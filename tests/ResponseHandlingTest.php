<?php

declare(strict_types=1);

namespace ChristianHeiko\Bka\Tests;

use ChristianHeiko\Bka\Exception\BkaException;
use ChristianHeiko\Bka\Exception\InvalidResponseException;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\Test;

/** Findings #4, #5 and #6. */
final class ResponseHandlingTest extends TestCase {

    #[Test]
    public function it_exposes_the_envelope_total_for_pagination(): void {
        // Regression for #4: `total` used to be discarded, making `limit`/`page` useless.
        $client = $this->client([$this->jsonResponse(200, ['code' => 200, 'data' => [1, 2], 'total' => 874])]);

        $events = $client->events(limit: 2, page: 1);

        self::assertSame([1, 2], $events);
        self::assertSame(874, $client->lastTotal());
        self::assertSame(200, $client->lastEnvelope->code);
    }

    #[Test]
    public function last_total_is_null_when_the_endpoint_reports_none(): void {
        $client = $this->client([$this->jsonResponse(200, ['code' => 200, 'data' => []])]);
        $client->regions();

        self::assertNull($client->lastTotal());
    }

    #[Test]
    public function delete_accepts_any_2xx_envelope_code(): void {
        // Regression for #5: a 204 envelope used to report failure for a successful delete.
        $client = $this->client([$this->jsonResponse(200, ['code' => 204, 'message' => 'deleted'])]);

        self::assertTrue($client->deleteEvent('1'));
    }

    #[Test]
    public function delete_falls_back_to_the_http_status_when_the_envelope_has_no_code(): void {
        $client = $this->client([$this->jsonResponse(200, ['message' => 'deleted'])]);

        self::assertTrue($client->deleteEvent('1'));
    }

    #[Test]
    public function a_non_json_body_raises_a_package_exception_not_a_type_error(): void {
        // Regression for #6: a proxy's HTML error page used to surface as
        // "TypeError: Client::json(): Return value must be of type object|array".
        $client = $this->client([new Response(200, [], '<html>502 Bad Gateway</html>')]);

        $this->expectException(InvalidResponseException::class);
        $this->expectExceptionMessageMatches('/not valid JSON/');

        $client->regions();
    }

    #[Test]
    public function an_envelope_without_data_raises_a_package_exception(): void {
        // Regression for #6: used to emit "Undefined property: stdClass::$data" then a TypeError.
        $client = $this->client([$this->jsonResponse(200, ['code' => 200, 'message' => 'ok'])]);

        $this->expectException(InvalidResponseException::class);
        $this->expectExceptionMessageMatches('/no `data` property/');

        $client->regions();
    }

    #[Test]
    public function every_package_exception_is_catchable_through_the_marker_interface(): void {
        $client = $this->client([new Response(200, [], 'not json')]);

        try {
            $client->regions();
            self::fail('Expected an exception.');
        } catch (BkaException $e) {
            self::assertInstanceOf(InvalidResponseException::class, $e);
            // BC: callers already catching \Exception keep working.
            self::assertInstanceOf(\Exception::class, $e);
        }
    }

}
