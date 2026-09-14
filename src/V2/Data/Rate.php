<?php

declare(strict_types=1);

namespace ChristianHeiko\Bka\V2\Data;

use ChristianHeiko\Bka\Data\Text;
use ChristianHeiko\Bka\Interface\ToArray;

/** A ticket price. v2 rejects a string price and wants the designation under `labels`. */
class Rate implements ToArray {

    public function __construct(
        public float|int $price,
        public Text $designation,
    ) { }

    /** @return array<string, mixed> */
    public function toArray(): array {
        return [
            'price' => (float)$this->price,
            'labels' => ['designation' => $this->designation->toArray()],
        ];
    }

}
