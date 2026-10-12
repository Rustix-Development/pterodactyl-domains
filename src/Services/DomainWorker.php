<?php

namespace RustixDev\Domains\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use RustixDev\Domains\Models\Domain;
use RustixDev\Domains\Support\DomainSettings;
use Throwable;

/**
 * The background side, run from the scheduler so web requests never wait on a CA:
 *
 * - work(), every minute: certificates users asked for, DNS records that need
 *   updating, certificates not yet written to the server, deleted servers' records.
 * - renew(), every hour: certificates inside the renewal window, failed ones whose
 *   retry time has come, and domains whose server address changed.
 */
class DomainWorker
{
    /** A certificate still "issuing" after this long was interrupted. */
    public const int STUCK_MINUTES = 15;

    /** Failed certificates stop retrying on their own after this many attempts. */
    public const int MAX_ATTEMPTS = 10;

    /** @var list<string> */
    private array $output = [];

    public function __construct(
        private readonly DomainSettings $settings,
        private readonly DomainRecords $records,
        private readonly CertificateIssuer $issuer,
        private readonly CertificateFiles $files,
    ) {}

    /** @return list<string> one line per thing done */
    public function work(?CarbonImmutable $now = null, int $limit = 5): array
    {
        $now ??= CarbonImmutable::now();
        $this->output = [];

        Domain::query()
            ->where('ssl_status', 'issuing')
            ->where('ssl_started_at', '<', $now->subMinutes(self::STUCK_MINUTES))
            ->get()
            ->each(function (Domain $domain) use ($now) {
                $domain->failSsl('Issuing was interrupted. It is tried again automatically.', $now);
                $domain->save();
            });

        $this->removeOrphans();

        Domain::query()->where('dns_sync_due', true)->whereNotNull('server_id')->with('server')->limit(50)->get()
            ->each(fn (Domain $domain) => $this->resync($domain));

        Domain::query()->where('ssl_status', 'queued')->orderBy('updated_at')->limit($limit)->pluck('id')
            ->each(fn (int $id) => $this->issue($id, ['queued'], $now));

        Domain::query()
            ->where('ssl_status', 'active')
            ->whereNull('cert_written_at')
            ->whereNotNull('server_id')
            ->with('server')
            ->limit(20)
            ->get()
            ->each(function (Domain $domain) {
                try {
                    $this->files->write($domain, $domain->server);
                    $domain->ssl_error = null;
                    $domain->save();
                    $this->output[] = "Wrote the certificate files for {$domain->name}.";
                } catch (Throwable) {
                    // The server's node is probably offline; next minute.
                }
            });

        return $this->output;
    }

    /** @return list<string> */
    public function renew(?CarbonImmutable $now = null, bool $force = false, ?string $only = null): array
    {
        $now ??= CarbonImmutable::now();
        $this->output = [];

        $due = Domain::query()
            ->whereNotNull('server_id')
            ->when($only !== null, fn ($query) => $query->where('name', $only))
            ->where(function ($query) use ($now, $force) {
                $query->where(function ($query) use ($now, $force) {
                    $query->where('ssl_status', 'active');
                    if (! $force) {
                        $query->where('cert_expires_at', '<=', $now->addDays($this->settings->renewDays()))
                            ->where(fn ($query) => $query->whereNull('ssl_next_attempt_at')->orWhere('ssl_next_attempt_at', '<=', $now));
                    }
                })->orWhere(function ($query) use ($now) {
                    $query->where('ssl_status', 'failed')
                        ->where('ssl_attempts', '<', self::MAX_ATTEMPTS)
                        ->where('ssl_next_attempt_at', '<=', $now);
                });
            })
            ->orderBy('cert_expires_at')
            ->limit(25)
            ->pluck('id');

        $due->each(fn (int $id) => $this->issue($id, ['active', 'failed'], $now));

        // Keep domains pointing at the right address after transfers or IP alias changes.
        Domain::query()->whereNotNull('server_id')->with('server.allocations', 'server.allocation', 'server.node', 'server.egg')
            ->chunkById(100, function ($domains) {
                foreach ($domains as $domain) {
                    try {
                        if ($this->records->retarget($domain, $domain->server)) {
                            $this->resync($domain, changed: true);
                        }
                    } catch (Throwable) {
                        // No public address any more; leave the record as it is.
                    }
                }
            });

        return $this->output;
    }

    /** Claim the domain so two runs never issue the same certificate, then issue it. */
    private function issue(int $id, array $from, CarbonImmutable $now): void
    {
        $claimed = Domain::query()->whereKey($id)->whereIn('ssl_status', $from)
            ->update(['ssl_status' => 'issuing', 'ssl_started_at' => $now, 'updated_at' => $now]);
        if ($claimed !== 1) {
            return;
        }

        $domain = Domain::query()->with('server')->findOrFail($id);

        try {
            $this->issuer->issue($domain);
            $this->output[] = "Issued a certificate for {$domain->name}, valid until {$domain->cert_expires_at?->toDateString()}.";
        } catch (Throwable $exception) {
            if (! $exception instanceof \RuntimeException) {
                Log::warning('Domains & SSL: issuing a certificate failed.', ['domain' => $domain->name, 'exception' => $exception]);
            }

            $domain->refresh();
            $domain->failSsl($exception->getMessage() ?: $exception::class, $now);
            $domain->save();
            $this->output[] = "Could not issue a certificate for {$domain->name}: {$exception->getMessage()}";
        }
    }

    private function resync(Domain $domain, bool $changed = false): void
    {
        try {
            $domain->dns_sync_due = false;
            $changed = $this->records->retarget($domain, $domain->server) || $changed;
            if ($domain->isOwn()) {
                if ($changed) {
                    // The user has to update their own records; the screen shows the new ones.
                    $domain->dns_status = 'pending';
                    $domain->dns_error = null;
                }
            } else {
                $this->records->sync($domain);
                $this->output[] = "Updated the DNS records of {$domain->name}.";
            }
        } catch (Throwable $exception) {
            $this->output[] = "Could not update the DNS records of {$domain->name}: {$exception->getMessage()}";
        }
        $domain->save();
    }

    /** Domains whose server was deleted: remove their records, then the row. */
    private function removeOrphans(): void
    {
        Domain::query()->whereNull('server_id')->limit(50)->get()->each(function (Domain $domain) {
            try {
                $this->records->remove($domain);
                $domain->delete();
                $this->output[] = "Removed {$domain->name}, its server was deleted.";
            } catch (Throwable $exception) {
                $domain->dns_status = 'error';
                $domain->dns_error = $exception->getMessage();
                $domain->save();
            }
        });
    }
}
