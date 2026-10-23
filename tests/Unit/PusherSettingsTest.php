<?php

namespace Tests\Unit;

use Pusher\Pusher;
use Pusher\PusherException;
use Spark\Http\Client\HttpRequest;
use Spark\Testing\TestCase;

final class PusherSettingsTest extends TestCase
{
    public function test_default_settings(): void
    {
        $pusher = new Pusher('my_key', 'my_secret', '12345');
        $settings = $pusher->getSettings();

        $this->assertSame('my_key', $settings['auth_key']);
        $this->assertSame('my_secret', $settings['secret']);
        $this->assertSame('12345', $settings['app_id']);
        $this->assertSame('/apps/12345', $settings['base_path']);
        // Default useTLS is true -> scheme https, port 443
        $this->assertSame('https', $settings['scheme']);
        $this->assertSame(443, $settings['port']);
        $this->assertSame('api-mt1.pusher.com', $settings['host']);
        $this->assertSame(30, $settings['timeout']);
    }

    public function test_use_tls_false_defaults_to_http_and_port_80(): void
    {
        $pusher = new Pusher('key', 'secret', 'app', ['useTLS' => false]);
        $settings = $pusher->getSettings();

        $this->assertSame('http', $settings['scheme']);
        $this->assertSame(80, $settings['port']);
    }

    public function test_cluster_sets_expected_host(): void
    {
        $pusher = new Pusher('key', 'secret', 'app', ['cluster' => 'eu']);
        $settings = $pusher->getSettings();

        $this->assertSame('api-eu.pusher.com', $settings['host']);
    }

    public function test_custom_host_overrides_cluster_and_strips_protocol(): void
    {
        $pusher = new Pusher('key', 'secret', 'app', [
            'cluster' => 'eu',
            'host' => 'https://custom.pusher.local',
            'port' => 8080,
            'scheme' => 'http',
        ]);
        $settings = $pusher->getSettings();

        $this->assertSame('custom.pusher.local', $settings['host']);
        $this->assertSame(8080, $settings['port']);
        $this->assertSame('http', $settings['scheme']);
    }

    public function test_client_template_getter_and_setter(): void
    {
        $template = HttpRequest::make()->withTimeout(15);
        $pusher = new Pusher('key', 'secret', 'app', [], $template);

        $this->assertSame($template, $pusher->getClient());

        $newTemplate = HttpRequest::make()->withTimeout(45);
        $pusher->setClient($newTemplate);
        $this->assertSame($newTemplate, $pusher->getClient());
    }

    public function test_logger_management_and_logging(): void
    {
        $pusher = new Pusher('key', 'secret', 'app');
        $this->assertNull($pusher->getLogger());

        $logs = [];
        $pusher->setLogger(function (string $level, string $msg, array $context) use (&$logs): void {
            $logs[] = ['level' => $level, 'msg' => $msg, 'context' => $context];
        });

        $this->assertNotNull($pusher->getLogger());
    }

    public function test_invalid_encryption_master_key_throws_exception(): void
    {
        $this->expectException(PusherException::class);
        new Pusher('key', 'secret', 'app', [
            'encryption_master_key_base64' => 'too-short',
        ]);
    }

    public function test_valid_encryption_master_key(): void
    {
        $key = base64_encode(str_repeat('k', 32));
        $pusher = new Pusher('key', 'secret', 'app', [
            'encryption_master_key_base64' => $key,
        ]);

        $this->assertInstanceOf(Pusher::class, $pusher);
    }
}
