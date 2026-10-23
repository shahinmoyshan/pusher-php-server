<?php

namespace Tests\Unit;

use Pusher\Pusher;
use Pusher\PusherException;
use Pusher\Webhook;
use Spark\Testing\TestCase;

final class WebhookTest extends TestCase
{
    private Pusher $pusher;

    protected function setUp(): void
    {
        $this->pusher = new Pusher('test_key', 'test_secret', '123');
    }

    public function test_valid_webhook_signature(): void
    {
        $body = json_encode([
            'time_ms' => 1600000000123,
            'events' => [
                ['name' => 'channel_occupied', 'channel' => 'test-channel'],
            ],
        ]);

        $signature = hash_hmac('sha256', $body, 'test_secret');
        $headers = [
            'X-Pusher-Key' => 'test_key',
            'X-Pusher-Signature' => $signature,
        ];

        $webhook = $this->pusher->webhook($headers, $body);

        $this->assertInstanceOf(Webhook::class, $webhook);
        $this->assertSame(1600000000123, $webhook->get_time_ms());
        $this->assertCount(1, $webhook->get_events());
        $this->assertSame('channel_occupied', $webhook->get_events()[0]->name);
        $this->assertSame('test-channel', $webhook->get_events()[0]->channel);
    }

    public function test_webhook_with_lowercase_headers(): void
    {
        $body = '{"time_ms": 100, "events": []}';
        $signature = hash_hmac('sha256', $body, 'test_secret');

        $headers = [
            'x-pusher-key' => 'test_key',
            'x-pusher-signature' => $signature,
        ];

        $this->pusher->verifySignature($headers, $body);
        $this->assertTrue(true);
    }

    public function test_invalid_signature_throws_exception(): void
    {
        $this->expectException(PusherException::class);
        $this->pusher->verifySignature([
            'X-Pusher-Key' => 'test_key',
            'X-Pusher-Signature' => 'invalid_signature_hash',
        ], 'body');
    }

    public function test_wrong_key_throws_exception(): void
    {
        $this->expectException(PusherException::class);
        $this->pusher->verifySignature([
            'X-Pusher-Key' => 'wrong_key',
            'X-Pusher-Signature' => 'sig',
        ], 'body');
    }
}
