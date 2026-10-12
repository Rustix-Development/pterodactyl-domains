<?php

namespace RustixDev\Domains\Support;

use Pterodactyl\Models\Server;

/**
 * Which SRV service a server's game uses, from the admin's list of rules:
 * "egg:5=_minecraft._tcp" (egg id), "tag:minecraft=_minecraft._tcp" (egg tag) or
 * "name:paper=_minecraft._tcp" (part of the egg name). The first match wins.
 */
final class SrvServices
{
    public const string SERVICE = '/^_[a-z0-9][a-z0-9-]{0,30}\._(tcp|udp|tls)$/';

    public const string RULE = '/^(egg|tag|name):([^=]{1,64})=(_[a-z0-9][a-z0-9-]{0,30}\._(?:tcp|udp|tls))$/';

    /** @param list<string> $rules */
    public static function forServer(Server $server, array $rules): ?string
    {
        $egg = $server->egg;
        if ($egg === null) {
            return null;
        }

        $name = strtolower((string) $egg->name);
        $tags = method_exists($egg, 'tagSlugs') ? $egg->tagSlugs() : [];

        foreach ($rules as $rule) {
            if (! preg_match(self::RULE, strtolower(trim($rule)), $match)) {
                continue;
            }

            [, $kind, $value, $service] = $match;
            $value = trim($value);
            $hit = match ($kind) {
                'egg' => ctype_digit($value) && (int) $value === (int) $egg->id,
                'tag' => in_array($value, $tags, true),
                'name' => $value !== '' && str_contains($name, $value),
            };

            if ($hit) {
                return $service;
            }
        }

        return null;
    }
}
