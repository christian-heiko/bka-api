<?php

namespace ChristianHeiko\Bka\Data;

use ChristianHeiko\Bka\Interface\ToArray;

class Rate implements ToArray {

    public function __construct(
        public string|float|int $price,
        public Text $designation
    ) { }


    public function toArray(): array {
        return [
            'price' => $this->price,
            'designation' => $this->designation->toArray()
        ];
    }

}
