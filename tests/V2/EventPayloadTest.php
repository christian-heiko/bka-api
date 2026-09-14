<?php

declare(strict_types=1);

namespace ChristianHeiko\Bka\Tests\V2;

use ChristianHeiko\Bka\Data\Text;
use ChristianHeiko\Bka\Enum\TicketingDesignation;
use ChristianHeiko\Bka\V2\Data\Rate;
use ChristianHeiko\Bka\V2\Data\SubEvent;
use ChristianHeiko\Bka\V2\Data\Ticketing;
use ChristianHeiko\Bka\V2\Iri;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** The v2 write contract, as established against the live API (see CLAUDE.md). */
final class EventPayloadTest extends TestCase {

    #[Test]
    public function it_serialises_the_complete_write_contract(): void {
        self::assertSame([
            'name' => 'Wa22ermann',
            'event_status' => 'confirmed',
            'place' => '/api/v2/places/ort-von-info-dachstock-ch',
            'categories' => ['/api/v2/categories/1', '/api/v2/categories/3'],
            'date_from' => '2026-10-29T19:30:00+01:00',
            'date_to' => '2026-10-29T23:59:00+01:00',
            'recurrence' => 'none',
            'recurrence_week_days' => [],
            'sub_events' => [[
                'date_from' => '2026-10-30T19:30:00+01:00',
                'date_to' => '2026-10-30T23:00:00+01:00',
                'opening_time' => '19:00',
            ]],
            'images' => ['/api/v2/images/9149', '/api/v2/images/9150'],
            'rates' => [['price' => 28.0, 'labels' => ['designation' => ['de' => 'Normal']]]],
            'ticketings' => [[
                'ticketing_designation' => 'petzi',
                'labels' => [
                    'ticketingUrl' => ['de' => 'https://www.petzi.ch/de/events/64011'],
                    'designation' => ['de' => 'Vorverkauf'],
                ],
            ]],
            'audience' => '/api/v2/audiences/2',
            'publication_status' => 'draft',
            'show_in_print' => true,
            'special_rate' => 'free_price',
            'labels' => [
                'description' => ['de' => 'Rap aus Bern.'],
                'printDescription' => ['de' => 'Rap'],
            ],
            'opening_time' => '19:00',
            'publication_date' => '2026-09-14',
        ], EventFactory::complete()->toArray());
    }

    #[Test]
    public function dates_keep_their_offset_on_both_sides_of_daylight_saving_time(): void {
        // v1's literal `Z` format made callers shift local times by hand, and a fixed
        // shift is wrong for half of the year.
        $summer = EventFactory::minimal()->toArray();
        $winter = EventFactory::complete()->toArray();

        self::assertSame('2026-09-18T21:00:00+02:00', $summer['date_from']);
        self::assertSame('2026-10-29T19:30:00+01:00', $winter['date_from']);
    }

    #[Test]
    public function collections_are_always_sent_so_an_update_can_clear_them(): void {
        // An update is a merge patch: an omitted collection would survive on BKA.
        $data = EventFactory::minimal()->toArray();

        foreach (['images', 'rates', 'ticketings', 'sub_events'] as $collection) {
            self::assertSame([], $data[$collection], $collection);
        }

        self::assertArrayHasKey('special_rate', $data);
        self::assertNull($data['special_rate']);
    }

    #[Test]
    public function unset_optional_values_are_left_out(): void {
        $data = EventFactory::minimal()->toArray();

        self::assertArrayNotHasKey('opening_time', $data);
        self::assertArrayNotHasKey('publication_date', $data);
        // A label cannot be nulled, so an absent print description is simply not sent.
        self::assertSame(['description' => ['de' => 'Tropipunk aus Buenos Aires.']], $data['labels']);
        self::assertSame('publish', $data['publication_status']);
        // The dead legacy key must not come back: v2 accepts it and throws it away.
        self::assertArrayNotHasKey('ticketing_url', $data);
    }

    #[Test]
    public function iris_are_built_per_resource_and_passed_through_when_given(): void {
        self::assertSame('/api/v2/places/ort-von-info-dachstock-ch', Iri::place('ort-von-info-dachstock-ch'));
        self::assertSame('/api/v2/categories/15', Iri::category(15));
        self::assertSame('/api/v2/audiences/2', Iri::audience('2'));
        self::assertSame('/api/v2/images/7', Iri::image('/api/v2/images/7'));
    }

    #[Test]
    public function a_rate_sends_a_float_price_and_a_labelled_designation(): void {
        self::assertSame(
            ['price' => 25.0, 'labels' => ['designation' => ['de' => 'Soli']]],
            (new Rate(25, Text::make('de', 'Soli')))->toArray()
        );
    }

    #[Test]
    public function a_ticket_link_guesses_its_provider_from_the_host(): void {
        self::assertSame(TicketingDesignation::petzi, TicketingDesignation::fromUrl('https://www.petzi.ch/de/events/1'));
        self::assertSame(TicketingDesignation::eventfrog, TicketingDesignation::fromUrl('https://eventfrog.ch/de/p/x.html'));
        self::assertSame(TicketingDesignation::ticketcorner, TicketingDesignation::fromUrl('https://www.ticketcorner.ch/event/1'));
        self::assertSame(TicketingDesignation::other, TicketingDesignation::fromUrl('https://tickets.example.com/1'));
        self::assertSame(TicketingDesignation::other, TicketingDesignation::fromUrl('not a url'));

        self::assertSame([
            'ticketing_designation' => 'other',
            'labels' => [
                'ticketingUrl' => ['de' => 'https://tickets.example.com/1'],
                'designation' => ['de' => 'Tickets'],
            ],
        ], Ticketing::fromUrl('https://tickets.example.com/1', Text::make('de', 'Tickets'))->toArray());
    }

    #[Test]
    public function a_sub_event_without_opening_time_omits_it(): void {
        $subEvent = new SubEvent(EventFactory::zurich('2026-12-31 22:00'), EventFactory::zurich('2027-01-01 04:00'));

        self::assertSame([
            'date_from' => '2026-12-31T22:00:00+01:00',
            'date_to' => '2027-01-01T04:00:00+01:00',
        ], $subEvent->toArray());
    }

}
