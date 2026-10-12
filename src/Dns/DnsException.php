<?php

namespace RustixDev\Domains\Dns;

use RuntimeException;

/** A DNS provider refused or failed a request. The message is safe to show to users. */
class DnsException extends RuntimeException {}
