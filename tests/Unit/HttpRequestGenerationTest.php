<?php

namespace Tests\Unit;

use Pusher\Pusher;
use Spark\Http\Client\Contracts\HttpRequestContract;
use Spark\Http\Client\HttpRequest;
use Spark\Testing\TestCase;

final class HttpRequestGenerationTest extends TestCase
{
    private Pusher $pusher;

    protected function setUp(): void
    {
        $this->pusher = new Pusher('test_key', 'test_secret', '12345', [
            'cluster' => 'mt1',
            'timeout' => 20,
        ]);
    }

    public function test_get_async_returns_configured_http_request(): void
    {
        $request = $this->pusher->getAsync('/channels', ['filter_by_prefix' => 'presence-']);

        $this->assertInstanceOf(HttpRequestContract::class, $request);
        $this->assertSame('GET', $request->getMethod());
        $this->assertSame('https://api-mt1.pusher.com:443/apps/12345/channels', $request->getUrl());

        $params = $request->getParams();
        $this->assertSame('test_key', $params['auth_key']);
        $this->assertSame('presence-', $params['filter_by_prefix']);
        $this->assertArrayHasKey('auth_signature', $params);

        // Verify headers
        $headers = $request->getHeaders();
        $this->assertContains('Content-Type: application/json', $headers);
        $this->assertContains('X-Pusher-Library: pusher-http-php ' . Pusher::$VERSION, $headers);
    }

    public function test_post_async_returns_configured_http_request(): void
    {
        $payload = json_encode(['foo' => 'bar']);
        $request = $this->pusher->postAsync('/events', $payload);

        $this->assertInstanceOf(HttpRequestContract::class, $request);
        $this->assertSame('POST', $request->getMethod());
        $this->assertSame('https://api-mt1.pusher.com:443/apps/12345/events', $request->getUrl());

        $params = $request->getParams();
        $this->assertSame('test_key', $params['auth_key']);
        $this->assertSame(md5($payload), $params['body_md5']);
        $this->assertArrayHasKey('auth_signature', $params);

        $this->assertSame($payload, $request->getData());
    }

    public function test_trigger_async_creates_valid_event_request(): void
    {
        $request = $this->pusher->triggerAsync('orders', 'OrderPlaced', ['order_id' => 101]);

        $this->assertInstanceOf(HttpRequestContract::class, $request);
        $this->assertSame('POST', $request->getMethod());
        $this->assertSame('https://api-mt1.pusher.com:443/apps/12345/events', $request->getUrl());

        $data = $request->getData();
        $this->assertIsString($data);
        $decoded = json_decode($data, true);
        $this->assertSame('OrderPlaced', $decoded['name']);
        $this->assertSame('orders', $decoded['channel']);
        $this->assertSame(json_encode(['order_id' => 101]), $decoded['data']);
    }

    public function test_trigger_async_multiple_channels(): void
    {
        $request = $this->pusher->triggerAsync(['orders', 'notifications'], 'OrderPlaced', 'string_payload');

        $data = json_decode($request->getData(), true);
        $this->assertSame(['orders', 'notifications'], $decodedChannels = $data['channels']);
    }

    public function test_trigger_batch_async(): void
    {
        $batch = [
            ['channel' => 'ch-1', 'name' => 'ev-1', 'data' => ['a' => 1]],
            ['channel' => 'ch-2', 'name' => 'ev-2', 'data' => 'raw_data'],
        ];

        $request = $this->pusher->triggerBatchAsync($batch);
        $this->assertInstanceOf(HttpRequestContract::class, $request);
        $this->assertSame('https://api-mt1.pusher.com:443/apps/12345/batch_events', $request->getUrl());

        $data = json_decode($request->getData(), true);
        $this->assertArrayHasKey('batch', $data);
        $this->assertCount(2, $data['batch']);
        $this->assertSame('ch-1', $data['batch'][0]['channel']);
        $this->assertSame('ev-1', $data['batch'][0]['name']);
    }

    public function test_send_to_user_async(): void
    {
        $request = $this->pusher->sendToUserAsync('user_99', 'user_notif', ['msg' => 'hi']);
        $data = json_decode($request->getData(), true);

        $this->assertSame('#server-to-user-user_99', $data['channel']);
        $this->assertSame('user_notif', $data['name']);
    }

    public function test_terminate_user_connections_async(): void
    {
        $request = $this->pusher->terminateUserConnectionsAsync('user_99');

        $this->assertSame('POST', $request->getMethod());
        $this->assertSame('https://api-mt1.pusher.com:443/apps/12345/users/user_99/terminate_connections', $request->getUrl());
        $this->assertSame('{}', $request->getData());
    }
}
