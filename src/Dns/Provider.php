<?php

namespace RustixDev\Domains\Dns;

/**
 * A DNS host the extension can manage records on. Cloudflare is the built-in driver;
 * another one implements this interface and is added to DnsManager::DRIVERS.
 * Names are always full host names, lower case, without a trailing dot.
 */
interface Provider
{
    /** Throws DnsException when the credentials are missing or rejected. */
    public function verify(): string;

    /** @return list<Zone> every zone the credentials can manage */
    public function listZones(): array;

    /** @return list<Record> records with this exact name, optionally of one type */
    public function findRecords(Zone $zone, string $name, ?string $type = null): array;

    /** Returns the record with its provider id. */
    public function createRecord(Zone $zone, Record $record): Record;

    public function updateRecord(Zone $zone, string $id, Record $record): Record;

    /** Deleting a record that no longer exists is not an error. */
    public function deleteRecord(Zone $zone, string $id): void;
}
