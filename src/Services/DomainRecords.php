<?php

namespace RustixDev\Domains\Services;

use Carbon\CarbonImmutable;
use Pterodactyl\Models\Server;
use RustixDev\Domains\Dns\DnsException;
use RustixDev\Domains\Dns\DnsManager;
use RustixDev\Domains\Dns\Lookup;
use RustixDev\Domains\Dns\Record;
use RustixDev\Domains\Models\Domain;
use RustixDev\Domains\Support\DomainProblem;
use RustixDev\Domains\Support\DomainSettings;
use RustixDev\Domains\Support\SrvServices;

/**
 * The DNS side of a domain: what records it needs, creating them on the provider
 * for subdomains, and checking them in public DNS for own domains.
 */
class DomainRecords
{
    public function __construct(
        private readonly DomainSettings $settings,
        private readonly DnsManager $dns,
        private readonly TargetAddress $address,
        private readonly Lookup $lookup,
    ) {}

    /**
     * Point the domain at its allocation: target IP, port and SRV service. Returns
     * whether anything changed.
     */
    public function retarget(Domain $domain, Server $server, ?int $allocationId = null): bool
    {
        $allocation = $this->address->allocation($server, $allocationId ?? $domain->allocation_id);
        $target = $this->address->resolve($server, $allocation);
        $service = $this->settings->srvEnabled() ? SrvServices::forServer($server, $this->settings->srvServices()) : null;

        $before = [$domain->allocation_id, $domain->target, $domain->port, $domain->srv_service];
        $domain->allocation_id = $allocation->id;
        $domain->target = $target['ip'];
        $domain->port = $allocation->port;
        $domain->srv_service = $service;

        return $before !== [$domain->allocation_id, $domain->target, $domain->port, $domain->srv_service];
    }

    /**
     * Every record the domain needs, keyed by role: "address", "srv", and for own
     * domains with certificates "delegation".
     *
     * @return array<string, Record>
     */
    public function expected(Domain $domain): array
    {
        $records = [];
        if ($domain->target !== null) {
            $records['address'] = new Record(str_contains($domain->target, ':') ? 'AAAA' : 'A', $domain->name, $domain->target);
        }
        if ($domain->srv_service !== null && $domain->port !== null) {
            $records['srv'] = Record::srv($domain->srv_service.'.'.$domain->name, $domain->name, $domain->port);
        }
        if ($domain->isOwn() && ($delegation = $this->delegationTarget($domain)) !== null) {
            $records['delegation'] = new Record('CNAME', $domain->challengeName(), $delegation);
        }

        return $records;
    }

    /** Where an own domain's _acme-challenge CNAME points, or null when certificates are not possible. */
    public function delegationTarget(Domain $domain): ?string
    {
        $zone = $this->settings->delegationZone();

        return $zone !== null && $domain->acme_token !== null ? $domain->acme_token.'.'.$zone : null;
    }

    /** Make the provider's records match a subdomain. */
    public function sync(Domain $domain): void
    {
        if ($domain->isOwn()) {
            return;
        }

        $saved = $domain->dns_records;
        $expected = $this->expected($domain);

        try {
            foreach (['address', 'srv'] as $role) {
                if (isset($saved[$role]) && $saved[$role]['name'] !== ($expected[$role]->name ?? null)) {
                    // The SRV service changed or is no longer wanted: the old record goes.
                    $this->dns->delete($saved[$role]['name'], $saved[$role]['id']);
                    unset($saved[$role]);
                }
                if (isset($expected[$role])) {
                    $record = $this->dns->put($expected[$role], $saved[$role]['id'] ?? null);
                    $saved[$role] = ['id' => (string) $record->id, 'name' => $record->name];
                }
            }

            $domain->dns_status = 'active';
            $domain->dns_error = null;
        } catch (DnsException $exception) {
            $domain->dns_status = 'error';
            $domain->dns_error = $exception->getMessage();

            throw $exception;
        } finally {
            $domain->dns_records = $saved;
            $domain->dns_checked_at = CarbonImmutable::now();
            $domain->dns_sync_due = $domain->dns_status === 'error';
        }
    }

    /** Remove the provider records of a subdomain. */
    public function remove(Domain $domain): void
    {
        if ($domain->isOwn()) {
            return;
        }

        $saved = $domain->dns_records;
        foreach ($saved as $role => $record) {
            $this->dns->delete($record['name'], $record['id']);
            unset($saved[$role]);
            $domain->dns_records = $saved;
        }
    }

    /** Whether the name is free on the provider (no record we did not make). */
    public function assertFree(string $name): void
    {
        $taken = array_filter($this->dns->find($name), fn (Record $record) => in_array($record->type, ['A', 'AAAA', 'CNAME'], true));
        if ($taken !== []) {
            throw new DomainProblem("{$name} already has a DNS record. Pick another name.");
        }
    }

    /**
     * Look up an own domain's records in public DNS and record the result.
     *
     * @return list<string> what is wrong, empty when the address record is right
     */
    public function check(Domain $domain): array
    {
        $problems = [];
        $addressOk = true;
        $expected = $this->expected($domain);

        if (isset($expected['address'])) {
            $record = $expected['address'];
            $found = $this->lookup->addresses($domain->name, $record->type);
            if (! in_array($this->canonicalIp($record->content), array_map($this->canonicalIp(...), $found), true)) {
                $addressOk = false;
                $problems[] = $found === []
                    ? "{$domain->name} has no {$record->type} record yet."
                    : "{$domain->name} points to ".implode(', ', $found)." instead of {$record->content}.";
            }
        }

        if (isset($expected['srv'])) {
            $record = $expected['srv'];
            $match = array_filter($this->lookup->srv($record->name), fn (array $srv) => $srv['port'] === $record->port && rtrim($srv['target'], '.') === $domain->name);
            if ($match === []) {
                $problems[] = "The SRV record {$record->name} is missing or different (optional: players then need the port).";
            }
        }

        $domain->delegation_ok = false;
        if (isset($expected['delegation'])) {
            $cname = $this->lookup->cname($domain->challengeName());
            $domain->delegation_ok = $cname !== null && rtrim($cname, '.') === $expected['delegation']->content;
            if (! $domain->delegation_ok) {
                $problems[] = $cname === null
                    ? "The CNAME record {$domain->challengeName()} is missing, so certificates cannot be issued yet."
                    : "{$domain->challengeName()} points to {$cname} instead of {$expected['delegation']->content}.";
            }
        }

        $domain->dns_status = $addressOk ? 'verified' : 'mismatch';
        $domain->dns_error = $problems === [] ? null : mb_substr(implode(' ', $problems), 0, 1000);
        $domain->dns_checked_at = CarbonImmutable::now();

        return $problems;
    }

    private function canonicalIp(string $ip): string
    {
        $packed = @inet_pton($ip);

        return $packed === false ? $ip : (string) inet_ntop($packed);
    }
}
