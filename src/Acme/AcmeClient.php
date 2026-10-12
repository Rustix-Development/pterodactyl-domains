<?php

namespace RustixDev\Domains\Acme;

use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RustixDev\Domains\Support\Environment;

/**
 * A small ACME (RFC 8555) client: account, order, dns-01 authorizations,
 * finalize and download. Only what issuing one certificate needs.
 */
class AcmeClient
{
    /** Longest time to wait for a CA to finish one step. */
    public const int POLL_SECONDS = 180;

    /** @var array<string, mixed>|null */
    private ?array $directory = null;

    private ?string $nonce = null;

    /** @var Closure(float): void */
    private Closure $sleep;

    public function __construct(
        private readonly string $directoryUrl,
        private readonly Jws $key,
        private ?string $kid = null,
        ?Closure $sleep = null,
    ) {
        if (! str_starts_with(strtolower($directoryUrl), 'https://') || filter_var($directoryUrl, FILTER_VALIDATE_URL) === false) {
            throw new AcmeException('The ACME directory must be an https:// URL.');
        }

        $this->sleep = $sleep ?? fn (float $seconds) => usleep((int) ($seconds * 1_000_000));
    }

    public function jws(): Jws
    {
        return $this->key;
    }

    /** Find or create the account for this key and return its URL (the "kid"). */
    public function account(?string $email): string
    {
        if ($this->kid !== null) {
            return $this->kid;
        }

        $payload = ['termsOfServiceAgreed' => true];
        if ($email !== null) {
            $payload['contact'] = ['mailto:'.$email];
        }

        $response = $this->post($this->endpoint('newAccount'), $payload, withJwk: true);
        $kid = $response->header('Location');
        if ($kid === '' || ! str_starts_with($kid, 'https://')) {
            throw new AcmeException('The CA did not return an account URL.');
        }

        return $this->kid = $kid;
    }

    /**
     * @param  list<string>  $names
     * @return array{url: string, authorizations: list<string>, finalize: string, status: string}
     */
    public function newOrder(array $names): array
    {
        $response = $this->post($this->endpoint('newOrder'), [
            'identifiers' => array_map(fn (string $name) => ['type' => 'dns', 'value' => $name], $names),
        ]);
        $body = $this->json($response);

        return [
            'url' => $this->url($response->header('Location')),
            'authorizations' => array_values(array_map(fn ($url) => $this->url($url), (array) ($body['authorizations'] ?? []))),
            'finalize' => $this->url($body['finalize'] ?? null),
            'status' => (string) ($body['status'] ?? ''),
        ];
    }

    /**
     * The authorization's name, status and its dns-01 challenge.
     *
     * @return array{name: string, status: string, token: string|null, challenge: string|null}
     */
    public function authorization(string $url): array
    {
        $body = $this->json($this->post($url, null));
        $challenge = null;
        foreach ((array) ($body['challenges'] ?? []) as $item) {
            if (is_array($item) && ($item['type'] ?? null) === 'dns-01') {
                $challenge = $item;
            }
        }

        $token = is_string($challenge['token'] ?? null) && preg_match('/^[A-Za-z0-9_-]+$/', $challenge['token']) ? $challenge['token'] : null;

        return [
            'name' => strtolower((string) ($body['identifier']['value'] ?? '')),
            'status' => (string) ($body['status'] ?? ''),
            'token' => $token,
            'challenge' => isset($challenge['url']) ? $this->url($challenge['url']) : null,
        ];
    }

    /** Tell the CA the challenge record is in place. */
    public function respond(string $challengeUrl): void
    {
        $this->post($challengeUrl, []);
    }

    /** Wait until the authorization is valid; throws with the CA's reason when it is not. */
    public function waitForAuthorization(string $url): void
    {
        $this->poll($url, function (array $body): bool {
            $status = $body['status'] ?? '';
            if ($status === 'valid') {
                return true;
            }
            if ($status !== 'pending') {
                $error = null;
                foreach ((array) ($body['challenges'] ?? []) as $challenge) {
                    $error ??= is_array($challenge) ? ($challenge['error']['detail'] ?? null) : null;
                }

                throw new AcmeException('The CA could not verify the domain'.(is_string($error) ? ': '.$error : '.'));
            }

            return false;
        });
    }

