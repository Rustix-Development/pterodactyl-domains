<?php

namespace RustixDev\Domains\Services;

use Pterodactyl\Models\Allocation;
use Pterodactyl\Models\Server;
use RustixDev\Domains\Support\DomainProblem;

/**
 * Works out the public address a domain points to: the allocation's alias or IP,
 * or the node's address when the allocation is bound to 0.0.0.0 or a private IP.
 */
class TargetAddress
{
    /**
     * The allocation to use: the one asked for if it belongs to the server, else the primary one.
     */
    public function allocation(Server $server, ?int $allocationId): Allocation
    {
        $server->loadMissing('allocations', 'allocation');
        if ($allocationId !== null) {
            $allocation = $server->allocations->firstWhere('id', $allocationId);
            if ($allocation === null) {
                throw new DomainProblem('That allocation does not belong to this server.');
            }

            return $allocation;
        }

        return $server->allocation ?? $server->allocations->first() ?? throw new DomainProblem('This server has no allocation to point a domain at.');
    }

    /** @return array{ip: string, type: 'A'|'AAAA'} */
    public function resolve(Server $server, Allocation $allocation): array
    {
        foreach ([$allocation->ip_alias, $allocation->ip, $server->node?->fqdn] as $candidate) {
            if (! is_string($candidate) || trim($candidate) === '') {
                continue;
            }

            foreach ($this->addresses(trim($candidate)) as $ip) {
                if (self::isPublic($ip)) {
                    return ['ip' => $ip, 'type' => str_contains($ip, ':') ? 'AAAA' : 'A'];
                }
            }
        }

        throw new DomainProblem('This server has no public IP address to point a domain at. Ask the host to set an IP alias on the allocation.');
    }

    public static function isPublic(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return false;
        }

        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_GLOBAL_RANGE) !== false;
    }

    /** @return list<string> */
    private function addresses(string $host): array
    {
        $host = trim($host, '[]');
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [$host];
        }

        if (! preg_match('/^[a-z0-9.-]+$/i', $host)) {
            return [];
        }

        $v4 = @gethostbynamel($host) ?: [];
        $v6 = array_column(@dns_get_record($host, DNS_AAAA) ?: [], 'ipv6');

        return [...$v4, ...$v6];
    }
}
