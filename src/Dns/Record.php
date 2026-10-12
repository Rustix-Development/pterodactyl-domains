<?php

namespace RustixDev\Domains\Dns;

/**
 * One DNS record as the extension sees it, whatever the provider. For SRV records
 * $content is the target host and $priority, $weight and $port are set.
 */
final readonly class Record
{
    public function __construct(
        public string $type,
        public string $name,
        public string $content,
        public int $ttl = 300,
        public ?int $priority = null,
        public ?int $weight = null,
        public ?int $port = null,
        public ?string $id = null,
    ) {}

    public static function srv(string $name, string $target, int $port, int $ttl = 300): self
    {
        return new self('SRV', $name, $target, $ttl, 0, 5, $port);
    }

    public function withId(string $id): self
    {
        return new self($this->type, $this->name, $this->content, $this->ttl, $this->priority, $this->weight, $this->port, $id);
    }

    /** The value as it is written in a zone file: "0 5 25565 play.example.com" for SRV. */
    public function value(): string
    {
        return $this->type === 'SRV'
            ? sprintf('%d %d %d %s', $this->priority ?? 0, $this->weight ?? 5, $this->port ?? 0, $this->content)
            : $this->content;
    }

    /** @return array{type: string, name: string, value: string, ttl: int} */
    public function toPayload(): array
    {
        return ['type' => $this->type, 'name' => $this->name, 'value' => $this->value(), 'ttl' => $this->ttl];
    }
}
