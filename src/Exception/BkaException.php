<?php

declare(strict_types=1);

namespace ChristianHeiko\Bka\Exception;

/**
 * Marker interface for every exception this package throws.
 *
 * It is an interface rather than a base class so each concrete exception can
 * still extend the SPL type that fits it best — which is also what keeps the
 * existing `catch (\Exception)` / `catch (\InvalidArgumentException)` callers
 * working unchanged.
 */
interface BkaException extends \Throwable {
}
