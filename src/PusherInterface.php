<?php

namespace Pusher;

use Spark\Http\Client\Contracts\HttpRequestContract;

interface PusherInterface
{
    /**
     * Fetch the settings.
     *
     * @return array
     */
    public function getSettings(): array;

    /**
     * Trigger an event by providing event name and payload.
     * Optionally provide a socket ID to exclude a client (most likely the sender).
     *
     * @param array|string $channels        A channel name or an array of channel names to publish the event on.
     * @param string       $event
     * @param mixed        $data            Event data
     * @param array        $params          [optional]
     * @param bool         $already_encoded [optional]
     *
     * @throws PusherException   Throws exception if $channels is an array of size 101 or above or $socket_id is invalid
     * @throws ApiErrorException Throws ApiErrorException if the Channels HTTP API responds with an error
     * @return object
     */
    public function trigger($channels, string $event, $data, array $params = [], bool $already_encoded = false): object;

    /**
     * Asynchronously trigger an event by providing event name and payload.
     * Returns an HttpRequestContract instance ready to be executed or pooled.
     *
     * @param array|string $channels        A channel name or an array of channel names to publish the event on.
     * @param string       $event
     * @param mixed        $data            Event data
     * @param array        $params          [optional]
     * @param bool         $already_encoded [optional]
     *
     * @throws PusherException
     * @return HttpRequestContract
     */
    public function triggerAsync($channels, string $event, $data, array $params = [], bool $already_encoded = false): HttpRequestContract;

    /**
     * Trigger multiple events at the same time.
     *
     * @param array $batch           [optional] An array of events to send
     * @param bool  $already_encoded [optional]
     *
     * @throws PusherException   Throws exception if parameters are invalid
     * @throws ApiErrorException Throws ApiErrorException if the Channels HTTP API responds with an error
     * @return object
     */
    public function triggerBatch(array $batch = [], bool $already_encoded = false): object;

    /**
     * Asynchronously trigger multiple events at the same time.
     * Returns an HttpRequestContract instance ready to be executed or pooled.
     *
     * @param array $batch           [optional] An array of events to send
     * @param bool  $already_encoded [optional]
     *
     * @throws PusherException
     * @return HttpRequestContract
     */
    public function triggerBatchAsync(array $batch = [], bool $already_encoded = false): HttpRequestContract;

    /**
     * Send an event to a specific user.
     *
     * @param string $user_id
     * @param string $event
     * @param mixed  $data
     * @param bool   $already_encoded [optional]
     *
     * @throws PusherException
     * @throws ApiErrorException
     * @return object
     */
    public function sendToUser(string $user_id, string $event, $data, bool $already_encoded = false): object;

    /**
     * Asynchronously send an event to a specific user.
     *
     * @param string $user_id
     * @param string $event
     * @param mixed  $data
     * @param bool   $already_encoded [optional]
     *
     * @throws PusherException
     * @return HttpRequestContract
     */
    public function sendToUserAsync(string $user_id, string $event, $data, bool $already_encoded = false): HttpRequestContract;

    /**
     * Terminates all connections established by the user with the given user id.
     *
     * @param string $user_id
     *
     * @throws PusherException
     * @throws ApiErrorException
     * @return object
     */
    public function terminateUserConnections(string $user_id): object;

    /**
     * Asynchronously terminates all connections established by the user with the given user id.
     *
     * @param string $user_id
     *
     * @throws PusherException
     * @return HttpRequestContract
     */
    public function terminateUserConnectionsAsync(string $user_id): HttpRequestContract;

    /**
     * Execute multiple Pusher requests concurrently using HttpPool.
     *
     * @param array<string|int, HttpRequestContract>|callable $requests
     * @return array
     * @throws PusherException
     * @throws ApiErrorException
     */
    public function pool(array|callable $requests): array;

