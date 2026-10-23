<?php

namespace Tests\Unit;

use Pusher\Pusher;
use Pusher\PusherCrypto;
use Pusher\PusherException;
use Spark\Testing\TestCase;

final class PusherCryptoTest extends TestCase
{
    private string $rawKey;
    private string $base64Key;
    private PusherCrypto $crypto;

    protected function setUp(): void
    {
        $this->rawKey = str_repeat('k', 32);
        $this->base64Key = base64_encode($this->rawKey);
        $this->crypto = new PusherCrypto($this->rawKey);
    }

    public function test_is_encrypted_channel(): void
    {
        $this->assertTrue(PusherCrypto::is_encrypted_channel('private-encrypted-chat'));
        $this->assertFalse(PusherCrypto::is_encrypted_channel('private-chat'));
        $this->assertFalse(PusherCrypto::is_encrypted_channel('public-chat'));
    }

    public function test_has_mixed_channels(): void
    {
        $this->assertTrue(PusherCrypto::has_mixed_channels(['private-encrypted-a', 'public-b']));
        $this->assertFalse(PusherCrypto::has_mixed_channels(['private-encrypted-a', 'private-encrypted-b']));
        $this->assertFalse(PusherCrypto::has_mixed_channels(['public-a', 'public-b']));
    }

    public function test_parse_master_key(): void
    {
        $parsed = PusherCrypto::parse_master_key($this->base64Key);
        $this->assertSame($this->rawKey, $parsed);
    }

    public function test_encrypt_and_decrypt_payload(): void
    {
        $channel = 'private-encrypted-test';
        $plaintext = 'Secret message 123';

        $encrypted = $this->crypto->encrypt_payload($channel, $plaintext);
        $this->assertStringContainsString('nonce', $encrypted);
        $this->assertStringContainsString('ciphertext', $encrypted);

        $event = (object) [
            'channel' => $channel,
            'data' => $encrypted,
        ];

        $decryptedEvent = $this->crypto->decrypt_event($event);
        $this->assertSame($plaintext, $decryptedEvent->data);
    }

    public function test_encrypt_on_non_encrypted_channel_throws(): void
    {
        $this->expectException(PusherException::class);
        $this->crypto->encrypt_payload('public-channel', 'Secret');
    }

    public function test_webhook_with_encrypted_event(): void
    {
        $pusher = new Pusher('key', 'secret', 'app', [
            'encryption_master_key_base64' => $this->base64Key,
        ]);

        $channel = 'private-encrypted-notes';
        $secretMessage = json_encode(['text' => 'Confidential!']);
        $encryptedPayload = $this->crypto->encrypt_payload($channel, $secretMessage);

        $body = json_encode([
            'time_ms' => 12345678,
            'events' => [
                (object) [
                    'name' => 'secret_event',
                    'channel' => $channel,
                    'data' => $encryptedPayload,
                ]
            ]
        ]);

        $headers = [
            'X-Pusher-Key' => 'key',
            'X-Pusher-Signature' => hash_hmac('sha256', $body, 'secret'),
        ];

        $webhook = $pusher->webhook($headers, $body);
        $events = $webhook->get_events();
        $this->assertCount(1, $events);
        $this->assertSame($secretMessage, $events[0]->data);
    }
}
