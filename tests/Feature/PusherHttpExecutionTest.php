<?php

namespace Tests\Feature;

use Pusher\ApiErrorException;
use Pusher\Pusher;
use Pusher\PusherException;
use Spark\Http\Client\Contracts\HttpResponseContract;
use Spark\Http\Client\HttpRequest;
use Spark\Http\Client\HttpResponse;
use Spark\Testing\TestCase;

final class PusherHttpExecutionTest extends TestCase
{
    private function createMockClient(mixed $responseBody = '{}', int $status = 200, array $headers = []): HttpRequest
    {
        return new class($responseBody, $status, $headers) extends HttpRequest {
            public mixed $recordedMethod = null;
            public mixed $recordedUrl = null;
            public mixed $recordedParams = null;
            public mixed $recordedData = null;

            public function __construct(
                private mixed $bodyData,
                private int $statusCode,
                private array $mockHeaders
            ) {
                parent::__construct();
            }

            public function execute(): HttpResponseContract
            {
                $this->recordedMethod = $this->getMethod();
                $this->recordedUrl = $this->getUrl();
                $this->recordedParams = $this->getParams();
                $this->recordedData = $this->getData();

                $body = is_string($this->bodyData) ? $this->bodyData : json_encode($this->bodyData);
                return new HttpResponse(
                    body: $body,
                    status: $this->statusCode,
                    headers: $this->mockHeaders
                );
            }
        };
    }

    public function test_get_successful_response(): void
    {
        $mock = $this->createMockClient(['channels' => ['test' => ['subscription_count' => 5]]]);
        $pusher = new Pusher('key', 'secret', '123', [], $mock);

        $result = $pusher->get('/channels');

        $this->assertTrue(is_object($result));
        $this->assertTrue(is_object($result->channels));
        $this->assertSame(5, $result->channels->test->subscription_count);
    }

    public function test_get_associative_array(): void
    {
        $mock = $this->createMockClient(['name' => 'presence-room', 'occupied' => true]);
        $pusher = new Pusher('key', 'secret', '123', [], $mock);

        $result = $pusher->get('/channels/presence-room', [], true);

        $this->assertIsArray($result);
        $this->assertSame('presence-room', $result['name']);
        $this->assertTrue($result['occupied']);
    }

    public function test_get_channels_unmarshals_channels_to_array(): void
    {
        $mock = $this->createMockClient((object) [
            'channels' => (object) [
                'presence-chat' => (object) ['user_count' => 3],
                'public-news' => (object) ['user_count' => 10],
            ],
        ]);
        $pusher = new Pusher('key', 'secret', '123', [], $mock);

        $result = $pusher->getChannels();

        $this->assertTrue(is_object($result));
        $this->assertIsArray($result->channels);
        $this->assertArrayHasKey('presence-chat', $result->channels);
        $this->assertArrayHasKey('public-news', $result->channels);
    }

    public function test_get_channel_info(): void
    {
        $mock = $this->createMockClient((object) ['occupied' => true, 'subscription_count' => 12]);
        $pusher = new Pusher('key', 'secret', '123', [], $mock);

        $info = $pusher->getChannelInfo('chat', ['info' => 'subscription_count']);
        $this->assertSame(12, $info->subscription_count);
    }

    public function test_get_presence_users(): void
    {
        $mock = $this->createMockClient((object) ['users' => [['id' => 'user-1'], ['id' => 'user-2']]]);
        $pusher = new Pusher('key', 'secret', '123', [], $mock);

        $result = $pusher->getPresenceUsers('presence-room');
        $this->assertCount(2, $result->users);
    }

    public function test_api_error_exception_on_non_200(): void
    {
        $mock = $this->createMockClient('Unknown application id', 404);
        $pusher = new Pusher('key', 'secret', '999', [], $mock);

        try {
            $pusher->get('/channels');
            $this->fail('Expected ApiErrorException');
        } catch (ApiErrorException $e) {
            $this->assertSame(404, $e->getCode());
            $this->assertSame('Unknown application id', $e->getMessage());
            $this->assertSame('(Status 404) Unknown application id', (string) $e);
        }
    }

    public function test_network_failure_status_0_throws_api_error_exception(): void
    {
        $mock = $this->createMockClient('', 0);
        $pusher = new Pusher('key', 'secret', '999', [], $mock);

        $this->expectException(ApiErrorException::class);
        $pusher->get('/channels');
    }

    public function test_malformed_json_response_throws_pusher_exception(): void
    {
        $mock = $this->createMockClient('{invalid json response', 200);
        $pusher = new Pusher('key', 'secret', '999', [], $mock);

        $this->expectException(PusherException::class);
        $pusher->get('/channels');
    }

    public function test_trigger_unmarshals_channel_objects(): void
    {
        $mock = $this->createMockClient((object) [
            'channels' => (object) [
                'ch1' => (object) ['subscription_count' => 2],
            ],
        ]);
        $pusher = new Pusher('key', 'secret', '123', [], $mock);

        $result = $pusher->trigger('ch1', 'test_event', ['hello' => 'world']);

        $this->assertTrue(is_object($result));
        $this->assertIsArray($result->channels);
        $this->assertArrayHasKey('ch1', $result->channels);
    }

    public function test_trigger_batch(): void
    {
        $mock = $this->createMockClient((object) ['batch' => []]);
        $pusher = new Pusher('key', 'secret', '123', [], $mock);

        $result = $pusher->triggerBatch([
            ['channel' => 'ch1', 'name' => 'ev1', 'data' => 'd1'],
            ['channel' => 'ch2', 'name' => 'ev2', 'data' => 'd2'],
        ]);

        $this->assertTrue(is_object($result));
    }

    public function test_send_to_user_and_terminate_user_connections(): void
    {
        $mock = $this->createMockClient((object) ['status' => 'ok']);
        $pusher = new Pusher('key', 'secret', '123', [], $mock);

        $res1 = $pusher->sendToUser('user_42', 'notify', ['alert' => 'new message']);
        $this->assertTrue(is_object($res1));

        $res2 = $pusher->terminateUserConnections('user_42');
        $this->assertTrue(is_object($res2));
    }
}