    /**
     * Get information, such as subscriber and user count, for a channel.
     *
     * @param string $channel The name of the channel
     * @param array  $params  Additional parameters for the query e.g. $params = array( 'info' => 'connection_count' )
     *
     * @throws PusherException   If $channel is invalid
     * @throws ApiErrorException Throws ApiErrorException if the Channels HTTP API responds with an error
     * @return object
     */
    public function getChannelInfo(string $channel, array $params = []): object;

    /**
     * Fetch a list containing all channels.
     *
     * @param array $params Additional parameters for the query e.g. $params = array( 'info' => 'connection_count' )
     *
     * @throws PusherException
     * @throws ApiErrorException Throws ApiErrorException if the Channels HTTP API responds with an error
     * @return object
     */
    public function getChannels(array $params = []): object;

    /**
     * Fetch user ids currently subscribed to a presence channel.
     *
     * @param string $channel The name of the channel
     *
     * @throws PusherException
     * @throws ApiErrorException Throws ApiErrorException if the Channels HTTP API responds with an error
     * @return object
     */
    public function getPresenceUsers(string $channel): object;

    /**
     * GET arbitrary REST API resource using a synchronous http client.
     * All request signing is handled automatically.
     *
     * @param string $path   Path excluding /apps/APP_ID
     * @param array  $params API params (see http://pusher.com/docs/rest_api)
     * @param bool   $associative When true, return the response body as an associative array, else return as an object
     *
     * @throws PusherException
     * @throws ApiErrorException Throws ApiErrorException if the Channels HTTP API responds with an error
     * @return mixed See Pusher API docs
     */
    public function get(string $path, array $params = [], bool $associative = false);

    /**
     * Asynchronously GET arbitrary REST API resource.
     * Returns an HttpRequestContract instance ready to be executed or pooled.
     *
     * @param string $path
     * @param array  $params
     * @return HttpRequestContract
     */
    public function getAsync(string $path, array $params = []): HttpRequestContract;

    /**
     * POST arbitrary REST API resource using a synchronous http client.
     * All request signing is handled automatically.
     *
     * @param string $path
     * @param mixed  $body
     * @param array  $params
     * @throws PusherException
     * @throws ApiErrorException
     * @return mixed
     */
    public function post(string $path, $body, array $params = []);

    /**
     * Asynchronously POST arbitrary REST API resource.
     * Returns an HttpRequestContract instance ready to be executed or pooled.
     *
     * @param string $path
     * @param mixed  $body
     * @param array  $params
     * @throws PusherException
     * @return HttpRequestContract
     */
    public function postAsync(string $path, $body, array $params = []): HttpRequestContract;

    /**
     * Creates a socket signature.
     *
     * @param string $channel
     * @param string $socket_id
     * @param string|null $custom_data
     * @return string Json encoded authentication string.
     * @throws PusherException Throws exception if $channel is invalid or above or $socket_id is invalid
     */
    public function authorizeChannel(string $channel, string $socket_id, ?string $custom_data = null): string;

    /**
     * Creates a presence signature (an extension of socket signing).
     *
     * @param string $channel
     * @param string $socket_id
     * @param string $user_id
     * @param mixed  $user_info
     *
     * @throws PusherException Throws exception if $channel is invalid or above or $socket_id is invalid
     * @return string
     */
    public function authorizePresenceChannel(string $channel, string $socket_id, string $user_id, $user_info = null): string;

    /**
     * Verify that a webhook actually came from Pusher, decrypts any
     * encrypted events, and marshals them into a PHP object.
     *
     * @param array  $headers An array of headers from the request
     * @param string $body    The body of the request
     *
     * @throws PusherException
     * @return object Webhook marshalled object with time_ms and events
     */
    public function webhook(array $headers, string $body): object;

    /**
     * Verify that a given Pusher Signature is valid.
     *
     * @param array  $headers An array of headers from the request
     * @param string $body    The body of the request
     *
     * @throws PusherException if signature is incorrect.
     * @return void
     */
    public function verifySignature(array $headers, string $body): void;
}
