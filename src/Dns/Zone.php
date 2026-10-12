<?php

namespace RustixDev\Domains\Dns;

final readonly class Zone
{
    public function __construct(public string $id, public string $name) {}
}
