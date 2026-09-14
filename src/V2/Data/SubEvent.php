<?php

declare(strict_types=1);

namespace ChristianHeiko\Bka\V2\Data;

use ChristianHeiko\Bka\Interface\ToArray;

/** A further date of the same event. Formats follow {@see Event}. */
class SubEvent implements ToArray {

    public function __construct(
        public \DateTimeInterface $dateFrom,
        public \DateTimeInterface $dateTo,
        public ?\DateTimeInterface $openingTime = null,
    ) { }

    /** @return array<string, mixed> */
    public function toArray(): array {
        $data = [
            'date_from' => $this->dateFrom->format(Event::DATE_FORMAT),
            'date_to' => $this->dateTo->format(Event::DATE_FORMAT),
        ];

        if (!is_null($this->openingTime)) {
            $data['opening_time'] = $this->openingTime->format(Event::TIME_FORMAT);
        }

        return $data;
    }

}