    /**
     * Send the CSR, wait for the certificate and download it as a PEM chain.
     *
     * @param  array{url: string, finalize: string}  $order
     */
    public function finalize(array $order, string $csrDer): string
    {
        $body = $this->json($this->post($order['finalize'], ['csr' => Jws::base64url($csrDer)]));

        if (($body['status'] ?? '') !== 'valid') {
            $body = $this->poll($order['url'], function (array $body): bool {
                return match ($body['status'] ?? '') {
                    'valid' => true,
                    'processing', 'ready', 'pending' => false,
                    default => throw new AcmeException('The CA refused the order'.(is_string($body['error']['detail'] ?? null) ? ': '.$body['error']['detail'] : '.')),
                };
            });
        }

        $certificate = $this->url($body['certificate'] ?? null);
        $chain = $this->post($certificate, null, accept: 'application/pem-certificate-chain')->body();
        if (! str_contains($chain, '-----BEGIN CERTIFICATE-----')) {
            throw new AcmeException('The CA returned no certificate.');
        }

        return trim($chain)."\n";
    }

    /**
     * POST-as-GET $url until $done says so, waiting longer each time.
     *
     * @param  Closure(array<string, mixed>): bool  $done
     * @return array<string, mixed>
     */
    private function poll(string $url, Closure $done): array
    {
        $waited = 0.0;
        $delay = 1.0;
        while (true) {
            $response = $this->post($url, null);
            $body = $this->json($response);
            if ($done($body)) {
                return $body;
            }

            if ($waited >= self::POLL_SECONDS) {
                throw new AcmeException('The CA took too long to answer. It will be tried again later.');
            }

            $retryAfter = $response->header('Retry-After');
            $wait = ctype_digit($retryAfter) ? min((float) $retryAfter, 30.0) : $delay;
            ($this->sleep)($wait);
            $waited += max($wait, 0.1);
            $delay = min($delay * 2, 10.0);
        }
    }

    /** @param array<string, mixed>|null $payload */
    private function post(string $url, ?array $payload, bool $withJwk = false, string $accept = 'application/json'): Response
    {
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $nonce = $this->nonce ?? $this->freshNonce();
            $this->nonce = null;

            if (! $withJwk && $this->kid === null) {
                throw new AcmeException('No ACME account yet.');
            }

            $body = $this->key->sign($url, $nonce, $payload, $withJwk ? null : $this->kid);
            $response = $this->send(fn (PendingRequest $http) => $http
                ->accept($accept)
                ->withBody((string) json_encode($body, JSON_UNESCAPED_SLASHES), 'application/jose+json')
                ->post($url));

            $this->nonce = $response->header('Replay-Nonce') ?: null;
            if ($response->successful()) {
                return $response;
            }

            $problem = $response->json();
            $type = is_array($problem) ? (string) ($problem['type'] ?? '') : '';
            if ($type === 'urn:ietf:params:acme:error:badNonce') {
                continue;
            }

            $detail = is_array($problem) && is_string($problem['detail'] ?? null) ? $problem['detail'] : 'HTTP '.$response->status();
            if ($type === 'urn:ietf:params:acme:error:rateLimited') {
                throw new AcmeException('The CA rate limit was reached: '.$detail);
            }

            throw new AcmeException('The CA refused the request: '.$detail);
        }

        throw new AcmeException('The CA kept rejecting the request nonce.');
    }

    private function freshNonce(): string
    {
        $response = $this->send(fn (PendingRequest $http) => $http->head($this->endpoint('newNonce')));
        $nonce = $response->header('Replay-Nonce');
        if ($nonce === '') {
            throw new AcmeException('The CA did not return a nonce.');
        }

        return $nonce;
    }

    private function endpoint(string $name): string
    {
        if ($this->directory === null) {
            $response = $this->send(fn (PendingRequest $http) => $http->get($this->directoryUrl));
            $directory = $response->json();
            if (! $response->successful() || ! is_array($directory)) {
                throw new AcmeException('The ACME directory could not be loaded.');
            }
            $this->directory = $directory;
        }

        return $this->url($this->directory[$name] ?? null);
    }

    /** Every URL the CA hands back must be https. */
    private function url(mixed $url): string
    {
        if (! is_string($url) || ! str_starts_with(strtolower($url), 'https://')) {
            throw new AcmeException('The CA returned an invalid URL.');
        }

        return $url;
    }

    /** @return array<string, mixed> */
    private function json(Response $response): array
    {
        $body = $response->json();

        return is_array($body) ? $body : throw new AcmeException('The CA returned an unexpected answer.');
    }

    /** @param Closure(PendingRequest): Response $request */
    private function send(Closure $request): Response
    {
        $http = Http::timeout(30)
            ->connectTimeout(10)
            ->withUserAgent('pterodactyl-domains-extension/1.0')
            ->withOptions(['allow_redirects' => false, 'verify' => Environment::acmeCaBundle() ?? true]);

        try {
            return $request($http);
        } catch (ConnectionException) {
            throw new AcmeException('Could not reach the CA. It will be tried again later.');
        }
    }
}
