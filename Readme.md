# Berner Kulturagenda API Client

This Guzzle based Client interacts with the Event-Related API of [Kulturagenda](https://bka.ch/) and [In-Situ](https://www.in-situ.org/de/).
Use it to read/create/update/delete Events and get other data such as audiences, categories and so on.

### API Credentials

To use it you will need a login to one of the Platform as well as API-Credentials:
- Client ID
- Client Secret
- Access Token
- Refresh Token

To obtain these credentials please get in Contact with the Support of one of these pages.

### Support
This is an unofficial package and neither this nor me are related or working for one of the organizations or related companies. Therefore, I can not offer support for api-sided issues but im happy to support issues related to this package.

This is my first open source package; feel free to contribute or give feedback ✌️


### Roadmap
A Wordpress Plugin for common Event Plugins could be an option. Please drop me a message if you would like to help develop such a plugin.

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
#.env
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
