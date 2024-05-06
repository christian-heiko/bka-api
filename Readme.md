# Berner Kulturagenda API

This is my first open source package; feel free to contribute or give feedback ✌️



## Installation

```shell
composer require christian-heiko/bka-api
```

## Create Instance


### Direct

```php
<?php

use ChristianHeiko\Bka\Client

$client = new Client(
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

use ChristianHeiko\Bka\Client
$accessToken = 'Load From DB or where-ever';
$client = Client::makeFromEnv($accessToken);
```

## Events

Full CRUD Example at `examples/events.php`

## Implemented Endpoints

```php
<?php

use ChristianHeiko\Bka\Client

$client = Client::makeFromEnv('...');

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
