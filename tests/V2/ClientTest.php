<?php

declare(strict_types=1);

namespace ChristianHeiko\Bka\Tests\V2;

use ChristianHeiko\Bka\Exception\ApiException;
use ChristianHeiko\Bka\Exception\AuthenticationException;
use ChristianHeiko\Bka\Exception\BkaException;
use ChristianHeiko\Bka\Exception\ConfigurationException;
use ChristianHeiko\Bka\Exception\InvalidImageException;
use ChristianHeiko\Bka\Exception\InvalidResponseException;
use ChristianHeiko\Bka\Exception\NotFoundException;
use ChristianHeiko\Bka\Exception\ValidationException;
use ChristianHeiko\Bka\Tests\Support\Jwt;
use ChristianHeiko\Bka\V2\Client;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\Test;

final class ClientTest extends TestCase {

    #[Test]
    public function it_talks_json_ld_to_the_v2_base_path_with_the_bearer_token(): void {
        $token = Jwt::valid('v2');
        $client = $this->client([$this->collection([])], token: $token);

        $client->events();

        $request = $this->lastRequest();
        self::assertSame('GET', $request->getMethod());
        self::assertSame('https://example.test/api/v2/events?page=1', (string)$request->getUri());
        self::assertSame('application/ld+json', $request->getHeaderLine('Accept'));
        self::assertSame('Bearer ' . $token, $request->getHeaderLine('Authorization'));
    }

    #[Test]
    public function a_trailing_slash_on_the_base_url_does_not_double_up(): void {
        $this->mock = new \GuzzleHttp\Handler\MockHandler([$this->collection([])]);
        $client = new Client('https://example.test/api/', Jwt::valid(), ['handler' => \GuzzleHttp\HandlerStack::create($this->mock)]);

        $client->regions();

        self::assertSame('https://example.test/api/v2/regions', (string)$this->lastRequest()->getUri());
    }

    #[Test]
    public function collections_are_unwrapped_and_their_total_exposed(): void {
        $client = $this->client([$this->collection([['id' => 1, 'slug' => 'a'], ['id' => 2, 'slug' => 'b']], total: 53)]);

        $events = $client->events(page: 2, itemsPerPage: 2);

        self::assertSame(['a', 'b'], array_map(fn(object $event): string => $event->slug, $events));
        self::assertSame(53, $client->lastTotal());
        self::assertSame('page=2&itemsPerPage=2', $this->lastRequest()->getUri()->getQuery());
    }

    #[Test]
    public function prefixed_hydra_keys_and_plain_lists_are_understood_too(): void {
        $client = $this->client([
            $this->jsonLd(200, ['hydra:member' => [['id' => 7]], 'hydra:totalItems' => 1]),
            new Response(200, ['Content-Type' => 'application/json'], '[{"id":3}]'),
        ]);

        self::assertSame(7, $client->categories()[0]->id);
        self::assertSame(1, $client->lastTotal());

        self::assertSame(3, $client->regions()[0]->id);
        self::assertNull($client->lastTotal(), 'A plain list reports no total.');
    }

    #[Test]
    public function all_events_follows_the_pagination(): void {
        $client = $this->client([
            $this->collection([['id' => 1], ['id' => 2]], total: 3),
            $this->collection([['id' => 3]], total: 3),
        ]);

        $events = $client->allEvents(itemsPerPage: 2);

        self::assertSame([1, 2, 3], array_map(fn(object $event): int => $event->id, $events));
        self::assertSame(0, $this->mock->count());
    }

    #[Test]
    public function a_body_that_is_not_a_collection_raises(): void {
        $client = $this->client([$this->jsonLd(200, ['id' => 1])]);

        $this->expectException(InvalidResponseException::class);
        $this->expectExceptionMessageMatches('/not a collection/');

        $client->categories();
    }

    #[Test]
    public function places_and_organizations_forward_the_keyword(): void {
        $client = $this->client([$this->collection([]), $this->collection([])]);

        $client->places('dachstock');
        self::assertSame('https://example.test/api/v2/places?keyword=dachstock', (string)$this->lastRequest()->getUri());

        $client->organizations();
        self::assertSame('https://example.test/api/v2/organizations', (string)$this->lastRequest()->getUri());
    }

    #[Test]
    public function an_event_is_fetched_by_slug(): void {
        $client = $this->client([$this->jsonLd(200, ['id' => 12969, 'slug' => 'lieder-tour'])]);

        $event = $client->event('lieder-tour');

        self::assertSame(12969, $event->id);
        self::assertSame('https://example.test/api/v2/events/lieder-tour', (string)$this->lastRequest()->getUri());
    }

