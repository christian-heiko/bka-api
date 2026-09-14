<?php

declare(strict_types=1);

namespace ChristianHeiko\Bka\V2;

/**
 * Builds the IRIs v2 expects wherever an event points at another resource.
 *
 * The identifier differs per resource: places are addressed by slug
 * (`/api/v2/places/ort-von-info-dachstock-ch` — the numeric id is rejected), while
 * categories, audiences and images use their numeric id. A value that already is an
 * IRI (starts with `/`) is passed through unchanged.
 */
final class Iri {

    public const PREFIX = '/api/v2/';

    public static function of(string $resource, int|string $identifier): string {
        $identifier = (string)$identifier;

        if (str_starts_with($identifier, '/')) {
            return $identifier;
        }

        return self::PREFIX . $resource . '/' . rawurlencode($identifier);
    }

    public static function place(string $slug): string {
        return self::of('places', $slug);
    }

    public static function category(int|string $id): string {
        return self::of('categories', $id);
    }

    public static function audience(int|string $id): string {
        return self::of('audiences', $id);
    }

    public static function image(int|string $id): string {
        return self::of('images', $id);
    }

}
