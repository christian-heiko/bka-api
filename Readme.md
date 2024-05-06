# Berner Kulturagenda API


## Create Instance


### Direct

```php
<?php

use ChristianHeiko\Bka\ApiClient

$client = new ApiClient(
    'url',
    'clientId',
    'clientSecret',
    'accessToken',
    'refreshToken'
);
```

### Via .env
```dotenv
BKA_URL =
BKA_CLIENT_ID =
BKA_CLIENT_SECRET =
BKA_REFRESH_TOKEN =
```
```php
<?php

use ChristianHeiko\Bka\ApiClient
$accessToken = 'Load From DB or where-ever';
$client = ApiClient::makeFromEnv($accessToken);
```

## Events

```php
<?php

use ChristianHeiko\Bka\ApiClient

$client = ApiClient::makeFromEnv('...');
$placeId = 0; // Find beforehand in the places endpoint
$organizationID = 0; // Find beforehand in the organizations endpoint
$audienceID = 0; // Find/Map beforehand from the audiences endpoint
$categories = [0];  // Find/Map beforehand from the audiences endpoint

$post = get_post(1);
$slug = get_post_meta($post->ID, 'bka_slug');
$bkaEvent = null;

// Check if event is mapped and if yes still exists.
if (!empty($slug)) {
    try {
        $bkaEvent = $client->event($slug);
    } catch (\Exception $e) { /** NOT FOUND **/ }
}

$eventData = [
    'name' => get_post($title), 
    'eventStatus' => 'confirmed',
    'place' => $placeId,
    'organization' => $organizationID,
    'categories' => [],
    'dateFrom' => (new DateTime())->format(DateTimeInterface::ATOM),
    'dateTo' => (new DateTime())->format(DateTimeInterface::ATOM),
    'openingTime' => (new DateTime())->format('H:i'),
    'recurrence' => 'none',
    'subEvents' => [],
    'description' => array:1 [
        'de' => ''
    ],
    'rates' => [
        [
            'price' => 20.50,
            'designation' => [
                'de' 'Regular Entry'
            ]           
        ]       
    ],   
    'images' => [
        [
            'base64File' => base64_encode(file_get_contents('path to image')),
            'fileExtension' => '.jpg',
            'legend' => [
                'de' => 'brutalismus3000',
            ]
        ]
    ],
    'audience' => $audienceID,
    'ticketingUrl' => 'https://petzi.ch/...'
    'publicationStatus' => $post->post_status === 'publish' ? 'publish' : 'draft',
    'publicationDate' => (new DateTime())->format(DateTimeInterface::ATOM)
];

// Save Event is a shortcut to $client->addEvent() or $client->updateEvent()
$savedEvent = $client->saveEvent($eventData, $bkaEvent?->id);

// Store slug of event to db to update it in the future
update_post_meta($post->ID, 'bka_slug', $savedEvent->slug);

// Delete the event:
$client->deleteEvent($savedEvent->id);
```

## All Endpoints
```php
<?php

use ChristianHeiko\Bka\ApiClient

$client = ApiClient::makeFromEnv('...');

$client->events(date_from: new DateTime());
$client->event('slug-to-event');
$client->addEvent(['data for event']);
$client->updateEvent('id', ['data for event']);
$client->saveEvent(['data for event'], 'id'|null); // Shortcut to add/update
$client->deleteEvent('id');

$client->sponsoredEvents();
$client->similarEvents('slug-to-event');
$client->eventsForOrganization('slug-to-organization');
$client->eventsForPlace('slug-to-place');

$client->affiliations();
$client->audiences();
$client->categories();
$client->organizations();
$client->organization('slug-to-organization');
$client->places();
$client->place('slug-to-place');
$client->regions();
```