    #[Test]
    public function an_unknown_slug_is_null(): void {
        $client = $this->client([$this->problem(404, 'Not Found')]);

        self::assertNull($client->event('gone'));
    }

    #[Test]
    public function an_empty_slug_is_null_without_a_request(): void {
        $client = $this->client();

        self::assertNull($client->event(''));
        self::assertNull($this->mock->getLastRequest());
    }

    #[Test]
    public function any_other_lookup_failure_throws_so_callers_never_create_a_duplicate(): void {
        // A caller treating "lookup failed" as "does not exist" would POST a second copy.
        $client = $this->client([new Response(500, ['Content-Type' => 'application/json'], '{"code":500,"message":"Internal Server Error"}')]);

        try {
            $client->event('exists');
            self::fail('Expected an exception.');
        } catch (ApiException $e) {
            self::assertNotInstanceOf(NotFoundException::class, $e);
            self::assertSame(500, $e->status);
            self::assertStringContainsString('Internal Server Error', $e->getMessage());
        }
    }

    #[Test]
    public function create_posts_json_ld_without_a_trailing_slash(): void {
        // The API 301s `events/`, and a followed redirect would turn the POST into a GET.
        $client = $this->client([$this->jsonLd(201, ['id' => 1, 'slug' => 'created'])]);

        $created = $client->createEvent(EventFactory::complete());

        $request = $this->lastRequest();
        self::assertSame('created', $created->slug);
        self::assertSame('POST', $request->getMethod());
        self::assertSame('https://example.test/api/v2/events', (string)$request->getUri());
        self::assertSame('application/ld+json', $request->getHeaderLine('Content-Type'));
        self::assertSame(EventFactory::complete()->toArray(), json_decode((string)$request->getBody(), true));
    }

    #[Test]
    public function prices_keep_their_float_type_on_the_wire(): void {
        $client = $this->client([$this->jsonLd(201, ['slug' => 'x'])]);

        $client->createEvent(EventFactory::complete());

        self::assertStringContainsString('"price":28.0', (string)$this->lastRequest()->getBody());
    }

    #[Test]
    public function update_is_a_merge_patch_by_slug(): void {
        $client = $this->client([$this->jsonLd(200, ['slug' => 'same'])]);

        $client->updateEvent('same', ['name' => 'Renamed']);

        $request = $this->lastRequest();
        self::assertSame('PATCH', $request->getMethod());
        self::assertSame('https://example.test/api/v2/events/same', (string)$request->getUri());
        self::assertSame('application/merge-patch+json', $request->getHeaderLine('Content-Type'));
        self::assertSame('{"name":"Renamed"}', (string)$request->getBody());
    }

    #[Test]
    public function save_creates_without_a_slug_and_patches_with_one(): void {
        $client = $this->client([$this->jsonLd(201, ['slug' => 'a']), $this->jsonLd(200, ['slug' => 'a'])]);

        $client->saveEvent(EventFactory::minimal());
        self::assertSame('POST', $this->lastRequest()->getMethod());

        $client->saveEvent(EventFactory::minimal(), 'a');
        self::assertSame('PATCH', $this->lastRequest()->getMethod());
    }

    #[Test]
    public function save_never_answers_a_404_with_a_create(): void {
        // The slug may just be stale — BKA regenerates it on rename — and a create would then
        // publish the event twice.
        $client = $this->client([$this->problem(404, 'Not Found'), $this->jsonLd(201, ['slug' => 'duplicate'])]);

        try {
            $client->saveEvent(EventFactory::minimal(), 'stale');
            self::fail('Expected an exception.');
        } catch (NotFoundException $e) {
            self::assertSame(404, $e->status);
        }

        self::assertSame('PATCH', $this->lastRequest()->getMethod());
        self::assertSame(1, $this->mock->count(), 'The create must not have been sent.');
    }

    #[Test]
    public function a_request_without_an_answer_is_a_transport_exception(): void {
        $client = $this->client([
            new \GuzzleHttp\Exception\ConnectException('cURL error 28: Operation timed out', new \GuzzleHttp\Psr7\Request('PATCH', 'events/x')),
        ]);

        try {
            $client->updateEvent('x', ['name' => 'y']);
            self::fail('Expected an exception.');
        } catch (\ChristianHeiko\Bka\Exception\TransportException $e) {
            self::assertInstanceOf(BkaException::class, $e);
            self::assertInstanceOf(\GuzzleHttp\Exception\ConnectException::class, $e->getPrevious());
            self::assertStringContainsString('PATCH events/x got no answer: cURL error 28', $e->getMessage());
        }
    }

