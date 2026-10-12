<?php

namespace RustixDev\Domains\Dns;

use RustixDev\Domains\Support\Environment;

/**
 * Public DNS lookups for checking records the user created themselves. Uses the
 * system resolver, or asks one DNS server directly over UDP when a resolver is set
 * in the environment (for tests).
 */
class Lookup
{
    private const array TYPES = ['A' => 1, 'CNAME' => 5, 'TXT' => 16, 'AAAA' => 28, 'SRV' => 33];

    /** @return list<string> addresses, CNAMEs followed */
    public function addresses(string $name, string $type = 'A'): array
    {
        return array_values(array_unique(array_column($this->query($name, $type), 'value')));
    }

    public function cname(string $name): ?string
    {
        $rows = array_filter($this->query($name, 'CNAME'), fn (array $row) => $row['type'] === 'CNAME' && $row['name'] === $name);

        return $rows === [] ? null : array_values($rows)[0]['value'];
    }

    /** @return list<array{priority: int, weight: int, port: int, target: string}> */
    public function srv(string $name): array
    {
        return array_values(array_map(
            fn (array $row) => ['priority' => $row['priority'], 'weight' => $row['weight'], 'port' => $row['port'], 'target' => $row['value']],
            array_filter($this->query($name, 'SRV'), fn (array $row) => $row['type'] === 'SRV'),
        ));
    }

    /**
     * Records of $type for $name; for A, AAAA and TXT only the final answers, after any CNAMEs.
     *
     * @return list<array{type: string, name: string, value: string, priority?: int, weight?: int, port?: int}>
     */
    public function query(string $name, string $type): array
    {
        $server = Environment::resolver();
        $rows = $server === null ? $this->system($name, $type) : $this->direct($server, $name, $type);

        return in_array($type, ['CNAME', 'SRV'], true)
            ? $rows
            : array_values(array_filter($rows, fn (array $row) => $row['type'] === $type));
    }

    /** @return list<array<string, mixed>> */
    private function system(string $name, string $type): array
    {
        $records = @dns_get_record($name, constant('DNS_'.$type));
        if (! is_array($records)) {
            return [];
        }

        $rows = [];
        foreach ($records as $record) {
            $row = ['type' => (string) ($record['type'] ?? ''), 'name' => strtolower((string) ($record['host'] ?? ''))];
            $rows[] = match ($row['type']) {
                'A' => [...$row, 'value' => (string) $record['ip']],
                'AAAA' => [...$row, 'value' => (string) inet_ntop((string) inet_pton((string) $record['ipv6']))],
                'CNAME' => [...$row, 'value' => strtolower(rtrim((string) $record['target'], '.'))],
                'TXT' => [...$row, 'value' => implode('', (array) ($record['entries'] ?? [$record['txt'] ?? '']))],
                'SRV' => [...$row, 'value' => strtolower(rtrim((string) $record['target'], '.')), 'priority' => (int) $record['pri'], 'weight' => (int) $record['weight'], 'port' => (int) $record['port']],
                default => null,
            };
        }

        return array_values(array_filter($rows));
    }

    /** @return list<array<string, mixed>> */
    private function direct(string $server, string $name, string $type): array
    {
        $id = random_int(0, 0xFFFF);
        $packet = pack('nnnnnn', $id, 0x0100, 1, 0, 0, 0).$this->encodeName($name).pack('nn', self::TYPES[$type], 1);

        $socket = @stream_socket_client('udp://'.$server, $errno, $error, 3);
        if ($socket === false) {
            return [];
        }
        stream_set_timeout($socket, 3);
        fwrite($socket, $packet);
        $response = fread($socket, 4096);
        fclose($socket);

        if (! is_string($response) || strlen($response) < 12 || unpack('n', $response)[1] !== $id) {
            return [];
        }

        return $this->parse($response);
    }

    private function encodeName(string $name): string
    {
        $out = '';
        foreach (explode('.', rtrim($name, '.')) as $label) {
            $out .= chr(strlen($label)).$label;
        }

        return $out."\0";
    }

    /** @return list<array<string, mixed>> */
    private function parse(string $message): array
    {
        ['qd' => $questions, 'an' => $answers] = unpack('nid/nflags/nqd/nan', $message);
        $offset = 12;
        for ($i = 0; $i < $questions; $i++) {
            $this->readName($message, $offset);
            $offset += 4;
        }

        $names = array_flip(self::TYPES);
        $rows = [];
        for ($i = 0; $i < $answers && $offset < strlen($message); $i++) {
            $owner = $this->readName($message, $offset);
            ['type' => $type, 'length' => $length] = unpack('ntype/nclass/Nttl/nlength', $message, $offset);
            $offset += 10;
            $start = $offset;
            $offset += $length;

            $row = ['type' => $names[$type] ?? (string) $type, 'name' => $owner];
            $rows[] = match ($row['type']) {
                'A', 'AAAA' => [...$row, 'value' => (string) inet_ntop(substr($message, $start, $length))],
                'CNAME' => [...$row, 'value' => $this->readName($message, $start)],
                'TXT' => [...$row, 'value' => $this->readStrings(substr($message, $start, $length))],
                'SRV' => [...$row, ...$this->readSrv($message, $start)],
                default => null,
            };
        }

        return array_values(array_filter($rows));
    }

    /** @return array{priority: int, weight: int, port: int, value: string} */
    private function readSrv(string $message, int $offset): array
    {
        ['priority' => $priority, 'weight' => $weight, 'port' => $port] = unpack('npriority/nweight/nport', $message, $offset);
        $offset += 6;

        return ['priority' => $priority, 'weight' => $weight, 'port' => $port, 'value' => $this->readName($message, $offset)];
    }

    private function readStrings(string $data): string
    {
        $out = '';
        for ($i = 0; $i < strlen($data);) {
            $length = ord($data[$i]);
            $out .= substr($data, $i + 1, $length);
            $i += $length + 1;
        }

        return $out;
    }

    /** Reads a possibly compressed name and moves $offset past it. */
    private function readName(string $message, int &$offset): string
    {
        $labels = [];
        $jumped = false;
        $position = $offset;
        for ($guard = 0; $guard < 128 && $position < strlen($message); $guard++) {
            $length = ord($message[$position]);
            if ($length === 0) {
                $position++;
                break;
            }
            if (($length & 0xC0) === 0xC0) {
                $pointer = (($length & 0x3F) << 8) | ord($message[$position + 1]);
                if (! $jumped) {
                    $offset = $position + 2;
                }
                $jumped = true;
                $position = $pointer;

                continue;
            }
            $labels[] = substr($message, $position + 1, $length);
            $position += $length + 1;
        }

        if (! $jumped) {
            $offset = $position;
        }

        return strtolower(implode('.', $labels));
    }
}
