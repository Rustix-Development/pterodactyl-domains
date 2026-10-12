<?php

namespace RustixDev\Domains\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rule;
use Pterodactyl\Models\Allocation;
use Pterodactyl\Models\Server;
use RustixDev\Domains\Dns\DnsException;
use RustixDev\Domains\Dns\Record;
use RustixDev\Domains\Models\Domain;
use RustixDev\Domains\Services\CertificateFiles;
use RustixDev\Domains\Services\DomainRecords;
use RustixDev\Domains\Support\DomainName;
use RustixDev\Domains\Support\DomainProblem;
use RustixDev\Domains\Support\DomainSettings;
use RustixDev\Domains\Support\SrvServices;
use Throwable;

class DomainController
{
    /** [attempts, seconds] per server for each limited action. */
    private const array LIMITS = [
        'create' => [10, 600],
        'certificate' => [5, 3600],
        'check' => [12, 600],
    ];

    public function __construct(
        private readonly DomainSettings $settings,
        private readonly DomainRecords $records,
        private readonly CertificateFiles $files,
    ) {}

    public function index(Request $request, Server $server): JsonResponse
    {
        $this->authorize($request, $server, 'read');
        $server->loadMissing('allocations', 'egg');

        return new JsonResponse([
            'domains' => Domain::query()->where('server_id', $server->id)->orderBy('id')->get()->map(fn (Domain $domain) => $this->present($domain)),
            'zones' => $this->settings->zones(),
            'allocations' => $server->allocations
                ->sortBy(fn (Allocation $a) => [$a->id !== $server->allocation_id, $a->port])
                ->values()
                ->map(fn (Allocation $a) => [
                    'id' => $a->id,
                    'address' => ($a->ip_alias ?: $a->ip).':'.$a->port,
                    'is_default' => $a->id === $server->allocation_id,
                ]),
            'max_domains' => $this->settings->maxDomains(),
            'can_manage' => $request->user()->can('ext.domains.manage', $server),
            'ssl_available' => $this->settings->sslAllowed(),
            'own_allowed' => $this->settings->ownDomainsAllowed(),
            'own_ssl_available' => $this->settings->sslAllowed() && $this->settings->delegationZone() !== null,
            'srv_service' => $this->settings->srvEnabled() ? SrvServices::forServer($server, $this->settings->srvServices()) : null,
            'cert_path' => $this->settings->certPath(),
        ]);
    }

    public function store(Request $request, Server $server): JsonResponse
    {
        $this->authorize($request, $server, 'manage');
        abort_if(Domain::query()->where('server_id', $server->id)->count() >= $this->settings->maxDomains(), 422, 'This server already has the most domains allowed.');

        $data = $request->validate([
            'kind' => ['required', Rule::in([Domain::SUBDOMAIN, Domain::OWN])],
            'label' => ['required_if:kind,subdomain', 'nullable', 'string', 'max:63'],
            'zone' => ['required_if:kind,subdomain', 'nullable', 'string', 'max:253'],
            'domain' => ['required_if:kind,own', 'nullable', 'string', 'max:253'],
            'allocation_id' => ['nullable', 'integer'],
            'certificate' => ['boolean'],
        ]);

        $name = $data['kind'] === Domain::SUBDOMAIN
            ? $this->subdomainName((string) $data['label'], (string) $data['zone'])
            : $this->ownName((string) $data['domain']);
        abort_if(Domain::query()->where('name', $name)->exists(), 422, "{$name} is already in use.");

        $domain = new Domain([
            'server_id' => $server->id,
            'server_uuid' => $server->uuid,
            'kind' => $data['kind'],
            'name' => $name,
            'zone' => $data['kind'] === Domain::SUBDOMAIN ? $data['zone'] : null,
            'dns_status' => 'pending',
            'created_by' => $request->user()->id,
        ]);
        $this->retarget($domain, $server, $data['allocation_id'] ?? null);
        // Counted only once the request is valid, so typos do not use up the limit.
        $this->limit($server, 'create');

        if ($domain->isOwn()) {
            $domain->acme_token = 'v'.bin2hex(random_bytes(12));
        } else {
            try {
                $this->records->assertFree($name);
                $this->records->sync($domain);
            } catch (DomainProblem $problem) {
                abort(422, $problem->getMessage());
            } catch (DnsException $exception) {
                $this->undoRecords($domain);
                abort(503, $exception->getMessage());
            }
        }

        if (($data['certificate'] ?? false) && ! $domain->isOwn() && $this->settings->sslAllowed()) {
            $domain->ssl_status = 'queued';
        }
        $domain->save();

        return new JsonResponse(['data' => $this->present($domain)], 201);
    }

