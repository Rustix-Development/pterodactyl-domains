<?php

namespace RustixDev\Domains\Support;

use RuntimeException;

/** Something the user asked for cannot be done. The message is shown as is. */
class DomainProblem extends RuntimeException {}
