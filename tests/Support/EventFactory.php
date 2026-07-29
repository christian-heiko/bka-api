<?php

declare(strict_types=1);

namespace ChristianHeiko\Bka\Tests\Support;

use ChristianHeiko\Bka\Data\Event;
use ChristianHeiko\Bka\Data\Image;
use ChristianHeiko\Bka\Data\Rate;
use ChristianHeiko\Bka\Data\SubEvent;
use ChristianHeiko\Bka\Data\Text;
use ChristianHeiko\Bka\Enum\EventStatus;
use ChristianHeiko\Bka\Enum\PublicationStatus;

/** Keeps the sample payload used across tests in one place. */
final class EventFactory {

    /** A fully populated event, mirroring examples/events.php. */
    public static function complete(): Event {
        $event = self::minimal();

        $event->attachSubEvent(new SubEvent(
            new \DateTime('2025-01-02 18:00'),
            new \DateTime('2025-01-02 22:00'),
            new \DateTime('2025-01-02 17:00'),
        ));
        $event->attachImage(new Image('.jpg', base64_encode('binary'), Text::make('de', 'Legend')));
        $event->attachRate(new Rate(25.50, Text::make('de', 'Regular Ticket')));

        return $event;
    }

    public static function minimal(): Event {
        return new Event(
            'Some Party Title',
            EventStatus::confirmed,
            1,
            1,
            [1, 2],
            new \DateTime('2025-01-01 18:00'),
            new \DateTime('2025-01-01 22:00'),
            new \DateTime('2025-01-01 17:00'),
            Text::make('de', 'Best Party in Town'),
            Text::make('de', 'Best Party'),
            1,
            PublicationStatus::publish,
            'https://www.petzi.ch/de/',
            true,
            new \DateTime('2024-12-01 09:30'),
        );
    }

    /** No opening time, no publication date, no attachments — everything optional omitted. */
    public static function bare(): Event {
        $event = new Event(
            'No Opening',
            EventStatus::confirmed,
            1,
            1,
            [1],
            new \DateTime('2025-01-01 18:00'),
            new \DateTime('2025-01-01 22:00'),
            null,
            Text::make('de', 'x'),
            Text::make('de', 'y'),
            1,
            PublicationStatus::draft,
        );

        $event->attachSubEvent(new SubEvent(
            new \DateTime('2025-01-02 18:00'),
            new \DateTime('2025-01-02 22:00'),
        ));

        return $event;
    }

}
