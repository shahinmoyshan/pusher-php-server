<?php

namespace Tests\Unit;

use Pusher\Pusher;
use Pusher\PusherInstance;
use Spark\Testing\TestCase;

final class PusherInstanceTest extends TestCase
{
    protected function setUp(): void
    {
        PusherInstance::reset();
    }

    protected function tearDown(): void
    {
        PusherInstance::reset();
    }

    public function test_get_pusher_creates_instance(): void
    {
        PusherInstance::configure('inst_key', 'inst_secret', 'inst_app', [
            'cluster' => 'eu',
        ]);

        $pusher1 = PusherInstance::get_pusher();
        $this->assertInstanceOf(Pusher::class, $pusher1);
        $this->assertSame('api-eu.pusher.com', $pusher1->getSettings()['host']);

        $pusher2 = PusherInstance::get_pusher();
        $this->assertSame($pusher1, $pusher2);
    }

    public function test_set_pusher_explicit(): void
    {
        $custom = new Pusher('custom_k', 'custom_s', 'custom_app');
        PusherInstance::set_pusher($custom);

        $this->assertSame($custom, PusherInstance::get_pusher());
    }
}
