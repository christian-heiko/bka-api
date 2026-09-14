<?php

declare(strict_types=1);

namespace ChristianHeiko\Bka\Exception;

/** The API rejected the payload (422). `$violations` lists each field and why. */
class ValidationException extends ApiException {
}
