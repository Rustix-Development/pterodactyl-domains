<?php

namespace RustixDev\Domains\Support;

use Pterodactyl\Services\Extensions\ExtensionSettings;

/**
 * Typed reads of the admin settings, with the defaults in one place.
 */
class DomainSettings
{
    public const string LETS_ENCRYPT = 'https://acme-v02.api.letsencrypt.org/directory';

    public const string LETS_ENCRYPT_STAGING = 'https://acme-staging-v02.api.letsencrypt.org/directory';

    /** Labels that are never handed out, on top of the admin's own list. */
    public const array RESERVED = [
        'www', 'mail', 'panel', 'node*', 'ns*', 'mx*', 'smtp', 'imap', 'pop', 'pop3', 'ftp', 'sftp', 'webmail',
        'autodiscover', 'autoconfig', 'admin', 'api', 'cpanel', 'whm', 'localhost', 'wings', 'daemon', 'status',
    ];

    /** Egg matchers and the SRV service they use; "name:" matches part of the egg name. */
    public const array SRV_DEFAULTS = [
        'name:minecraft=_minecraft._tcp',
        'name:paper=_minecraft._tcp',
        'name:spigot=_minecraft._tcp',
        'name:purpur=_minecraft._tcp',
        'name:forge=_minecraft._tcp',
        'name:fabric=_minecraft._tcp',
        'name:sponge=_minecraft._tcp',
        'name:bungeecord=_minecraft._tcp',
        'name:velocity=_minecraft._tcp',
        'name:waterfall=_minecraft._tcp',
    ];

    public function __construct(private readonly ExtensionSettings $settings) {}

    public function provider(): string
    {
        return $this->string('dns_provider') ?: 'cloudflare';
    }

    public function cloudflareToken(): ?string
    {
        return $this->string('cloudflare_token') ?: null;
    }

    /** @return list<string> base domains users can pick a subdomain under */
    public function zones(): array
    {
        return array_values(array_unique(array_filter(array_map(
            fn (string $zone) => DomainName::normalize($zone),
            $this->list('zones'),
        ))));
    }

    public function delegationZone(): ?string
    {
        $zone = $this->string('acme_delegation_zone');

        return $zone === '' ? null : DomainName::normalize($zone);
    }

    public function acmeDirectory(): string
    {
        return $this->string('acme_directory') ?: self::LETS_ENCRYPT;
    }

    public function acmeEmail(): ?string
    {
        $email = $this->string('acme_email');

        return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null;
    }

    public function maxDomains(): int
    {
        return $this->int('max_domains', 3, 1, 100);
    }

    public function sslAllowed(): bool
    {
        return (bool) $this->settings->get('allow_ssl', true);
    }

    public function ownDomainsAllowed(): bool
    {
        return (bool) $this->settings->get('allow_own_domains', true);
    }

    public function srvEnabled(): bool
    {
        return (bool) $this->settings->get('srv_records', true);
    }

    /** @return list<string> */
    public function srvServices(): array
    {
        $value = $this->settings->get('srv_services');

        return is_array($value) ? $this->list('srv_services') : self::SRV_DEFAULTS;
    }

    /** The folder inside the server the certificates are written to, always absolute and without "..". */
    public function certPath(): string
    {
        $path = '/'.trim(str_replace('\\', '/', $this->string('cert_path') ?: '/ssl'), '/');
        $parts = array_filter(explode('/', $path), fn (string $part) => $part !== '' && $part !== '.');

        return in_array('..', $parts, true) ? '/ssl' : '/'.implode('/', $parts);
    }

    public function renewDays(): int
    {
        return $this->int('renew_days', 30, 1, 60);
    }

    /** @return list<string> */
    public function reservedLabels(): array
    {
        return array_values(array_unique([
            ...self::RESERVED,
            ...array_map(fn (string $label) => strtolower(trim($label)), $this->list('reserved_labels')),
        ]));
    }

    private function string(string $key): string
    {
        $value = $this->settings->get($key);

        return is_string($value) ? trim($value) : '';
    }

    /** @return list<string> */
    private function list(string $key): array
    {
        $value = $this->settings->get($key);

        return is_array($value) ? array_values(array_filter(array_map('trim', array_filter($value, 'is_string')))) : [];
    }

    private function int(string $key, int $default, int $min, int $max): int
    {
        $value = $this->settings->get($key, $default);

        return is_numeric($value) ? max($min, min($max, (int) $value)) : $default;
    }
}
