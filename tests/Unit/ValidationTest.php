<?php

namespace Tests\Unit;

use Pusher\Pusher;
use Pusher\PusherException;
use Spark\Testing\TestCase;

final class ValidationTest extends TestCase
{
    private Pusher $pusher;

    protected function setUp(): void
    {
        $this->pusher = new Pusher('key', 'secret', '100');
    }

    public function test_valid_channel_names(): void
    {
        $valid = ['test_channel', 'channel-1', 'channel_1', 'channel,1', 'channel;1', 'channel=1', 'channel@1'];
        foreach ($valid as $ch) {
            $req = $this->pusher->triggerAsync($ch, 'ev', ['data' => 1]);
            $this->assertNotNull($req);
        }
    }

    public function test_invalid_channel_name_throws_exception(): void
    {
        $this->expectException(PusherException::class);
        $this->pusher->triggerAsync('invalid channel with spaces!', 'ev', []);
    }

    public function test_too_many_channels_throws_exception(): void
    {
        $channels = [];
        for ($i = 0; $i < 101; $i++) {
            $channels[] = "ch_$i";
        }

        $this->expectException(PusherException::class);
        $this->pusher->triggerAsync($channels, 'ev', []);
    }

    public function test_valid_socket_id(): void
    {
        $req = $this->pusher->triggerAsync('chan', 'ev', [], ['socket_id' => '123.456']);
        $this->assertNotNull($req);
    }

    public function test_invalid_socket_id_throws_exception(): void
    {
        $this->expectException(PusherException::class);
        $this->pusher->triggerAsync('chan', 'ev', [], ['socket_id' => 'abc.123']);
    }

    public function test_empty_user_id_throws_exception(): void
    {
        $this->expectException(PusherException::class);
        $this->pusher->sendToUserAsync('', 'ev', []);
    }

    public function test_user_data_without_id_throws_exception(): void
    {
        $this->expectException(PusherException::class);
        $this->pusher->authenticateUser('123.456', ['name' => 'Alice']);
    }

    public function test_valid_user_data_authentication(): void
    {
        $auth = $this->pusher->authenticateUser('123.456', ['id' => 'user-42', 'name' => 'Alice']);
        $decoded = json_decode($auth, true);

        $this->assertArrayHasKey('auth', $decoded);
        $this->assertArrayHasKey('user_data', $decoded);
        $this->assertSame('key:', substr($decoded['auth'], 0, 4));
    }
}
