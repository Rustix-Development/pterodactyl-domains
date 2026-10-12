<?php

namespace RustixDev\Domains\Services;

use Carbon\CarbonImmutable;
use Closure;
use RustixDev\Domains\Acme\AcmeClient;
use RustixDev\Domains\Acme\AcmeException;
use RustixDev\Domains\Acme\Csr;
use RustixDev\Domains\Acme\Jws;
use RustixDev\Domains\Dns\DnsManager;
use RustixDev\Domains\Dns\Record;
use RustixDev\Domains\Models\AcmeAccount;
use RustixDev\Domains\Models\Domain;
use RustixDev\Domains\Support\DomainProblem;
use RustixDev\Domains\Support\DomainSettings;
use RustixDev\Domains\Support\Environment;
use Throwable;

/**
 * Issues a certificate for one domain with the ACME dns-01 challenge. The TXT
 * record goes on the provider: at _acme-challenge.<name> for subdomains, and at
 * <token>.<delegation zone> for own domains, which the user's _acme-challenge
 * CNAME points to. Challenge records are always removed again.
 */
class CertificateIssuer
{
    /** @var Closure(float): void */
    private Closure $sleep;

    public function __construct(
        private readonly DomainSettings $settings,
        private readonly DnsManager $dns,
        private readonly DomainRecords $records,
        private readonly CertificateFiles $files,
    ) {
        $this->sleep = fn (float $seconds) => usleep((int) ($seconds * 1_000_000));
    }

    /** Issue (or renew) and save the certificate, then write it into the server's files. */
    public function issue(Domain $domain): void
    {
        $server = $domain->server ?? throw new DomainProblem('The server no longer exists.');
        if (! $this->settings->sslAllowed()) {
            throw new DomainProblem('Certificates are turned off by the host.');
        }

        $challengeName = $this->challengeRecordName($domain);
        $client = $this->client();
        $jws = $client->jws();

        $order = $client->newOrder([$domain->name]);
        $created = [];

        try {
            $pending = [];
            foreach ($order['authorizations'] as $url) {
                $authz = $client->authorization($url);
                if ($authz['status'] === 'valid') {
                    continue;
                }
                if ($authz['token'] === null || $authz['challenge'] === null) {
                    throw new AcmeException('The CA did not offer a DNS challenge.');
                }

                $record = new Record('TXT', $challengeName, $jws->dnsChallengeValue($authz['token']), 60);
                $created[] = $this->dns->put($record);
                $pending[$url] = $authz['challenge'];
            }

            if ($pending !== []) {
                ($this->sleep)(Environment::propagationSeconds());
                foreach ($pending as $url => $challenge) {
                    $client->respond($challenge);
                    $client->waitForAuthorization($url);
                }
            }
        } finally {
            foreach ($created as $record) {
                try {
                    $this->dns->delete($record->name, (string) $record->id);
                } catch (Throwable) {
                    // A leftover TXT record is harmless; the next issue replaces its value anyway.
                }
            }
        }

        $key = Jws::generateEcKey();
        $chain = $client->finalize($order, Csr::create([$domain->name], $key));
        $info = Csr::inspect($chain, $key);
        if (! $info['matches']) {
            throw new AcmeException('The certificate the CA returned does not match its key.');
        }

        $domain->cert_chain = $chain;
        $domain->cert_key = $key;
        $domain->cert_issued_at = CarbonImmutable::createFromTimestamp($info['issued']);
        $domain->cert_expires_at = CarbonImmutable::createFromTimestamp($info['expires']);
        $domain->cert_written_at = null;
        $domain->ssl_status = 'active';
        $domain->ssl_error = null;
        $domain->ssl_attempts = 0;
        $domain->ssl_next_attempt_at = null;
        $domain->ssl_started_at = null;
        $domain->save();

        try {
            $this->files->write($domain, $server);
        } catch (Throwable $exception) {
            $domain->ssl_error = 'The certificate was issued but could not be written to the server yet; it is tried again every minute. ('.mb_substr($exception->getMessage(), 0, 200).')';
        }
        $domain->save();
    }

    /** Where the TXT record for this domain's challenge goes. */
    public function challengeRecordName(Domain $domain): string
    {
        if (! $domain->isOwn()) {
            return $domain->challengeName();
        }

        $target = $this->records->delegationTarget($domain);
        if ($target === null) {
            throw new DomainProblem('Certificates for own domains are not available: the host has not set a delegation zone.');
        }

        $this->records->check($domain);
        $domain->save();
        if (! $domain->delegation_ok) {
            throw new DomainProblem("Add the CNAME record {$domain->challengeName()} → {$target} first.");
        }

        return $target;
    }

    /** Use another wait between setting the challenge record and asking the CA, for tests. */
    public function sleepUsing(Closure $sleep): void
    {
        $this->sleep = $sleep;
    }

    private function client(): AcmeClient
    {
        $directory = $this->settings->acmeDirectory();
        $email = $this->settings->acmeEmail();

        $account = AcmeAccount::query()->firstOrNew(['directory_hash' => hash('sha256', $directory)]);
        if (! $account->exists) {
            $account->directory = $directory;
            $account->private_key = Jws::generateEcKey();
            $account->save();
        }

        $client = new AcmeClient($directory, new Jws($account->private_key), $account->email === $email ? $account->kid : null);
        $kid = $client->account($email);
        if ($kid !== $account->kid || $account->email !== $email) {
            $account->kid = $kid;
            $account->email = $email;
            $account->save();
        }

        return $client;
    }
}
