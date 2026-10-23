<?php

namespace Tests\Unit;

use Pusher\Pusher;
use Spark\Testing\TestCase;

final class SignatureAndAuthTest extends TestCase
{
    private Pusher $pusher;

    protected function setUp(): void
    {
        $this->pusher = new Pusher('test_key', 'test_secret', '999');
    }

    public function test_build_auth_query_params(): void
    {
        $params = Pusher::build_auth_query_params(
            'test_key',
            'test_secret',
            'GET',
            '/apps/999/channels',
            ['info' => 'user_count'],
            '1.0',
            '1600000000'
        );

        $this->assertSame('test_key', $params['auth_key']);
        $this->assertSame('1600000000', $params['auth_timestamp']);
        $this->assertSame('1.0', $params['auth_version']);
        $this->assertSame('user_count', $params['info']);
        $this->assertArrayHasKey('auth_signature', $params);

        // Verify HMAC
        $expectedStringToSign = "GET\n/apps/999/channels\nauth_key=test_key&auth_timestamp=1600000000&auth_version=1.0&info=user_count";
        $expectedSignature = hash_hmac('sha256', $expectedStringToSign, 'test_secret');
        $this->assertSame($expectedSignature, $params['auth_signature']);
    }

    public function test_authorize_channel_public(): void
    {
        $auth = $this->pusher->authorizeChannel('my-channel', '123.456');
        $decoded = json_decode($auth, true);

        $expectedSig = hash_hmac('sha256', '123.456:my-channel', 'test_secret');
        $this->assertSame('test_key:' . $expectedSig, $decoded['auth']);
        $this->assertArrayNotHasKey('channel_data', $decoded);
    }

    public function test_authorize_channel_with_custom_data(): void
    {
        $customData = json_encode(['user_id' => 'user-1']);
        $auth = $this->pusher->authorizeChannel('presence-chat', '123.456', $customData);
        $decoded = json_decode($auth, true);

        $expectedSig = hash_hmac('sha256', "123.456:presence-chat:$customData", 'test_secret');
        $this->assertSame('test_key:' . $expectedSig, $decoded['auth']);
        $this->assertSame($customData, $decoded['channel_data']);
    }

    public function test_authorize_presence_channel(): void
    {
        $auth = $this->pusher->authorizePresenceChannel('presence-room', '123.456', 'user-42', ['role' => 'admin']);
        $decoded = json_decode($auth, true);

        $this->assertArrayHasKey('auth', $decoded);
        $this->assertArrayHasKey('channel_data', $decoded);

        $channelData = json_decode($decoded['channel_data'], true);
        $this->assertSame('user-42', $channelData['user_id']);
        $this->assertSame(['role' => 'admin'], $channelData['user_info']);
    }
}
