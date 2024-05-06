<?php

use ChristianHeiko\Bka\Client;
use ChristianHeiko\Bka\Data\Event;
use ChristianHeiko\Bka\Data\Image;
use ChristianHeiko\Bka\Data\Rate;
use ChristianHeiko\Bka\Enum\EventStatus;
use ChristianHeiko\Bka\Enum\PublicationStatus;
use ChristianHeiko\Bka\Data\Text;

include __DIR__ . '/../vendor/autoload.php';

$client = new Client('url', 'clientId', 'clientSecret', 'accessToken', 'refreshToken');

$placeId = 0; // Find beforehand in the places endpoint.
$organizationID = 0; // Find beforehand in the organizations endpoint.
$audienceID = 0; // Find/Map beforehand from the audiences endpoint.
$categories = [0];  // Find/Map beforehand from the audiences endpoint.
$language = 'de'; // Only de is currently supported.


// Or whatever your backend looks like:
$eventDbEntry = (object)[
    'bka_api_slug' => 'some-slug', // Map Event to BKA Api Event. Empty initially.
    'title' => 'Some Party Title',
    'start' => '01.01.2025 18:00',
    'end' => '01.01.2025 22:00',
    'doors' => '01.01.2025 17:00',
    'text' => 'Best Party in Town',
    'fee' => 25.50,
    'image' => 'https://upload.wikimedia.org/wikipedia/commons/thumb/6/66/SMPTE_Color_Bars.svg/1200px-SMPTE_Color_Bars.svg.png',
    'tickets' => 'https://www.petzi.ch/de/'
];
$bkaApiEvent = null;

if (!empty($eventDbEntry->bka_api_slug)) {
    try {
        $bkaApiEvent = $client->event($eventDbEntry->bka_api_slug);
    } catch (Exception $e) { /** NOT FOUND **/ }
}

$eventData = new Event(
    $eventDbEntry->title,
    EventStatus::confirmed,
    $placeId,
    $organizationID,
    $categories,
    new DateTime($eventDbEntry->start),
    new DateTime($eventDbEntry->end),
    new DateTime($eventDbEntry->doors),
    Text::make($language, $eventDbEntry->text),
    $audienceID,
    PublicationStatus::publish,
    $eventDbEntry->tickets
);

$eventData->images[] = Image::makeFromPath($eventDbEntry->image, Text::make($language, 'Image: ' . $eventDbEntry->title));
$eventData->rates[] = new Rate($eventDbEntry->fee, Text::make($language, 'Regular Ticket'));



// Save Event is a shortcut to $client->addEvent() or $client->updateEvent()
$savedEvent = $client->saveEvent($eventData, $bkaApiEvent?->id);

// Store slug of event to db to update it in the future
$eventDbEntry->bka_api_slug = $savedEvent->slug;
// $eventDbEntry->save() or whatever.

// Delete the event:
$client->deleteEvent($savedEvent->id);
