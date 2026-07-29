<?php

declare(strict_types=1);

namespace ChristianHeiko\Bka\Data;

use ChristianHeiko\Bka\Interface\ToArray;

class Rate implements ToArray {

    public function __construct(
        public string|float|int $price,
        public Text $designation
    ) { }


    /** @return array<string, mixed> */
    public function toArray(): array {
        return [
            'price' => $this->price,
            'designation' => $this->designation->toArray()
        ];
    }

}