    /** Point the domain at another of the server's allocations. */
    public function update(Request $request, Server $server, int $domain): JsonResponse
    {
        $this->authorize($request, $server, 'manage');
        $model = $this->find($server, $domain);
        $data = $request->validate(['allocation_id' => ['required', 'integer']]);

        if ($this->retarget($model, $server, (int) $data['allocation_id'])) {
            if ($model->isOwn()) {
                $model->dns_status = 'pending';
                $model->dns_error = null;
            } else {
                try {
                    $this->records->sync($model);
                } catch (DnsException $exception) {
                    $model->save();
                    abort(503, $exception->getMessage());
                }
            }
            $model->save();
        }

        return new JsonResponse(['data' => $this->present($model)]);
    }

    public function destroy(Request $request, Server $server, int $domain): Response
    {
        $this->authorize($request, $server, 'manage');
        $model = $this->find($server, $domain);
        abort_if($model->ssl_status === 'issuing', 409, 'A certificate is being issued for this domain right now. Try again in a minute.');

        try {
            $this->records->remove($model);
        } catch (DnsException $exception) {
            $model->save();
            abort(503, 'The DNS records could not be removed, so the domain was kept: '.$exception->getMessage());
        }

        if ($model->cert_written_at !== null) {
            try {
                $this->files->delete($model, $server);
            } catch (Throwable) {
                // The node may be offline; the files are harmless without the domain.
            }
        }
        $model->delete();

        return response()->noContent();
    }

    /** Own domains: look the records up in public DNS. Subdomains: write the records again. */
    public function check(Request $request, Server $server, int $domain): JsonResponse
    {
        $this->authorize($request, $server, 'manage');
        $model = $this->find($server, $domain);
        $this->limit($server, 'check');

        try {
            if ($model->isOwn()) {
                $this->records->check($model);
            } else {
                $this->retarget($model, $server, $model->allocation_id);
                $this->records->sync($model);
            }
        } catch (DnsException $exception) {
            $model->save();
            abort(503, $exception->getMessage());
        }
        $model->save();

        return new JsonResponse(['data' => $this->present($model)]);
    }

    /** Ask for a certificate; the scheduler issues it within a minute. */
    public function certificate(Request $request, Server $server, int $domain): JsonResponse
    {
        $this->authorize($request, $server, 'manage');
        $model = $this->find($server, $domain);
        abort_unless($this->settings->sslAllowed(), 422, 'Certificates are turned off by the host.');
        abort_if(in_array($model->ssl_status, ['queued', 'issuing'], true), 409, 'A certificate is already on its way.');
        // The CA limits duplicate certificates, so a healthy one is left to renew on schedule.
        abort_unless($this->renewable($model), 409, 'The certificate is valid until '.$model->cert_expires_at?->toDateString().' and renews automatically.');

        if ($model->isOwn()) {
            abort_if($this->settings->delegationZone() === null, 422, 'Certificates for own domains are not available on this panel.');
            if (! $model->delegation_ok) {
                $this->limit($server, 'check');
                $this->records->check($model);
                $model->save();
                abort_unless($model->delegation_ok, 422, "Add the CNAME record {$model->challengeName()} first, then try again.");
            }
        }

        $this->limit($server, 'certificate');
        $model->ssl_status = 'queued';
        $model->ssl_error = null;
        $model->ssl_attempts = 0;
        $model->ssl_next_attempt_at = null;
        $model->save();

        return new JsonResponse(['data' => $this->present($model)], 202);
    }

