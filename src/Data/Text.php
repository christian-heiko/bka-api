<?php

namespace ChristianHeiko\Bka\Data;

use ChristianHeiko\Bka\Interface\ToArray;

class Text implements ToArray {

    public array $languages = [];

    public static function make(string $language, string $text): static {
        return (new static)->setText($language, $text);
    }

    public function setText(string $language, string $text): static {
        $this->languages[$language] = $text;

        return $this;
    }

    public function toArray(): array {
        return $this->languages;
    }

}
