<?php

namespace ChristianHeiko\Bka\Data;

use ChristianHeiko\Bka\Interface\ToArray;

class SubEvent implements ToArray {

    public function __construct(
        public \DateTimeInterface $date_from,
        public \DateTimeInterface $date_to,
        public \DateTimeInterface $openingTime,
    ) { }

    public function toArray(): array{
        return [
            'date_from' => $this->date_from->format(Event::DATE_FORMAT),
            'date_to' => $this->date_to->format(Event::DATE_FORMAT),
            'opening_time' => $this->openingTime->format(Event::TIME_FORMAT),
        ];
    }
}