    #[Test]
    public function text_that_cannot_be_encoded_fails_before_any_request(): void {
        $client = $this->client();

        try {
            $client->createEvent(['name' => "\xB1\x31"]);
            self::fail('Expected an exception.');
        } catch (\ChristianHeiko\Bka\Exception\InvalidTextException $e) {
            self::assertInstanceOf(BkaException::class, $e);
        }

        self::assertNull($this->mock->getLastRequest());
    }

    #[Test]
    public function an_empty_image_file_fails_before_any_request(): void {
        $file = sys_get_temp_dir() . '/bka-v2-empty-' . bin2hex(random_bytes(4)) . '.jpg';
        touch($file);

        try {
            $this->client()->uploadImage($file);
            self::fail('Expected an exception.');
        } catch (InvalidImageException $e) {
            self::assertNull($this->mock->getLastRequest());
        } finally {
            unlink($file);
        }
    }

    #[Test]
    public function the_token_cannot_be_read_from_outside(): void {
        // Monolog and friends serialise context objects through json_encode()/get_object_vars().
        $token = Jwt::valid('secret-marker');
        $client = $this->client(token: $token);

        self::assertStringNotContainsString($token, json_encode($client, JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString($token, print_r(get_object_vars($client), true));
        self::assertStringNotContainsString($token, print_r((array)$client, true));
        self::assertArrayNotHasKey('token', get_object_vars($client));
    }

    #[Test]
    public function save_never_creates_when_the_update_fails_for_another_reason(): void {
        $client = $this->client([
            $this->problem(422, 'images: reassign', [['propertyPath' => 'images', 'message' => 'You do not have permission to reassign the image (1).']]),
            $this->jsonLd(201, ['slug' => 'duplicate']),
        ]);

        try {
            $client->saveEvent(EventFactory::minimal(), 'existing');
            self::fail('Expected an exception.');
        } catch (ValidationException $e) {
            self::assertStringContainsString('reassign the image (1)', $e->getMessage());
        }

        self::assertSame('PATCH', $this->lastRequest()->getMethod());
        self::assertSame(1, $this->mock->count(), 'The create must not have been sent.');
    }

    #[Test]
    public function an_image_is_deleted_by_id_or_iri(): void {
        $client = $this->client([new Response(204), new Response(204), $this->problem(404, 'Not Found')]);

        self::assertTrue($client->deleteImage(42));
        self::assertSame('DELETE', $this->lastRequest()->getMethod());
        self::assertSame('https://example.test/api/v2/images/42', (string)$this->lastRequest()->getUri());

        self::assertTrue($client->deleteImage('/api/v2/images/43'));
        self::assertSame('https://example.test/api/v2/images/43', (string)$this->lastRequest()->getUri());

        self::assertFalse($client->deleteImage(44));
    }

    #[Test]
    public function delete_reports_whether_the_event_existed(): void {
        $client = $this->client([new Response(204), $this->problem(404, 'Not Found')]);

        self::assertTrue($client->deleteEvent('there'));
        self::assertSame('DELETE', $this->lastRequest()->getMethod());
        self::assertFalse($client->deleteEvent('gone'));
    }

    #[Test]
    public function a_422_lists_every_violation_in_the_message(): void {
        $client = $this->client([$this->problem(422, 'name: Dieser Wert sollte nicht leer sein.', [
            ['propertyPath' => 'name', 'message' => 'Dieser Wert sollte nicht leer sein.', 'code' => 'c1051bb4'],
            ['propertyPath' => '', 'message' => 'The date_from must be before the date_to.', 'code' => 'x'],
        ])]);

        try {
            $client->createEvent(['name' => '']);
            self::fail('Expected an exception.');
        } catch (ValidationException $e) {
            self::assertSame(422, $e->status);
            self::assertSame(422, $e->getCode());
            self::assertSame('name', $e->violations[0]['propertyPath']);
            self::assertSame(
                'POST events failed with 422: name: Dieser Wert sollte nicht leer sein.; The date_from must be before the date_to.',
                $e->getMessage()
            );
        }
    }

    #[Test]
    public function a_403_keeps_its_status_and_detail(): void {
        $client = $this->client([$this->problem(403, 'Access Denied.')]);

        try {
            $client->updateEvent('foreign', ['name' => 'x']);
            self::fail('Expected an exception.');
        } catch (ApiException $e) {
            self::assertSame(403, $e->status);
            self::assertSame('Access Denied.', $e->detail);
            self::assertSame('PATCH events/foreign failed with 403: Access Denied.', $e->getMessage());
        }
    }

    #[Test]
    public function a_401_means_the_token_has_to_be_replaced(): void {
        $client = $this->client([new Response(401, ['Content-Type' => 'application/json'], '{"code":401,"message":"Expired JWT Token"}')]);

        $this->expectException(AuthenticationException::class);
        $this->expectExceptionMessageMatches('/Expired JWT Token.*BKA profile/');

        $client->events();
    }

    #[Test]
    public function redirects_surface_instead_of_being_followed(): void {
        $client = $this->client([new Response(301, ['Location' => 'https://example.test/api/v2/events'])]);

        try {
            $client->createEvent(['name' => 'x']);
            self::fail('Expected an exception.');
        } catch (ApiException $e) {
            self::assertSame(301, $e->status);
        }

        self::assertSame(0, $this->mock->count());
        self::assertFalse($this->lastOptions()['allow_redirects']);
    }

    #[Test]
    public function a_non_json_body_raises_a_package_exception(): void {
        $client = $this->client([new Response(200, [], '<html>502 Bad Gateway</html>')]);

        $this->expectException(InvalidResponseException::class);
        $this->expectExceptionMessageMatches('/not valid JSON/');

        $client->regions();
    }

    #[Test]
    public function every_error_is_catchable_through_the_marker_interface(): void {
        $client = $this->client([$this->problem(500, 'boom')]);

        try {
            $client->regions();
            self::fail('Expected an exception.');
        } catch (BkaException $e) {
            self::assertInstanceOf(\RuntimeException::class, $e);
        }
    }

    #[Test]
    public function an_image_is_uploaded_as_a_bare_multipart_file(): void {
        // The file is the only part: a `labels[legend][de]` part made the live API answer 500.
        $file = sys_get_temp_dir() . '/bka-v2-' . bin2hex(random_bytes(4)) . '.png';
        file_put_contents($file, 'PNG-BYTES');

        try {
            $client = $this->client([$this->jsonLd(201, ['@id' => '/api/v2/images/42', 'id' => 42])]);

            $image = $client->uploadImage($file);

            $request = $this->lastRequest();
            $body = (string)$request->getBody();

            self::assertSame('/api/v2/images/42', $image->{'@id'});
            self::assertSame('https://example.test/api/v2/images', (string)$request->getUri());
            // The image endpoint answers 406 to anything but JSON-LD.
            self::assertSame('application/ld+json', $request->getHeaderLine('Accept'));
            self::assertStringStartsWith('multipart/form-data; boundary=', $request->getHeaderLine('Content-Type'));
            self::assertStringContainsString('name="file"; filename="' . basename($file) . '"', $body);
            self::assertStringContainsString('PNG-BYTES', $body);
            self::assertSame(1, substr_count($body, 'Content-Disposition'), 'The file must be the only part.');
        } finally {
            unlink($file);
        }
    }

    #[Test]
    public function an_unreadable_image_fails_before_any_request(): void {
        $client = $this->client();

        try {
            $client->uploadImage('/definitely/not/here.jpg');
            self::fail('Expected an exception.');
        } catch (InvalidImageException $e) {
            self::assertStringContainsString('/definitely/not/here.jpg', $e->getMessage());
        }

        self::assertNull($this->mock->getLastRequest());
    }

    #[Test]
    public function the_token_is_checked_locally(): void {
        $expiry = time() + 7200;

        $valid = $this->client(token: Jwt::make($expiry));
        self::assertTrue($valid->isTokenValid());
        self::assertSame($expiry, $valid->tokenExpiresAt()?->getTimestamp());

        self::assertFalse($this->client(token: Jwt::expired())->isTokenValid());

        $malformed = $this->client(token: 'not-a-jwt');
        self::assertFalse($malformed->isTokenValid());
        self::assertNull($malformed->tokenExpiresAt());
    }

    #[Test]
    public function the_token_never_shows_up_in_dumps(): void {
        $token = Jwt::valid('secret-marker');
        $client = $this->client(token: $token);

        self::assertStringNotContainsString($token, print_r($client, true));
        self::assertStringNotContainsString($token, print_r($client->client, true), 'The transport config must not hold it either.');
    }

    #[Test]
    public function make_from_env_needs_url_and_token(): void {
        putenv('BKA_V2TEST_URL=https://example.test/api');
        putenv('BKA_V2TEST_TOKEN=' . Jwt::valid());

        try {
            $client = Client::makeFromEnv('BKA_V2TEST_');
            self::assertSame('https://example.test/api', $client->url);

            putenv('BKA_V2TEST_TOKEN');

            $this->expectException(ConfigurationException::class);
            $this->expectExceptionMessageMatches('/BKA_V2TEST_TOKEN/');

            Client::makeFromEnv('BKA_V2TEST_');
        } finally {
            putenv('BKA_V2TEST_URL');
            putenv('BKA_V2TEST_TOKEN');
        }
    }

}
