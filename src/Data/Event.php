<?php

namespace ChristianHeiko\Bka\Data;

use ChristianHeiko\Bka\Enum\EventStatus;
use ChristianHeiko\Bka\Enum\PublicationStatus;
use ChristianHeiko\Bka\Enum\Recurrence;
use ChristianHeiko\Bka\Enum\SpecialRate;
use ChristianHeiko\Bka\Interface\ToArray;

class Event implements ToArray {

    public const DATE_FORMAT = 'Y-m-d\TH:i:s\Z';

    public const TIME_FORMAT = 'H:i';

    public function __construct(
        public string $name,
        public EventStatus $eventStatus,
        public int $place,
        public int $organization,
        public array $categories,
        public \DateTimeInterface $dateFrom,
        public \DateTimeInterface $dateTo,
        public \DateTimeInterface $openingTime,
        public Text $description,
        public Text $printDescription,
        public int $audience,
        public PublicationStatus $publicationStatus,

        // Optional:
        public ?string $ticketingUrl = null,
        public bool $showInPrint = false,
        public ?\DateTimeInterface $publicationDate = null,
        public Recurrence $recurrence = Recurrence::none,
        public ?SpecialRate $specialRate = null,

        public array $subEvents = [],
        public array $recurrenceWeekDays = [],
        public array $images = [],
        public array $rates = [],
    ) {}

    public function attachSubEvent(SubEvent $subEvent): static {
        $this->subEvents[] = $subEvent;
        return $this;
    }

    public function attachImage(Image $image): static {
        $this->images[] = $image;
        return $this;
    }

    public function attachRate(Rate $rate): static {
        $this->rates[] = $rate;
        return $this;
    }

    public function toArray(): array {
        $data = [
            'name' => $this->name,
            'eventStatus' => $this->eventStatus->value,
            'showInPrint' => $this->showInPrint,
            'place' => $this->place,
            'organization' => $this->organization,
            'categories' => array_map(fn(mixed $value): int => (int)$value, $this->categories),
            'dateFrom' => $this->dateFrom->format(self::DATE_FORMAT),
            'dateTo' => $this->dateTo->format(self::DATE_FORMAT),
            'openingTime' => $this->openingTime->format(self::TIME_FORMAT),
            'recurrence' => $this->recurrence->value,
            'recurrenceWeekDays' => $this->recurrenceWeekDays,
            'subEvents' => array_map(fn(SubEvent $subEvent): array => $subEvent->toArray(), $this->subEvents),
            'description' => $this->description->toArray(),
            'printDescription' => $this->printDescription->toArray(),
            'images' => array_map(fn(Image $image): array => $image->toArray(), $this->images),
            'rates' => array_map(fn(Rate $rate): array => $rate->toArray(), $this->rates),
            'ticketingUrl' => $this->ticketingUrl,
            'audience' => $this->audience,
            'publicationStatus' => $this->publicationStatus->value,
        ];

        if (!is_null($this->specialRate)) {
            $data['specialRate'] = $this->specialRate->value;
        }

        if (!is_null($this->publicationDate)) {
            $data['publicationDate'] = $this->publicationDate->format(self::DATE_FORMAT);
        }

        return $data;
    }

}
