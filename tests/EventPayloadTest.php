<?php

declare(strict_types=1);

namespace ChristianHeiko\Bka\Tests;

use ChristianHeiko\Bka\Data\Event;
use ChristianHeiko\Bka\Tests\Support\EventFactory;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase as BaseTestCase;

/**
 * Guards the write payload against the published OpenAPI spec
 * (https://admin.bka.ch/openapi.yaml — `ApiEventType` and `ApiSubEventType`).
 */
final class EventPayloadTest extends BaseTestCase {

    /** Properties `ApiEventType` declares. Anything else is undocumented. */
    private const SPEC_EVENT_KEYS = [
        'name', 'eventStatus', 'place', 'organization', 'showInPrint', 'categories',
        'dateFrom', 'dateTo', 'openingTime', 'recurrence', 'recurrenceWeekDays',
        'subEvents', 'description', 'printDescription', 'images', 'rates',
        'specialRate', 'ticketingUrl', 'audience', 'publicationStatus', 'publicationDate',
    ];

    private const SPEC_EVENT_REQUIRED = [
        'name', 'eventStatus', 'place', 'organization', 'showInPrint', 'categories',
        'dateFrom', 'dateTo', 'recurrence', 'description', 'printDescription',
        'audience', 'publicationStatus',
    ];

    private const SPEC_SUBEVENT_KEYS = ['date_from', 'date_to', 'openingTime'];

    #[Test]
    public function sub_events_use_the_casing_the_spec_actually_specifies(): void {
        // Regression: this used to emit `opening_time`, which the API silently dropped.
        // `date_from`/`date_to` really are snake_case — the spec mixes casing here.
        $sub = EventFactory::complete()->toArray()['subEvents'][0];

        self::assertArrayHasKey('openingTime', $sub);
        self::assertArrayNotHasKey('opening_time', $sub);
        self::assertArrayHasKey('date_from', $sub);
        self::assertArrayHasKey('date_to', $sub);
        self::assertMatchesRegularExpression('/^\d{2}:\d{2}$/', $sub['openingTime']);
    }

    #[Test]
    public function publication_date_uses_the_date_format_not_date_time(): void {
        // The spec types publicationDate as `format: date`.
        self::assertSame('2024-12-01', EventFactory::complete()->toArray()['publicationDate']);
    }

    #[Test]
    public function date_from_and_to_stay_date_times(): void {
        $payload = EventFactory::complete()->toArray();

        self::assertSame('2025-01-01T18:00:00Z', $payload['dateFrom']);
        self::assertSame('2025-01-01T22:00:00Z', $payload['dateTo']);
        self::assertSame('17:00', $payload['openingTime']);
    }

    #[Test]
    public function optional_fields_are_omitted_rather_than_sent_as_null(): void {
        $payload = EventFactory::bare()->toArray();

        self::assertArrayNotHasKey('openingTime', $payload);
        self::assertArrayNotHasKey('publicationDate', $payload);
        self::assertArrayNotHasKey('specialRate', $payload);
        self::assertArrayNotHasKey('openingTime', $payload['subEvents'][0]);
    }

    #[Test]
    public function the_payload_contains_no_undocumented_keys(): void {
        $payload = EventFactory::complete()->toArray();

        self::assertSame([], array_diff(array_keys($payload), self::SPEC_EVENT_KEYS));
        self::assertSame([], array_diff(array_keys($payload['subEvents'][0]), self::SPEC_SUBEVENT_KEYS));
        self::assertSame([], array_diff(array_keys($payload['images'][0]), ['fileExtension', 'base64File', 'legend']));
        self::assertSame([], array_diff(array_keys($payload['rates'][0]), ['price', 'designation']));
    }

    #[Test]
    public function every_spec_required_field_is_emitted(): void {
        foreach ([EventFactory::complete(), EventFactory::bare()] as $event) {
            self::assertSame([], array_diff(self::SPEC_EVENT_REQUIRED, array_keys($event->toArray())));
        }
    }

    #[Test]
    public function categories_are_coerced_to_integers(): void {
        self::assertSame([1, 2], EventFactory::complete()->toArray()['categories']);
    }

    #[Test]
    public function translatable_fields_serialise_as_language_maps(): void {
        $payload = EventFactory::complete()->toArray();

        self::assertSame(['de' => 'Best Party in Town'], $payload['description']);
        self::assertSame(['de' => 'Legend'], $payload['images'][0]['legend']);
        self::assertSame(['de' => 'Regular Ticket'], $payload['rates'][0]['designation']);
    }

    #[Test]
    public function enums_serialise_to_their_backing_values(): void {
        $payload = EventFactory::complete()->toArray();

        self::assertSame('confirmed', $payload['eventStatus']);
        self::assertSame('publish', $payload['publicationStatus']);
        self::assertSame('none', $payload['recurrence']);
    }

    #[Test]
    public function the_date_constants_match_the_spec_formats(): void {
        self::assertSame('Y-m-d\TH:i:s\Z', Event::DATE_FORMAT);
        self::assertSame('H:i', Event::TIME_FORMAT);
        self::assertSame('Y-m-d', Event::PUBLICATION_DATE_FORMAT);
    }

}
