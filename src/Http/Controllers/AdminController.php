<?php

namespace RustixDev\Domains\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use RustixDev\Domains\Dns\DnsException;
use RustixDev\Domains\Dns\DnsManager;
use RustixDev\Domains\Dns\Zone;
use RustixDev\Domains\Models\Domain;
use RustixDev\Domains\Support\DomainName;
use RustixDev\Domains\Support\DomainSettings;

/** Every domain on the panel, and a check of the DNS provider settings. */
class AdminController
{
    public function __construct(private readonly DomainSettings $settings, private readonly DnsManager $dns) {}

    public function index(): JsonResponse
    {
        $domains = Domain::query()
            ->with('server:id,uuid,uuidShort,name')
            ->orderBy('name')
            ->limit(1000)
            ->get()
            ->map(fn (Domain $domain) => [
                ...$domain->toPayload(),
                'server' => $domain->server?->only(['id', 'name', 'uuidShort']),
            ]);

        return new JsonResponse([
            'domains' => $domains,
            'provider' => DnsManager::DRIVERS[$this->settings->provider()] ?? $this->settings->provider(),
            'zones' => $this->settings->zones(),
            'delegation_zone' => $this->settings->delegationZone(),
            'acme_directory' => $this->settings->acmeDirectory(),
        ]);
    }

    /** Try the API token and check every configured zone is reachable with it. */
    public function test(Request $request): JsonResponse
    {
        $key = 'ext-domains:test:'.$request->user()->id;
        abort_if(RateLimiter::tooManyAttempts($key, 10), 429, 'Too many tests. Try again in a minute.');
        RateLimiter::hit($key, 60);

        try {
            $message = $this->dns->provider()->verify();
            $zones = $this->dns->zones(fresh: true);
        } catch (DnsException $exception) {
            return new JsonResponse(['ok' => false, 'message' => $exception->getMessage(), 'checks' => []]);
        }

        $checks = [];
        foreach ($this->settings->zones() as $name) {
            $checks[] = ['name' => $name, 'role' => 'zone', 'ok' => $this->covered($name, $zones)];
        }
        if (($delegation = $this->settings->delegationZone()) !== null) {
            $checks[] = ['name' => $delegation, 'role' => 'delegation', 'ok' => $this->covered($delegation, $zones)];
        }

        return new JsonResponse([
            'ok' => ! in_array(false, array_column($checks, 'ok'), true),
            'message' => $message,
            'checks' => $checks,
        ]);
    }

    /** @param list<Zone> $zones */
    private function covered(string $name, array $zones): bool
    {
        foreach ($zones as $zone) {
            if (DomainName::within($name, $zone->name)) {
                return true;
            }
        }

        return false;
    }
}
