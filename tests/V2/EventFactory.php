<?php

declare(strict_types=1);

namespace ChristianHeiko\Bka\Tests\V2;

use ChristianHeiko\Bka\Data\Text;
use ChristianHeiko\Bka\Enum\EventStatus;
use ChristianHeiko\Bka\Enum\PublicationStatus;
use ChristianHeiko\Bka\Enum\SpecialRate;
use ChristianHeiko\Bka\V2\Data\Event;
use ChristianHeiko\Bka\V2\Data\Rate;
use ChristianHeiko\Bka\V2\Data\SubEvent;
use ChristianHeiko\Bka\V2\Data\Ticketing;

/** Sample v2 payloads, shaped like the ones accepted by the live API. */
final class EventFactory {

    public static function zurich(string $time): \DateTimeImmutable {
        return new \DateTimeImmutable($time, new \DateTimeZone('Europe/Zurich'));
    }

    /** Required fields only. */
    public static function minimal(): Event {
        return new Event(
            'Kumbia Queers',
            EventStatus::confirmed,
            'ort-von-info-dachstock-ch',
            [1],
            self::zurich('2026-09-18 21:00'),
            self::zurich('2026-09-19 01:00'),
            Text::make('de', 'Tropipunk aus Buenos Aires.'),
            2,
        );
    }

    /** Every field set; dated after the switch to winter time. */
    public static function complete(): Event {
        $event = new Event(
            'Wa22ermann',
            EventStatus::confirmed,
            'ort-von-info-dachstock-ch',
            [1, 3],
            self::zurich('2026-10-29 19:30'),
            self::zurich('2026-10-29 23:59'),
            Text::make('de', 'Rap aus Bern.'),
            2,
            publicationStatus: PublicationStatus::draft,
            openingTime: self::zurich('2026-10-29 19:00'),
            printDescription: Text::make('de', 'Rap'),
            showInPrint: true,
            publicationDate: self::zurich('2026-09-14'),
            specialRate: SpecialRate::free_price,
        );

        return $event
            ->attachSubEvent(new SubEvent(self::zurich('2026-10-30 19:30'), self::zurich('2026-10-30 23:00'), self::zurich('2026-10-30 19:00')))
            ->attachImage(9149)
            ->attachImage('/api/v2/images/9150')
            ->attachRate(new Rate(28, Text::make('de', 'Normal')))
            ->attachTicketing(Ticketing::fromUrl('https://www.petzi.ch/de/events/64011', Text::make('de', 'Vorverkauf')));
    }

}
