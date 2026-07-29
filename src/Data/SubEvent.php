<?php

declare(strict_types=1);

namespace ChristianHeiko\Bka\Data;

use ChristianHeiko\Bka\Interface\ToArray;

class SubEvent implements ToArray {

    public function __construct(
        public \DateTimeInterface $date_from,
        public \DateTimeInterface $date_to,
        public ?\DateTimeInterface $openingTime = null,
    ) { }

    /** @return array<string, mixed> */
    public function toArray(): array{
        $data = [
            'date_from' => $this->date_from->format(Event::DATE_FORMAT),
            'date_to' => $this->date_to->format(Event::DATE_FORMAT),
        ];

        if (!is_null($this->openingTime)) {
            $data['openingTime'] = $this->openingTime->format(Event::TIME_FORMAT);
        }

        return $data;
    }
}
