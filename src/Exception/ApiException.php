<?php

declare(strict_types=1);

namespace ChristianHeiko\Bka\Exception;

use Psr\Http\Message\ResponseInterface;

/**
 * The v2 API answered with an error status.
 *
 * The message is meant to be logged as-is: it names the request, the status, and either
 * the field violations or the problem's detail. The parsed parts stay available, and the
 * exception code is the HTTP status.
 */
class ApiException extends \RuntimeException implements BkaException {

    /**
     * @param list<array{propertyPath: string, message: string}> $violations
     */
    public function __construct(
        string $message,
        public readonly int $status,
        public readonly ?string $detail = null,
        public readonly array $violations = [],
        public readonly ?ResponseInterface $response = null,
    ) {
        parent::__construct($message, $status);
    }

    public static function fromResponse(string $method, string $endpoint, ResponseInterface $response): self {
        $status = $response->getStatusCode();
        $body = (string)$response->getBody();
        $problem = json_decode($body, true);
        $problem = is_array($problem) ? $problem : [];

        $violations = [];

        foreach ((array)($problem['violations'] ?? []) as $violation) {
            if (is_array($violation) && isset($violation['message'])) {
                $violations[] = [
                    'propertyPath' => (string)($violation['propertyPath'] ?? ''),
                    'message' => (string)$violation['message'],
                ];
            }
        }

        // API Platform problems carry `detail`; Symfony and LexikJWT errors carry `message`.
        $detail = $problem['detail'] ?? $problem['message'] ?? $problem['title'] ?? null;
        $detail = is_string($detail) ? $detail : null;

        $summary = $violations === []
            ? ($detail ?? (trim(substr($body, 0, 200)) ?: 'no details'))
            : implode('; ', array_map(
                // Class-level constraints (e.g. date_from before date_to) have an empty path.
                fn(array $violation): string => ($violation['propertyPath'] === '' ? '' : $violation['propertyPath'] . ': ') . $violation['message'],
                $violations
            ));

        $message = "$method $endpoint failed with $status: $summary";

        return match ($status) {
            404 => new NotFoundException($message, $status, $detail, $violations, $response),
            422 => new ValidationException($message, $status, $detail, $violations, $response),
            default => new self($message, $status, $detail, $violations, $response),
        };
    }

}
