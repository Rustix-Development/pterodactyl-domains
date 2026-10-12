<?php

namespace RustixDev\Domains\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Pterodactyl\Models\Allocation;
use Pterodactyl\Models\Server;

/**
 * A host name attached to a server: a subdomain on one of the admin's zones
 * (records managed for the user) or the user's own domain (records they create).
 *
 * @property int $id
 * @property int|null $server_id
 * @property string $server_uuid
 * @property string $kind
 * @property string $name
 * @property string|null $zone
 * @property int|null $allocation_id
 * @property string|null $target
 * @property int|null $port
 * @property string|null $srv_service
 * @property array<string, array{id: string, name: string}> $dns_records provider records by role (address, srv)
 * @property string $dns_status
 * @property string|null $dns_error
 * @property CarbonImmutable|null $dns_checked_at
 * @property bool $dns_sync_due
 * @property string|null $acme_token
 * @property bool $delegation_ok
 * @property string $ssl_status
 * @property string|null $ssl_error
 * @property int $ssl_attempts
 * @property CarbonImmutable|null $ssl_next_attempt_at
 * @property CarbonImmutable|null $ssl_started_at
 * @property string|null $cert_chain
 * @property string|null $cert_key
 * @property CarbonImmutable|null $cert_issued_at
 * @property CarbonImmutable|null $cert_expires_at
 * @property CarbonImmutable|null $cert_written_at
 * @property int|null $created_by
 * @property Server|null $server
 * @property Allocation|null $allocation
 */
class Domain extends Model
{
    public const string SUBDOMAIN = 'subdomain';

    public const string OWN = 'own';

    /** Certificate states the worker picks up. */
    public const array SSL_WAITING = ['queued'];

    protected $table = 'ext_domains_domains';

    protected $guarded = ['id'];

    protected $attributes = [
        'dns_records' => '{}',
        'dns_status' => 'pending',
        'dns_sync_due' => false,
        'delegation_ok' => false,
        'ssl_status' => 'none',
        'ssl_attempts' => 0,
    ];

    /** Never serialized, whatever calls toArray(). */
    protected $hidden = ['cert_key', 'cert_chain', 'acme_token'];

    protected function casts(): array
    {
        return [
            'dns_records' => 'array',
            'dns_checked_at' => 'immutable_datetime',
            'dns_sync_due' => 'boolean',
            'delegation_ok' => 'boolean',
            'ssl_next_attempt_at' => 'immutable_datetime',
            'ssl_started_at' => 'immutable_datetime',
            'cert_key' => 'encrypted',
            'cert_issued_at' => 'immutable_datetime',
            'cert_expires_at' => 'immutable_datetime',
            'cert_written_at' => 'immutable_datetime',
        ];
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    public function allocation(): BelongsTo
    {
        return $this->belongsTo(Allocation::class);
    }

    public function isOwn(): bool
    {
        return $this->kind === self::OWN;
    }

    /** The name the CA checks for the dns-01 challenge. */
    public function challengeName(): string
    {
        return '_acme-challenge.'.$this->name;
    }

    public function failSsl(string $message, CarbonImmutable $now): void
    {
        $this->ssl_status = 'failed';
        $this->ssl_error = mb_substr($message, 0, 1000);
        $this->ssl_attempts = min($this->ssl_attempts + 1, 1000);
        // 30 minutes, then 1, 2, 4... hours, at most a day apart.
        $this->ssl_next_attempt_at = $now->addMinutes(min(30 * (2 ** min($this->ssl_attempts - 1, 6)), 1440));
        $this->ssl_started_at = null;

        // A renewal that fails keeps the current certificate in place and retries with the same backoff.
        if ($this->cert_chain !== null && $this->cert_expires_at?->isAfter($now)) {
            $this->ssl_status = 'active';
            $this->ssl_error = mb_substr('Renewal failed: '.$message, 0, 1000);
        }
    }

    /** @return array<string, mixed> */
    public function toPayload(): array
    {
        return [
            'id' => $this->id,
            'kind' => $this->kind,
            'name' => $this->name,
            'zone' => $this->zone,
            'allocation_id' => $this->allocation_id,
            'target' => $this->target,
            'port' => $this->port,
            'srv_service' => $this->srv_service,
            'dns_status' => $this->dns_status,
            'dns_error' => $this->dns_error,
            'dns_checked_at' => $this->dns_checked_at?->toIso8601String(),
            'delegation_ok' => $this->delegation_ok,
            'ssl_status' => $this->ssl_status,
            'ssl_error' => $this->ssl_error,
            'ssl_next_attempt_at' => $this->ssl_status === 'failed' ? $this->ssl_next_attempt_at?->toIso8601String() : null,
            'cert_issued_at' => $this->cert_issued_at?->toIso8601String(),
            'cert_expires_at' => $this->cert_expires_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
