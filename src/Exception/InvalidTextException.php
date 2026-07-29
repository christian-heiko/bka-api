<?php

declare(strict_types=1);

namespace ChristianHeiko\Bka\Exception;

/** A translatable text was given an empty value. */
class InvalidTextException extends \InvalidArgumentException implements BkaException {
}
