<?php

namespace Tests\Feature;

use Pusher\ApiErrorException;
use Pusher\Pusher;
use Spark\Testing\TestCase;

final class HttpPoolAndEndToEndTest extends TestCase
{
    private mixed $process = null;
    private int $port = 0;
    private Pusher $pusher;

    protected function setUp(): void
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        if ($socket === false) {
            $this->markTestSkipped("Cannot bind socket for test server: $error");
        }
        $address = stream_socket_get_name($socket, false);
        fclose($socket);

        $this->port = (int) explode(':', $address)[1];

        $serverFile = dirname(__DIR__) . '/Fixtures/server.php';
        $cmd = [PHP_BINARY, '-d', 'display_errors=0', '-d', 'log_errors=0', '-S', "127.0.0.1:{$this->port}", $serverFile];

        $this->process = proc_open($cmd, [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes);

        if (!is_resource($this->process)) {
            $this->markTestSkipped('Cannot start PHP built-in test server.');
        }

        $deadline = microtime(true) + 3.0;
        $connected = false;
        do {
            $conn = @stream_socket_client("tcp://127.0.0.1:{$this->port}", $errno, $error, 0.1);
            if ($conn !== false) {
                fclose($conn);
                $connected = true;
                break;
            }
            usleep(20000);
        } while (microtime(true) < $deadline);

        if (!$connected) {
            if (is_resource($this->process)) {
                proc_terminate($this->process);
                proc_close($this->process);
            }
            $this->markTestSkipped('HTTP test server failed to start within timeout.');
        }

        $this->pusher = new Pusher('test_key', 'test_secret', '123', [
            'host' => '127.0.0.1',
            'port' => $this->port,
            'scheme' => 'http',
            'useTLS' => false,
            'timeout' => 5,
        ]);
    }

    protected function tearDown(): void
    {
        if (is_resource($this->process)) {
            proc_terminate($this->process);
            proc_close($this->process);
            $this->process = null;
        }
    }

    public function test_end_to_end_get_channels(): void
    {
        $response = $this->pusher->getChannels();

        $this->assertTrue(is_object($response));
        $this->assertIsArray($response->channels);
        $this->assertArrayHasKey('presence-lobby', $response->channels);
        $this->assertArrayHasKey('public-chat', $response->channels);
    }

    public function test_end_to_end_get_channel_info(): void
    {
        $response = $this->pusher->getChannelInfo('presence-lobby');

        $this->assertTrue(is_object($response));
        $this->assertTrue($response->occupied);
        $this->assertSame(8, $response->user_count);
    }

    public function test_end_to_end_get_presence_users(): void
    {
        $response = $this->pusher->getPresenceUsers('presence-lobby');

        $this->assertTrue(is_object($response));
        $this->assertCount(2, $response->users);
    }

    public function test_end_to_end_trigger(): void
    {
        $result = $this->pusher->trigger('my-channel', 'greeting', ['message' => 'Hello Real World']);

        $this->assertTrue(is_object($result));
        $this->assertIsArray($result->channels);
        $this->assertArrayHasKey('my-channel', $result->channels);
    }

    public function test_end_to_end_trigger_batch(): void
    {
        $result = $this->pusher->triggerBatch([
            ['channel' => 'my-channel', 'name' => 'ev1', 'data' => ['num' => 1]],
            ['channel' => 'my-channel', 'name' => 'ev2', 'data' => ['num' => 2]],
        ]);

        $this->assertTrue(is_object($result));
        $this->assertIsArray($result->batch);
    }

    public function test_end_to_end_terminate_user(): void
    {
        $result = $this->pusher->terminateUserConnections('user_42');

        $this->assertTrue(is_object($result));
        $this->assertSame('terminated', $result->status);
    }

    public function test_end_to_end_api_error_response(): void
    {
        try {
            $this->pusher->get('/error_endpoint');
            $this->fail('Expected ApiErrorException');
        } catch (ApiErrorException $e) {
            $this->assertSame(403, $e->getCode());
            $this->assertStringContainsString('Forbidden access', $e->getMessage());
        }
    }

