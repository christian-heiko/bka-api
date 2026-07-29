<?php

declare(strict_types=1);

namespace ChristianHeiko\Bka\Data;

use ChristianHeiko\Bka\Exception\InvalidTextException;
use ChristianHeiko\Bka\Interface\ToArray;

/** @phpstan-consistent-constructor Subclasses must keep the constructor signature; make() relies on it. */
class Text implements ToArray {

    public array $languages = [];

    public static function make(string $language, string $text): static {
        return (new static)->setText($language, $text);
    }

    public function setText(string $language, string $text): static {
        if (empty($text)) {
            throw new InvalidTextException('Text cannot be empty');
        }

        $this->languages[$language] = $text;

        return $this;
    }

    /** @return array<string, mixed> */
    public function toArray(): array {
        return $this->languages;
    }

}
