<?php

namespace RustixDev\Domains\Support;

/**
 * Checks and normalizes host names. Everything is stored lower case, without a
 * trailing dot, and international names in their ASCII (punycode) form.
 */
final class DomainName
{
    public const string LABEL = '/^(?!-)[a-z0-9-]{1,63}(?<!-)$/';

    /** Lower case, no trailing dot, punycode for international names. Null when it cannot be a host name. */
    public static function normalize(string $name): ?string
    {
        $name = rtrim(strtolower(trim($name)), '.');
        if ($name === '' || strlen($name) > 1000) {
            return null;
        }

        if (preg_match('/[^\x20-\x7e]/', $name)) {
            if (! function_exists('idn_to_ascii')) {
                return null;
            }
            $ascii = idn_to_ascii($name, IDNA_NONTRANSITIONAL_TO_ASCII, INTL_IDNA_VARIANT_UTS46);
            if (! is_string($ascii)) {
                return null;
            }
            $name = strtolower($ascii);
        }

        return self::isHost($name) ? $name : null;
    }

    /** A host name of valid labels with at least one dot and a top level that is not all digits. */
    public static function isHost(string $name): bool
    {
        if (strlen($name) > 253 || ! str_contains($name, '.')) {
            return false;
        }

        $labels = explode('.', $name);
        foreach ($labels as $label) {
            if (! preg_match(self::LABEL, $label)) {
                return false;
            }
        }

        $tld = end($labels);

        return strlen($tld) >= 2 && ! ctype_digit($tld);
    }

    /** A label users can pick: 1 to 63 lower case letters, digits and hyphens, not starting or ending with a hyphen. */
    public static function isLabel(string $label): bool
    {
        return preg_match(self::LABEL, $label) === 1;
    }

    /** Whether the label matches a reserved word; "node*" covers node, node1, node-eu... */
    public static function isReserved(string $label, array $reserved): bool
    {
        foreach ($reserved as $pattern) {
            if ($pattern !== '' && fnmatch($pattern, $label)) {
                return true;
            }
        }

        return false;
    }

    /** Whether $name is $zone or a name under it. */
    public static function within(string $name, string $zone): bool
    {
        return $name === $zone || str_ends_with($name, '.'.$zone);
    }
}
