<?php

namespace RustixDev\Domains\Support;

/**
 * Overrides read from the panel's environment, for testing against local stand-ins
 * (Pebble, a mock DNS API, a test resolver). They are not admin settings on purpose:
 * none of them is needed, or safe to change, on a live panel.
 */
final class Environment
{
    /** Base URL of the Cloudflare API. */
    public static function cloudflareApi(): string
    {
        $url = self::value('DOMAINS_CLOUDFLARE_API_URL');

        return $url !== null ? rtrim($url, '/') : 'https://api.cloudflare.com/client/v4';
    }

    /** A CA bundle to trust for the ACME server, e.g. Pebble's test CA. */
    public static function acmeCaBundle(): ?string
    {
        $path = self::value('DOMAINS_ACME_CA_BUNDLE');

        return $path !== null && is_file($path) ? $path : null;
    }

    /** host:port of a DNS server to ask instead of the system resolver. */
    public static function resolver(): ?string
    {
        return self::value('DOMAINS_DNS_RESOLVER');
    }

    /** Seconds to wait after setting a challenge record before asking the CA to check it. */
    public static function propagationSeconds(): int
    {
        $value = self::value('DOMAINS_PROPAGATION_SECONDS');

        return $value !== null && ctype_digit($value) ? min((int) $value, 300) : 20;
    }

    private static function value(string $key): ?string
    {
        $value = env($key);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
