<?php

use ChristianHeiko\Bka\Data\Text;
use ChristianHeiko\Bka\Enum\EventStatus;
use ChristianHeiko\Bka\Enum\PublicationStatus;
use ChristianHeiko\Bka\Exception\AuthenticationException;
use ChristianHeiko\Bka\Exception\BkaException;
use ChristianHeiko\Bka\Exception\NotFoundException;
use ChristianHeiko\Bka\Exception\TransportException;
use ChristianHeiko\Bka\Exception\ValidationException;
use ChristianHeiko\Bka\V2\Client;
use ChristianHeiko\Bka\V2\Data\Event;
use ChristianHeiko\Bka\V2\Data\Rate;
use ChristianHeiko\Bka\V2\Data\Ticketing;

include __DIR__ . '/../vendor/autoload.php';

// The token is generated in the BKA profile. There is nothing to refresh.
$client = new Client('https://admin.bka.ch/api', getenv('BKA_TOKEN') ?: '');

$placeSlug = 'your-place-slug'; // v2 addresses places by slug: $client->places('your venue')
$categories = [1];              // $client->categories()
$audience = 1;                  // $client->audiences()
$zurich = new DateTimeZone('Europe/Zurich'); // dates keep their offset, so pass local times

// Or whatever your backend looks like:
$eventDbEntry = (object)[
    'bka_slug' => null,    // Stored after the first save. Changes when the event is renamed.
    'bka_images' => [],    // Ids of the images attached on BKA, to delete them once replaced.
    'bka_unconfirmed' => [], // Uploads whose save got no answer — maybe attached, maybe not.
    'title' => 'Some Party Title',
    'start' => '2026-12-31 22:00',
    'end' => '2027-01-01 04:00',
    'doors' => '2026-12-31 21:30',
    'text' => '<p>Best Party in Town</p>',
    'textShort' => 'Best Party',
    'fee' => 25.50,
    'image' => __DIR__ . '/image.jpg',
    'tickets' => 'https://www.petzi.ch/de/events/1',
];

$event = new Event(
    $eventDbEntry->title,
    EventStatus::confirmed,
    $placeSlug,
    $categories,
    new DateTime($eventDbEntry->start, $zurich),
    new DateTime($eventDbEntry->end, $zurich),
    Text::make('de', $eventDbEntry->text),
    $audience,
    publicationStatus: PublicationStatus::draft,
    openingTime: new DateTime($eventDbEntry->doors, $zurich),
    printDescription: Text::make('de', $eventDbEntry->textShort),
);

$event->attachRate(new Rate($eventDbEntry->fee, Text::make('de', 'Regular Ticket')));
$event->attachTicketing(Ticketing::fromUrl($eventDbEntry->tickets, Text::make('de', 'Vorverkauf')));

$uploaded = [];

try {
    // An update of an event with images fails unless it replaces them, so upload them again
    // for every save — and only delete the old ones once the save went through.
    $uploaded[] = $client->uploadImage($eventDbEntry->image)->id;
    $event->images = $uploaded;

    // Updates by slug when one is known, creates otherwise.
    $saved = $client->saveEvent($event, $eventDbEntry->bka_slug);

    $replaced = array_diff([...$eventDbEntry->bka_images, ...$eventDbEntry->bka_unconfirmed], $uploaded);
    array_map([$client, 'deleteImage'], $replaced);

    $eventDbEntry->bka_slug = $saved->slug;
    $eventDbEntry->bka_images = $uploaded;
    $eventDbEntry->bka_unconfirmed = [];
    // $eventDbEntry->save() or whatever.

    // Delete the event (its images go with it):
    $client->deleteEvent($saved->slug);
} catch (TransportException $e) {
    // No answer: the save may have gone through, and the uploads with it. Remember them and
    // clean up after the next successful save instead of deleting them now.
    $eventDbEntry->bka_unconfirmed = [...$eventDbEntry->bka_unconfirmed, ...$uploaded];
    echo $e->getMessage(), "\n";
} catch (NotFoundException $e) {
    // The stored slug is gone: deleted on BKA, or renamed there (which regenerates the slug).
    // saveEvent() does not create it again — check on BKA, then save without a slug if wanted.
    array_map([$client, 'deleteImage'], $uploaded);
    echo $e->getMessage(), "\n";
} catch (ValidationException $e) {
    array_map([$client, 'deleteImage'], $uploaded);

    foreach ($e->violations as $violation) {
        echo $violation['propertyPath'], ': ', $violation['message'], "\n";
    }
} catch (AuthenticationException $e) {
    echo 'Generate a new token in the BKA profile: ', $e->getMessage(), "\n";
} catch (BkaException $e) {
    // BKA answered with an error, so the save did not happen and nothing uses the uploads.
    array_map([$client, 'deleteImage'], $uploaded);
    echo $e->getMessage(), "\n";
}