    public function test_end_to_end_concurrent_http_pool_array(): void
    {
        $requests = [
            'req1' => $this->pusher->triggerAsync('my-channel', 'event1', ['id' => 1]),
            'req2' => $this->pusher->triggerAsync('my-channel', 'event2', ['id' => 2]),
            'req3' => $this->pusher->triggerAsync('my-channel', 'event3', ['id' => 3]),
        ];

        $results = $this->pusher->pool($requests);

        $this->assertCount(3, $results);
        $this->assertArrayHasKey('req1', $results);
        $this->assertArrayHasKey('req2', $results);
        $this->assertArrayHasKey('req3', $results);

        $this->assertTrue(is_object($results['req1']));
        $this->assertArrayHasKey('my-channel', $results['req1']->channels);
    }

    public function test_end_to_end_concurrent_http_pool_callback(): void
    {
        $results = $this->pusher->pool(function () {
            return [
                'first' => $this->pusher->triggerAsync('my-channel', 'e1', ['a' => 1]),
                'second' => $this->pusher->triggerAsync('my-channel', 'e2', ['b' => 2]),
            ];
        });

        $this->assertCount(2, $results);
        $this->assertArrayHasKey('first', $results);
        $this->assertArrayHasKey('second', $results);
        $this->assertTrue(is_object($results['first']));
        $this->assertTrue(is_object($results['second']));
    }

    public function test_template_body_does_not_leak_into_get_or_replace_post(): void
    {
        $template = \Spark\Http\Client\HttpRequest::make('POST')
            ->withPostFields('stale body')
            ->withHeader('X-Template', 'retained')
            ->withTimeout(3);
        $this->pusher->setClient($template);
        $get = $this->pusher->get('/echo');
        $this->assertSame('GET', $get->method);
        $this->assertSame('', $get->body);
        $this->assertSame('retained', $get->headers->{'X-Template'});
        $post = $this->pusher->post('/echo', '{"fresh":"✓"}');
        $this->assertSame('POST', $post->method);
        $this->assertSame('{"fresh":"✓"}', $post->body);
        $this->assertSame('POST', $template->getMethod());
        $this->assertSame('', $template->getUrl());
    }

    public function test_path_prefix_is_part_of_the_signature(): void
    {
        $pusher = new Pusher('test_key', 'test_secret', '123', [
            'host' => '127.0.0.1',
            'port' => $this->port,
            'scheme' => 'http',
            'path' => '/proxy',
        ]);
        $response = $pusher->post('/echo', '{"message":"exact bytes"}');
        $this->assertSame('{"message":"exact bytes"}', $response->body);
    }

    public function test_pool_can_use_same_request_twice_without_mutating_it(): void
    {
        $request = $this->pusher->getAsync('/echo');
        $originalKey = $request->getKey();
        $responses = $this->pusher->pool(['first' => $request, 'second' => $request]);
        $this->assertSame(['first', 'second'], array_keys($responses));
        $this->assertSame('GET', $responses['first']->method);
        $this->assertSame('GET', $responses['second']->method);
        $this->assertSame($originalKey, $request->getKey());
    }

    public function test_redirects_are_returned_as_api_errors(): void
    {
        try {
            $this->pusher->get('/redirect');
            $this->fail('A signed request must not follow redirects.');
        } catch (ApiErrorException $error) {
            $this->assertSame(307, $error->getCode());
        }
    }

    public function test_lost_publish_response_does_not_trigger_automatic_retries(): void
    {
        $counter = sys_get_temp_dir() . '/pusher-test-' . $this->port . '.count';
        try {
            $this->pusher->post('/incomplete', '{}');
            $this->fail('Expected transport error.');
        } catch (ApiErrorException $error) {
            $this->assertSame(0, $error->getCode());
            $this->assertSame('1', file_get_contents($counter));
        } finally {
            if (is_file($counter)) {
                unlink($counter);
            }
        }
    }

    public function test_encoded_query_values_match_the_wire_signature(): void
    {
        $response = $this->pusher->get('/echo', ['filter_by_prefix' => 'literal%2B+ space']);
        $this->assertSame('literal%2B+ space', $response->query->filter_by_prefix);
    }

}
