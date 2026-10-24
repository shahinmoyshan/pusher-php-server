<?php

namespace Tests\Unit;

use Pusher\{ApiErrorException, Pusher, PusherCrypto, PusherException};
use Spark\Http\Client\HttpRequest;
use Spark\Testing\TestCase;

class RegressionTest extends TestCase
{
    public function testExplicitSchemeChoosesMatchingDefaultPort(): void
    {
        $this->assertSame(443, (new Pusher('k', 's', 'a', ['scheme' => 'https']))->getSettings()['port']);
        $this->assertSame('https', (new Pusher('k', 's', 'a', ['port' => 8443]))->getSettings()['scheme']);
    }

    public function testPoolNormalizesTransportExceptions(): void
    {
        $request = new class extends HttpRequest {
            public function buildCurlHandle()
            {
                throw new \RuntimeException('transport failed');
            }
        };
        $this->expectException(ApiErrorException::class);
        (new Pusher('k', 's', 'a'))->pool([$request]);
    }

    public function testPoolRejectsInvalidEntriesBeforeSending(): void
    {
        $this->expectException(PusherException::class);
        (new Pusher('k', 's', 'a'))->pool(['invalid']);
    }

    public function testEmptyChannelListIsRejected(): void
    {
        $this->expectException(PusherException::class);
        (new Pusher('k', 's', 'a'))->triggerAsync([], 'event', []);
    }

    public function testChannelLengthLimitIsEnforced(): void
    {
        $this->expectException(PusherException::class);
        (new Pusher('k', 's', 'a'))->authorizeChannel(str_repeat('a', 165), '1.1');
    }

    public function testReservedEventFieldsCannotOverrideValidatedArguments(): void
    {
        $this->expectException(PusherException::class);
        (new Pusher('k', 's', 'a'))->triggerAsync('news', 'event', [], ['channel' => 'invalid channel']);
    }

    public function testOversizedDataIsRejectedBeforeSending(): void
    {
        $this->expectException(PusherException::class);
        (new Pusher('k', 's', 'a'))->triggerAsync('news', 'event', str_repeat('a', 10241), [], true);
    }

    public function testOversizedBatchIsRejected(): void
    {
        $this->expectException(PusherException::class);
        (new Pusher('k', 's', 'a'))->triggerBatchAsync(array_fill(0, 11, ['channel' => 'news', 'name' => 'event', 'data' => []]));
    }

    public function testEncryptionRequiresAConfiguredKey(): void
    {
        $this->expectException(PusherException::class);
        (new Pusher('k', 's', 'a'))->triggerAsync('private-encrypted-news', 'event', []);
    }

    public function testDifferentEncryptedChannelsCannotShareCiphertext(): void
    {
        $this->expectException(PusherException::class);
        (new Pusher('k', 's', 'a', ['encryption_master_key_base64' => base64_encode(str_repeat('k', 32))]))
            ->triggerAsync(['private-encrypted-one', 'private-encrypted-two'], 'event', []);
    }

    public function testEncryptedZeroAndEmptyStringRoundTrip(): void
    {
        $crypto = new PusherCrypto(str_repeat('k', 32));
        foreach (['0', ''] as $plaintext) {
            $event = (object) ['channel' => 'private-encrypted-test', 'data' => $crypto->encrypt_payload('private-encrypted-test', $plaintext)];
            $this->assertSame($plaintext, $crypto->decrypt_event($event)->data);
        }
    }

    public function testMalformedEncryptedPayloadProducesPusherException(): void
    {
        $this->expectException(PusherException::class);
        (new PusherCrypto(str_repeat('k', 32)))->decrypt_event((object) ['channel' => 'private-encrypted-test', 'data' => 'null']);
    }

    public function testWebhookHeaderNamesAreCaseInsensitive(): void
    {
        $body = '{"time_ms":1,"events":[]}';
        $webhook = (new Pusher('k', 's', 'a'))->webhook(['X-PUSHER-KEY' => 'k', 'x-Pusher-Signature' => hash_hmac('sha256', $body, 's')], $body);
        $this->assertSame([], $webhook->get_events());
    }

    public function testSignedMalformedWebhookIsRejected(): void
    {
        $body = 'null';
        $this->expectException(PusherException::class);
        (new Pusher('k', 's', 'a'))->webhook(['X-Pusher-Key' => 'k', 'X-Pusher-Signature' => hash_hmac('sha256', $body, 's')], $body);
    }

    public function testMultipartTemplatesAreRejectedBeforeSending(): void
    {
        $template = HttpRequest::make()->asMultipart();
        $this->expectException(PusherException::class);
        (new Pusher('k', 's', 'a', [], $template))->triggerAsync('news', 'event', []);
    }

    public function testReservedSignatureParametersCannotOverrideCredentials(): void
    {
        $this->expectException(PusherException::class);
        Pusher::build_auth_query_params('k', 's', 'GET', '/apps/a/channels', ['auth_key' => 'other']);
    }

    public function testUserIdsAreEncodedAsOnePathSegment(): void
    {
        $request = (new Pusher('k', 's', 'a'))->terminateUserConnectionsAsync('a/b?#');
        $this->assertStringContainsString('/users/a%2Fb%3F%23/terminate_connections', $request->getUrl());
    }
}
