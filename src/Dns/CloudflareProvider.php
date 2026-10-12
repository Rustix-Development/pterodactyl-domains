<?php

namespace RustixDev\Domains\Dns;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RustixDev\Domains\Support\Environment;

/**
 * Cloudflare API v4. The token needs Zone:Read and DNS:Edit on the zones it manages.
 * Records are never proxied: game traffic has to reach the server directly.
 */
class CloudflareProvider implements Provider
{
    public function __construct(private readonly ?string $token) {}

    public function verify(): string
    {
        $zones = $this->listZones();

        return $zones === []
            ? 'The token works, but it cannot see any zones. Give it Zone:Read and DNS:Edit on your zones.'
            : sprintf('The token works and can see %d zone%s: %s.', count($zones), count($zones) === 1 ? '' : 's', implode(', ', array_map(fn (Zone $zone) => $zone->name, array_slice($zones, 0, 10))));
    }

    public function listZones(): array
    {
        $zones = [];
        for ($page = 1; $page <= 20; $page++) {
            $body = $this->call('get', '/zones', ['per_page' => 50, 'page' => $page, 'status' => 'active']);
            foreach ($body['result'] ?? [] as $zone) {
                if (is_array($zone) && is_string($zone['id'] ?? null) && is_string($zone['name'] ?? null)) {
                    $zones[] = new Zone($zone['id'], strtolower($zone['name']));
                }
            }

            $pages = (int) ($body['result_info']['total_pages'] ?? 1);
            if ($page >= $pages) {
                break;
            }
        }

        return $zones;
    }

    public function findRecords(Zone $zone, string $name, ?string $type = null): array
    {
        $query = array_filter(['name' => $name, 'type' => $type, 'per_page' => 100]);
        $body = $this->call('get', "/zones/{$this->id($zone)}/dns_records", $query);

        return array_values(array_filter(array_map(fn ($row) => is_array($row) ? $this->toRecord($row) : null, $body['result'] ?? [])));
    }

    public function createRecord(Zone $zone, Record $record): Record
    {
        $body = $this->call('post', "/zones/{$this->id($zone)}/dns_records", $this->payload($record));

        return $this->toRecord($body['result'] ?? []) ?? throw new DnsException('Cloudflare did not return the new record.');
    }

    public function updateRecord(Zone $zone, string $id, Record $record): Record
    {
        $body = $this->call('put', "/zones/{$this->id($zone)}/dns_records/{$this->id($id)}", $this->payload($record));

        return $this->toRecord($body['result'] ?? []) ?? throw new DnsException('Cloudflare did not return the updated record.');
    }

    public function deleteRecord(Zone $zone, string $id): void
    {
        $this->call('delete', "/zones/{$this->id($zone)}/dns_records/{$this->id($id)}", [], allowMissing: true);
    }

    /** @return array<string, mixed> */
    private function payload(Record $record): array
    {
        $payload = ['type' => $record->type, 'name' => $record->name, 'ttl' => $record->ttl, 'comment' => 'Managed by the Domains & SSL panel extension'];

        if ($record->type === 'SRV') {
            return [...$payload, 'data' => [
                'priority' => $record->priority ?? 0,
                'weight' => $record->weight ?? 5,
                'port' => $record->port ?? 0,
                'target' => $record->content,
            ]];
        }

        $payload['content'] = $record->content;
        if (in_array($record->type, ['A', 'AAAA', 'CNAME'], true)) {
            $payload['proxied'] = false;
        }

        return $payload;
    }

    /** @param array<mixed> $row */
    private function toRecord(array $row): ?Record
    {
        if (! is_string($row['id'] ?? null) || ! is_string($row['type'] ?? null) || ! is_string($row['name'] ?? null)) {
            return null;
        }

        $ttl = (int) ($row['ttl'] ?? 300);
        if ($row['type'] === 'SRV' && is_array($row['data'] ?? null)) {
            return new Record('SRV', strtolower($row['name']), strtolower(rtrim((string) ($row['data']['target'] ?? ''), '.')), $ttl, (int) ($row['data']['priority'] ?? 0), (int) ($row['data']['weight'] ?? 0), (int) ($row['data']['port'] ?? 0), $row['id']);
        }

        return new Record($row['type'], strtolower($row['name']), trim((string) ($row['content'] ?? ''), '"'), $ttl, id: $row['id']);
    }

    /** Ids go into the URL, so only plain ids are accepted. */
    private function id(Zone|string $value): string
    {
        $id = $value instanceof Zone ? $value->id : $value;
        if (! preg_match('/^[A-Za-z0-9_-]{1,64}$/', $id)) {
            throw new DnsException('Invalid Cloudflare id.');
        }

        return $id;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function call(string $method, string $path, array $data = [], bool $allowMissing = false): array
    {
        if ($this->token === null) {
            throw new DnsException('No Cloudflare API token is set. Add one under Admin → Extensions → Domains & SSL.');
        }

        try {
            /** @var Response $response */
            $response = $this->client()->{$method}(Environment::cloudflareApi().$path, $data);
        } catch (ConnectionException) {
            throw new DnsException('Could not reach Cloudflare. Try again in a minute.');
        }

        if ($allowMissing && $response->status() === 404) {
            return [];
        }

        $body = $response->json();
        if (! is_array($body) || ! $response->successful() || ($body['success'] ?? false) !== true) {
            $error = is_array($body) ? ($body['errors'][0]['message'] ?? null) : null;
            if ($response->status() === 401 || $response->status() === 403) {
                throw new DnsException('Cloudflare refused the API token'.(is_string($error) ? ': '.rtrim($error, '.') : '').'. It needs Zone:Read and DNS:Edit.');
            }

            throw new DnsException('Cloudflare: '.(is_string($error) ? rtrim($error, '.') : 'request failed with status '.$response->status()).'.');
        }

        return $body;
    }

    private function client(): PendingRequest
    {
        return Http::withToken((string) $this->token)
            ->acceptJson()
            ->asJson()
            ->timeout(15)
            ->connectTimeout(5)
            ->withOptions(['allow_redirects' => false]);
    }
}
