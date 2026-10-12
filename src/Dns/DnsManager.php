<?php

namespace RustixDev\Domains\Dns;

use Illuminate\Support\Facades\Cache;
use RustixDev\Domains\Support\DomainName;
use RustixDev\Domains\Support\DomainSettings;

/**
 * Picks the DNS driver from the settings and finds the zone a name belongs to.
 * To add a provider, implement Provider and add it to DRIVERS and to the
 * dns_provider setting's options.
 */
class DnsManager
{
    public const array DRIVERS = [
        'cloudflare' => 'Cloudflare',
    ];

    private ?Provider $provider = null;

    public function __construct(private readonly DomainSettings $settings) {}

    public function provider(): Provider
    {
        return $this->provider ??= match ($this->settings->provider()) {
            'cloudflare' => new CloudflareProvider($this->settings->cloudflareToken()),
            default => throw new DnsException('The DNS provider set in the settings is not available.'),
        };
    }

    /** Use a different driver, for tests. */
    public function using(Provider $provider): self
    {
        $this->provider = $provider;

        return $this;
    }

    /** @return list<Zone> */
    public function zones(bool $fresh = false): array
    {
        $key = 'ext-domains:zones:'.$this->settings->provider().':'.hash('sha256', (string) $this->settings->cloudflareToken());
        if ($fresh) {
            Cache::forget($key);
        }

        $rows = Cache::remember($key, 600, fn () => array_map(
            fn (Zone $zone) => ['id' => $zone->id, 'name' => $zone->name],
            $this->provider()->listZones(),
        ));

        return array_map(fn (array $row) => new Zone($row['id'], $row['name']), $rows);
    }

    /** The provider zone that holds $name: the longest zone name it ends with. */
    public function zoneFor(string $name): Zone
    {
        $match = null;
        foreach ([false, true] as $fresh) {
            foreach ($this->zones($fresh) as $zone) {
                if (DomainName::within($name, $zone->name) && ($match === null || strlen($zone->name) > strlen($match->name))) {
                    $match = $zone;
                }
            }
            if ($match !== null) {
                return $match;
            }
        }

        throw new DnsException("The DNS provider has no zone for {$name}. Check the API token can see it.");
    }

    /**
     * Create the record, or update the one this domain made before. A saved id is only
     * updated while it still belongs to this name; otherwise a new record is made.
     */
    public function put(Record $record, ?string $id = null): Record
    {
        $zone = $this->zoneFor($record->name);
        if ($id !== null) {
            $existing = array_filter($this->provider()->findRecords($zone, $record->name), fn (Record $r) => $r->id === $id);
            if ($existing !== []) {
                return $this->provider()->updateRecord($zone, $id, $record);
            }
        }

        return $this->provider()->createRecord($zone, $record);
    }

    /** Delete the record, but only if it still has the name it was created with. */
    public function delete(string $name, string $id): void
    {
        $zone = $this->zoneFor($name);
        $match = array_filter($this->provider()->findRecords($zone, $name), fn (Record $record) => $record->id === $id);
        if ($match !== []) {
            $this->provider()->deleteRecord($zone, $id);
        }
    }

    /** @return list<Record> */
    public function find(string $name, ?string $type = null): array
    {
        return $this->provider()->findRecords($this->zoneFor($name), $name, $type);
    }
}
