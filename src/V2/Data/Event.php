<?php

declare(strict_types=1);

namespace ChristianHeiko\Bka\V2\Data;

use ChristianHeiko\Bka\Data\Text;
use ChristianHeiko\Bka\Enum\EventStatus;
use ChristianHeiko\Bka\Enum\PublicationStatus;
use ChristianHeiko\Bka\Enum\Recurrence;
use ChristianHeiko\Bka\Enum\SpecialRate;
use ChristianHeiko\Bka\Interface\ToArray;
use ChristianHeiko\Bka\V2\Iri;

/**
 * Write model of a v2 event.
 *
 * Keys are snake_case, relations are IRIs and texts live under `labels`, which must
 * contain `description`. Unlike v1, dates keep their UTC offset: pass wall-clock times
 * in the venue's timezone and daylight saving time takes care of itself.
 *
 * Collections (images, rates, ticketings, sub-events) are always sent, so on an update
 * they replace what is on BKA and an empty one clears it. Images need care on updates —
 * see Client::updateEvent().
 */
class Event implements ToArray {

    /** RFC 3339 with offset, e.g. 2026-10-29T19:30:00+01:00. */
    public const DATE_FORMAT = \DateTimeInterface::ATOM;

    public const TIME_FORMAT = 'H:i';

    public const PUBLICATION_DATE_FORMAT = 'Y-m-d';

    /**
     * @param string $place Place slug (or IRI) — v2 does not accept a place's numeric id.
     * @param list<int|string> $categories Category ids (or IRIs).
     * @param int|string $audience Audience id (or IRI).
     * @param bool $showInPrint Sent as `show_in_print`. As of 2026-09 the API accepts it but
     *                          does not apply it — neither this key nor `showInPrint` is stored.
     * @param list<int> $recurrenceWeekDays
     * @param list<SubEvent> $subEvents
     * @param list<int|string> $images Ids (or IRIs) of images uploaded with Client::uploadImage().
     * @param list<Rate> $rates
     * @param list<Ticketing> $ticketings
     */
    public function __construct(
        public string $name,
        public EventStatus $eventStatus,
        public string $place,
        public array $categories,
        public \DateTimeInterface $dateFrom,
        public \DateTimeInterface $dateTo,
        public Text $description,
        public int|string $audience,

        // Optional:
        public PublicationStatus $publicationStatus = PublicationStatus::publish,
        public ?\DateTimeInterface $openingTime = null,
        public ?Text $printDescription = null,
        public bool $showInPrint = false,
        public ?\DateTimeInterface $publicationDate = null,
        public ?SpecialRate $specialRate = null,
        public Recurrence $recurrence = Recurrence::none,
        public array $recurrenceWeekDays = [],
        public array $subEvents = [],
        public array $images = [],
        public array $rates = [],
        public array $ticketings = [],
    ) {}

    public function attachSubEvent(SubEvent $subEvent): static {
        $this->subEvents[] = $subEvent;
        return $this;
    }

    public function attachImage(int|string $image): static {
        $this->images[] = $image;
        return $this;
    }

    public function attachRate(Rate $rate): static {
        $this->rates[] = $rate;
        return $this;
    }

    public function attachTicketing(Ticketing $ticketing): static {
        $this->ticketings[] = $ticketing;
        return $this;
    }

    /** @return array<string, mixed> */
    public function toArray(): array {
        $labels = ['description' => $this->description->toArray()];

        // A label cannot be cleared on update (null is a "structural error"), so an unset
        // print description is left out rather than sent empty.
        if (!is_null($this->printDescription)) {
            $labels['printDescription'] = $this->printDescription->toArray();
        }

        $data = [
            'name' => $this->name,
            'event_status' => $this->eventStatus->value,
            'place' => Iri::place($this->place),
            'categories' => array_values(array_map(fn(int|string $id): string => Iri::category($id), $this->categories)),
            'date_from' => $this->dateFrom->format(self::DATE_FORMAT),
            'date_to' => $this->dateTo->format(self::DATE_FORMAT),
            'recurrence' => $this->recurrence->value,
            'recurrence_week_days' => $this->recurrenceWeekDays,
            'sub_events' => array_map(fn(SubEvent $subEvent): array => $subEvent->toArray(), $this->subEvents),
            'images' => array_values(array_map(fn(int|string $id): string => Iri::image($id), $this->images)),
            'rates' => array_map(fn(Rate $rate): array => $rate->toArray(), $this->rates),
            'ticketings' => array_map(fn(Ticketing $ticketing): array => $ticketing->toArray(), $this->ticketings),
            'audience' => Iri::audience($this->audience),
            'publication_status' => $this->publicationStatus->value,
            'show_in_print' => $this->showInPrint,
            // Null rather than omitted: an update is a merge patch, where an omitted field
            // would keep its old value on BKA.
            'special_rate' => $this->specialRate?->value,
            'labels' => $labels,
        ];

        // Left out when unset, so an update cannot clear them. Whether the API takes null for
        // these was never verified: every event on BKA has an opening time.
        if (!is_null($this->openingTime)) {
            $data['opening_time'] = $this->openingTime->format(self::TIME_FORMAT);
        }

        if (!is_null($this->publicationDate)) {
            $data['publication_date'] = $this->publicationDate->format(self::PUBLICATION_DATE_FORMAT);
        }

        return $data;
    }

}