    /** @return array<string, mixed> */
    private function present(Domain $domain): array
    {
        return [
            ...$domain->toPayload(),
            'records' => array_values(array_map(
                fn (string $role, Record $record) => ['role' => $role, ...$record->toPayload()],
                array_keys($expected = $this->records->expected($domain)),
                $expected,
            )),
            'cert_folder' => $domain->cert_chain !== null ? $this->files->folder($domain) : null,
            'renewable' => $this->renewable($domain),
        ];
    }

    /** Whether asking for a certificate now makes sense: none yet, a failed one, or one due for renewal. */
    private function renewable(Domain $domain): bool
    {
        if ($domain->ssl_status !== 'active' || $domain->ssl_error !== null || $domain->cert_expires_at === null) {
            return ! in_array($domain->ssl_status, ['queued', 'issuing'], true);
        }

        return $domain->cert_expires_at->subDays($this->settings->renewDays())->isPast();
    }

    private function subdomainName(string $label, string $zone): string
    {
        $label = strtolower(trim($label));
        abort_unless(DomainName::isLabel($label), 422, 'Use 1 to 63 lower case letters, digits and hyphens, not starting or ending with a hyphen.');
        abort_if(DomainName::isReserved($label, $this->settings->reservedLabels()), 422, "\"{$label}\" is reserved. Pick another name.");
        abort_unless(in_array($zone, $this->settings->zones(), true), 422, 'That domain is not offered.');

        $name = $label.'.'.$zone;
        abort_if(strlen($name) > 253, 422, 'That name is too long.');

        return $name;
    }

    private function ownName(string $input): string
    {
        abort_unless($this->settings->ownDomainsAllowed(), 422, 'Own domains are turned off by the host.');
        $name = DomainName::normalize($input);
        abort_if($name === null, 422, 'Enter a domain like play.example.com, without http:// or a port.');
        abort_if(str_starts_with($name, '_'), 422, 'Enter a domain like play.example.com.');

        $blocked = array_filter([...$this->settings->zones(), $this->settings->delegationZone(), parse_url((string) config('app.url'), PHP_URL_HOST)]);
        foreach ($blocked as $zone) {
            abort_if(DomainName::within($name, strtolower((string) $zone)), 422, 'That domain belongs to this panel. Use the subdomain option instead.');
        }

        return $name;
    }

    private function retarget(Domain $domain, Server $server, ?int $allocationId): bool
    {
        try {
            return $this->records->retarget($domain, $server, $allocationId);
        } catch (DomainProblem $problem) {
            abort(422, $problem->getMessage());
        }
    }

    /** A failed create leaves nothing behind on the provider. */
    private function undoRecords(Domain $domain): void
    {
        try {
            $this->records->remove($domain);
        } catch (Throwable) {
            // Nothing more can be done here; the provider rejected the name anyway.
        }
    }

    private function limit(Server $server, string $action): void
    {
        [$attempts, $seconds] = self::LIMITS[$action];
        $key = "ext-domains:{$action}:{$server->id}";
        if (RateLimiter::tooManyAttempts($key, $attempts)) {
            abort(429, 'Too many requests. Try again in '.max(1, (int) ceil(RateLimiter::availableIn($key) / 60)).' minutes.');
        }
        RateLimiter::hit($key, $seconds);
    }

    private function find(Server $server, int $id): Domain
    {
        return Domain::query()->where('server_id', $server->id)->findOrFail($id);
    }

    private function authorize(Request $request, Server $server, string $permission): void
    {
        $user = $request->user();
        $allowed = $user->can('ext.domains.manage', $server) || ($permission === 'read' && $user->can('ext.domains.read', $server));
        abort_unless($allowed, 403);
    }
}
