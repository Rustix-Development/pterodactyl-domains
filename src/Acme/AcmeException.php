<?php

namespace RustixDev\Domains\Acme;

use RuntimeException;

/** The CA refused or failed a step. The message is safe to show to users. */
class AcmeException extends RuntimeException {}
