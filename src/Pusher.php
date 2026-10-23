<?php

namespace Pusher;

use Spark\Http\Client\Contracts\HttpRequestContract;
use Spark\Http\Client\Contracts\HttpResponseContract;
use Spark\Http\Client\HttpPool;
use Spark\Http\Client\HttpRequest;
use function array_key_exists;
use function count;
use function in_array;
use function is_array;
use function is_null;
use function is_object;
use function is_scalar;
use function is_string;
use function sprintf;

class Pusher implements PusherInterface
{
    /**
     * @var string Version
     */
    public static $VERSION = '7.3.0';

    /**
     * @var null|PusherCrypto
     */
    private ?PusherCrypto $crypto = null;

    /**
     * @var array Settings
     */
    private array $settings = [
        'scheme' => 'http',
        'port' => 80,
        'path' => '',
        'timeout' => 30,
    ];

    /**
     * @var null|HttpRequestContract Client template for HTTP requests
     */
    private ?HttpRequestContract $client = null;

    /**
     * @var mixed Logger instance
     */
    protected mixed $logger = null;

    /**
     * Initializes a new Pusher instance with key, secret, app ID and channel.
     *
     * @param string $auth_key
     * @param string $secret
     * @param string $app_id
     * @param array $options  [optional]
     *                         Options to configure the Pusher instance.
     *                         scheme - e.g. http or https
     *                         host - the host e.g. api-mt1.pusher.com. No trailing forward slash.
     *                         port - the http port
     *                         timeout - the http timeout
     *                         useTLS - quick option to use scheme of https and port 443 (default is true).
     *                         cluster - cluster name to connect to.
     *                         encryption_master_key_base64 - a 32 byte key, encoded as base64.
     * @param HttpRequestContract|null $client [optional] - client template to use for all HTTP requests
     *
     * @throws PusherException Throws exception if any required dependencies are missing
     */
    public function __construct(
        string $auth_key,
        string $secret,
        string $app_id,
        array $options = [],
        ?HttpRequestContract $client = null
    ) {
        $this->check_compatibility();

        $useTLS = true;
        if (isset($options['useTLS'])) {
            $useTLS = $options['useTLS'] === true;
        }
        if (
            $useTLS &&
            !isset($options['scheme']) &&
            !isset($options['port'])
        ) {
            $options['scheme'] = 'https';
            $options['port'] = 443;
        }

        $this->settings['auth_key'] = $auth_key;
        $this->settings['secret'] = $secret;
        $this->settings['app_id'] = $app_id;
        $this->settings['base_path'] = '/apps/' . $this->settings['app_id'];

        foreach ($options as $key => $value) {
            // only set if valid setting/option
            if (isset($this->settings[$key])) {
                $this->settings[$key] = $value;
            }
        }

        // handle the case when 'host' and 'cluster' are specified in the options.
        if (!array_key_exists('host', $this->settings)) {
            if (array_key_exists('host', $options)) {
                $this->settings['host'] = $options['host'];
            } elseif (array_key_exists('cluster', $options)) {
                $this->settings['host'] = 'api-' . $options['cluster'] . '.pusher.com';
            } else {
                $this->settings['host'] = 'api-mt1.pusher.com';
            }
        }

        // ensure host doesn't have a scheme prefix
        $this->settings['host'] = preg_replace('/^http[s]?\:\/\//', '', $this->settings['host'], 1);

        if (!array_key_exists('encryption_master_key_base64', $options)) {
            $options['encryption_master_key_base64'] = '';
        }

        if ($options['encryption_master_key_base64'] !== '') {
            $parsedKey = PusherCrypto::parse_master_key(
                $options['encryption_master_key_base64']
            );
            $this->crypto = new PusherCrypto($parsedKey);
        }

        $this->client = $client;
    }

    /**
     * Get the client template.
     *
     * @return HttpRequestContract|null
     */
    public function getClient(): ?HttpRequestContract
    {
        return $this->client;
    }

    /**
     * Set the client template.
     *
     * @param HttpRequestContract|null $client
     * @return void
     */
    public function setClient(?HttpRequestContract $client): void
    {
        $this->client = $client;
    }

    /**
     * Fetch the settings.
     *
     * @return array
     */
    public function getSettings(): array
    {
        return $this->settings;
    }

    /**
     * Set a logger instance.
     *
     * @param mixed $logger
     * @return void
     */
    public function setLogger(mixed $logger): void
    {
        $this->logger = $logger;
    }

    /**
     * Get the logger instance.
     *
     * @return mixed
     */
    public function getLogger(): mixed
    {
        return $this->logger;
    }

    /**
     * Log a message with optional context.
     *
     * @param string $msg The message to log
     * @param array $context [optional]
     * @param string $level [optional]
     */
    private function log(string $msg, array $context = [], string $level = 'debug'): void
    {
        if ($this->logger === null) {
            return;
        }

        if (is_object($this->logger) && method_exists($this->logger, 'log')) {
            $this->logger->log($level, $msg, $context);
            return;
        }

        if (is_object($this->logger) && method_exists($this->logger, $level)) {
            $this->logger->$level($msg, $context);
            return;
        }

        if (is_callable($this->logger)) {
            ($this->logger)($level, $msg, $context);
            return;
        }

        // Support string interpolation fallback
        $formatted = sprintf('Pusher: %s: %s', strtoupper($level), $msg);
        $replacement = [];
        foreach ($context as $k => $v) {
            $replacement['{' . $k . '}'] = is_scalar($v) ? (string) $v : json_encode($v);
        }
        $formatted = strtr($formatted, $replacement);

        if (is_object($this->logger) && method_exists($this->logger, 'log')) {
            $this->logger->log($level, $formatted);
        }
    }

    /**
     * Check if the current PHP setup is sufficient to run this class.
     *
     * @throws PusherException If any required dependencies are missing
     */
    private function check_compatibility(): void
    {
        if (!extension_loaded('curl')) {
            throw new PusherException('The Pusher library requires the PHP cURL module. Please ensure it is installed');
        }

        if (!extension_loaded('json')) {
            throw new PusherException('The Pusher library requires the PHP JSON module. Please ensure it is installed');
        }

        if (!in_array('sha256', hash_algos(), true)) {
            throw new PusherException('SHA256 appears to be unsupported - make sure you have support for it, or upgrade your version of PHP.');
        }
    }

    /**
     * Validate number of channels and channel name format.
     *
     * @param string[] $channels An array of channel names to validate
     *
     * @throws PusherException If $channels is too big or any channel is invalid
     */
    private function validate_channels(array $channels): void
    {
        if (count($channels) > 100) {
            throw new PusherException('An event can be triggered on a maximum of 100 channels in a single call.');
        }

        foreach ($channels as $channel) {
            $this->validate_channel($channel);
        }
    }

    /**
     * Ensure a channel name is valid based on our spec.
     *
     * @param string $channel The channel name to validate
     *
     * @throws PusherException If $channel is invalid
     */
    private function validate_channel(string $channel): void
    {
        if (!preg_match('/\A#?[-a-zA-Z0-9_=@,.;]+\z/', $channel)) {
            throw new PusherException("Invalid channel name $channel");
        }
    }

    /**
     * Ensure a socket_id is valid based on our spec.
     *
     * @param string|null $socket_id The socket ID to validate
     *
     * @throws PusherException If $socket_id is invalid
     */
    private function validate_socket_id(?string $socket_id): void
    {
        if ($socket_id !== null && !preg_match('/\A\d+\.\d+\z/', $socket_id)) {
            throw new PusherException("Invalid socket ID $socket_id");
        }
    }

    /**
     * Ensure a user id is valid based on our spec.
     *
     * @param string|null $user_id The user id to validate
     *
     * @throws PusherException If $user_id is invalid
     */
    private function validate_user_id(?string $user_id): void
    {
        if ($user_id === null || $user_id === '') {
            throw new PusherException("Invalid user id $user_id");
        }
    }

    /**
     * Utility function used to generate signing headers / parameters.
     *
     * @param string $path
     * @param string $request_method
     * @param array $query_params [optional]
     *
     * @return array
     */
    private function sign(string $path, string $request_method = 'GET', array $query_params = []): array
    {
        return self::build_auth_query_params(
            $this->settings['auth_key'],
            $this->settings['secret'],
            $request_method,
            $path,
            $query_params
        );
    }

    /**
     * Build the Channels url prefix.
     *
     * @return string
     */
    private function channels_url_prefix(): string
    {
        return $this->settings['scheme'] . '://' . $this->settings['host'] . ':' . $this->settings['port'] . $this->settings['path'];
    }

    /**
     * Build the required HMAC'd auth string.
     *
     * @param string $auth_key
     * @param string $auth_secret
     * @param string $request_method
     * @param string $request_path
     * @param array $query_params [optional]
     * @param string $auth_version [optional]
     * @param string|null $auth_timestamp [optional]
     * @return array
     */
    public static function build_auth_query_params(
        string $auth_key,
        string $auth_secret,
        string $request_method,
        string $request_path,
        array $query_params = [],
        string $auth_version = '1.0',
        ?string $auth_timestamp = null
    ): array {
        $params = [];
        $params['auth_key'] = $auth_key;
        $params['auth_timestamp'] = ($auth_timestamp === null ? time() : $auth_timestamp);
        $params['auth_version'] = $auth_version;

        $params = [...$params, ...$query_params];
        ksort($params);

        $string_to_sign = "$request_method\n$request_path\n" . self::array_implode('=', '&', $params);

        $auth_signature = hash_hmac('sha256', $string_to_sign, $auth_secret, false);

        $params['auth_signature'] = $auth_signature;

        return $params;
    }

    /**
     * Implode an array with the key and value pair giving
     * a glue, a separator between pairs and the array
     * to implode.
     *
     * @param string       $glue      The glue between key and value
     * @param string       $separator Separator between pairs
     * @param array|string $array     The array to implode
     *
     * @return string The imploded array
     */
    public static function array_implode(string $glue, string $separator, $array): string
    {
        if (!is_array($array)) {
            return (string) $array;
        }

        $string = [];
        foreach ($array as $key => $val) {
            if (is_array($val)) {
                $val = implode(',', $val);
            }
            $string[] = "{$key}{$glue}{$val}";
        }

        return implode($separator, $string);
    }

    /**
     * @var int Counter for generating unique request keys
     */
    private static int $requestKeyCounter = 0;

    /**
     * Create an HttpRequestContract instance for a given method, path, params, and body.
     *
     * @param string $method
     * @param string $path
     * @param array $params
     * @param mixed $body
     * @param array $headers
     * @return HttpRequestContract
     */
    public function createRequest(string $method, string $path, array $params = [], mixed $body = null, array $headers = []): HttpRequestContract
    {
        $prefix = rtrim($this->channels_url_prefix(), '/');
        $fullPath = str_starts_with($path, '/apps/')
            ? $path
            : $this->settings['base_path'] . (str_starts_with($path, '/') ? $path : '/' . $path);
        $fullUrl = "$prefix/" . ltrim($fullPath, '/');

        $mergedHeaders = [
            'Content-Type' => 'application/json',
            'X-Pusher-Library' => 'pusher-http-php ' . self::$VERSION,
            ...$headers
        ];

        $key = ++self::$requestKeyCounter;
        $request = $this->client !== null ? clone $this->client : HttpRequest::make(key: $key);
        if ($this->client !== null && $request instanceof HttpRequest) {
            $setKey = \Closure::bind(function (HttpRequest $req, int $k) {
                $req->key = $k;
            }, null, HttpRequest::class);
            $setKey($request, $key);
        }

        $request->setMethod(strtoupper($method))
            ->setUrl($fullUrl)
            ->setParams($params)
            ->setData($body ?? [])
            ->withHeaders($mergedHeaders);

        if (in_array(strtoupper($method), ['POST', 'PUT', 'PATCH'], true) && $body !== null) {
            $request->withPostFields($body, 'application/json');
        }

        if ($this->client === null && isset($this->settings['timeout'])) {
            $request->withTimeout((int) $this->settings['timeout']);
        }

        if (isset($this->settings['curl_options']) && is_array($this->settings['curl_options'])) {
            $request->withOptions($this->settings['curl_options']);
        }

        return $request;
    }

    /**
     * Safely execute an HttpRequestContract instance, normalizing failures to ApiErrorException.
     *
     * @param HttpRequestContract $request
     * @return HttpResponseContract
     * @throws ApiErrorException
     */
    protected function executeRequest(HttpRequestContract $request): HttpResponseContract
    {
        try {
            $response = $request->execute();
        } catch (\Throwable $e) {
            throw new ApiErrorException($e->getMessage(), 0, $e);
        }

        if ($response->status() === 0) {
            throw new ApiErrorException('HTTP request failed (status 0).', 0);
        }

        return $response;
    }

    /**
     * Trigger an event by providing event name and payload.
     * Optionally provide a socket ID to exclude a client (most likely the sender).
     *
     * @param array|string $channels A channel name or an array of channel names to publish the event on.
     * @param string $event
     * @param mixed $data Event data
     * @param array $params [optional]
     * @param bool $already_encoded [optional]
     *
     * @return object
     * @throws ApiErrorException Throws ApiErrorException if the Channels HTTP API responds with an error
     * @throws PusherException Throws PusherException if $channels is an array of size 101 or above or $socket_id is invalid
     */
    public function trigger($channels, string $event, $data, array $params = [], bool $already_encoded = false): object
    {
        $post_value = $this->make_trigger_body($channels, $event, $data, $params, $already_encoded);
        $this->log('trigger POST: {post_value}', compact('post_value'));
        return $this->process_trigger_result($this->post('/events', $post_value));
    }

    /**
     * Asynchronously trigger an event by providing event name and payload.
     * Returns an HttpRequestContract instance ready to be executed or pooled.
     *
     * @param array|string $channels A channel name or an array of channel names to publish the event on.
     * @param string $event
     * @param mixed $data Event data
     * @param array $params [optional]
     * @param bool $already_encoded [optional]
     *
     * @return HttpRequestContract
     * @throws PusherException
     */
    public function triggerAsync($channels, string $event, $data, array $params = [], bool $already_encoded = false): HttpRequestContract
    {
        $post_value = $this->make_trigger_body($channels, $event, $data, $params, $already_encoded);
        $this->log('trigger POST: {post_value}', compact('post_value'));
        return $this->postAsync('/events', $post_value);
    }

    /**
     * Send an event to a user.
     *
     * @param string $user_id
     * @param string $event
     * @param mixed $data Event data
     * @param bool $already_encoded [optional]
     *
     * @return object
     * @throws PusherException
     * @throws ApiErrorException
     */
    public function sendToUser(string $user_id, string $event, $data, bool $already_encoded = false): object
    {
        $this->validate_user_id($user_id);
        return $this->trigger(["#server-to-user-$user_id"], $event, $data, [], $already_encoded);
    }

    /**
     * Asynchronously send an event to a user.
     *
     * @param string $user_id
     * @param string $event
     * @param mixed $data Event data
     * @param bool $already_encoded [optional]
     *
     * @return HttpRequestContract
     * @throws PusherException
     */
    public function sendToUserAsync(string $user_id, string $event, $data, bool $already_encoded = false): HttpRequestContract
    {
        $this->validate_user_id($user_id);
        return $this->triggerAsync(["#server-to-user-$user_id"], $event, $data, [], $already_encoded);
    }

    /**
     * Trigger multiple events at the same time.
     *
     * @param array $batch [optional] An array of events to send
     * @param bool $already_encoded [optional]
     *
     * @return object
     * @throws ApiErrorException Throws ApiErrorException if the Channels HTTP API responds with an error
     * @throws PusherException
     */
    public function triggerBatch(array $batch = [], bool $already_encoded = false): object
    {
        $post_value = $this->make_trigger_batch_body($batch, $already_encoded);
        $this->log('trigger POST: {post_value}', compact('post_value'));
        return $this->process_trigger_result($this->post('/batch_events', $post_value));
    }

    /**
     * Asynchronously trigger multiple events at the same time.
     *
     * @param array $batch [optional] An array of events to send
     * @param bool $already_encoded [optional]
     *
     * @return HttpRequestContract
     * @throws PusherException
     */
    public function triggerBatchAsync(array $batch = [], bool $already_encoded = false): HttpRequestContract
    {
        $post_value = $this->make_trigger_batch_body($batch, $already_encoded);
        $this->log('trigger POST: {post_value}', compact('post_value'));
        return $this->postAsync('/batch_events', $post_value);
    }

    /**
     * Terminates all connections established by the user with the given user id.
     *
     * @param string $user_id
     *
     * @throws PusherException   If $user_id is invalid
     * @throws ApiErrorException Throws ApiErrorException if the Channels HTTP API responds with an error
     *
     * @return object response body
     */
    public function terminateUserConnections(string $user_id): object
    {
        $this->validate_user_id($user_id);
        return $this->post("/users/$user_id/terminate_connections", "{}");
    }

    /**
     * Asynchronous request to terminate all connections established by the user with the given user id.
     *
     * @param string $user_id
     *
     * @throws PusherException If $user_id is invalid
     *
     * @return HttpRequestContract
     */
    public function terminateUserConnectionsAsync(string $user_id): HttpRequestContract
    {
        $this->validate_user_id($user_id);
        return $this->postAsync("/users/$user_id/terminate_connections", "{}");
    }

    /**
     * Fetch channel information for a specific channel.
     *
     * @param string $channel The name of the channel
     * @param array  $params  Additional parameters for the query e.g. $params = array( 'info' => 'connection_count' )
     *
     * @throws PusherException   If $channel is invalid
     * @throws ApiErrorException Throws ApiErrorException if the Channels HTTP API responds with an error
     * @return object
     */
    public function getChannelInfo(string $channel, array $params = []): object
    {
        $this->validate_channel($channel);
        return $this->get("/channels/$channel", $params);
    }

    /**
     * Fetch a list containing all channels.
     *
     * @param array $params Additional parameters for the query e.g. $params = array( 'info' => 'connection_count' )
     *
     * @throws ApiErrorException Throws ApiErrorException if the Channels HTTP API responds with an error
     * @throws PusherException
     * @return object
     */
    public function getChannels(array $params = []): object
    {
        $result = $this->get('/channels', $params);

        if (property_exists($result, 'channels') && is_object($result->channels)) {
            $result->channels = get_object_vars($result->channels);
        }

        return $result;
    }

    /**
     * Fetch user ids currently subscribed to a presence channel.
     *
     * @param string $channel The name of the channel
     *
     * @throws ApiErrorException Throws ApiErrorException if the Channels HTTP API responds with an error
     * @throws PusherException
     * @return object
     */
    public function getPresenceUsers(string $channel): object
    {
        return $this->get("/channels/$channel/users");
    }

    /**
     * GET arbitrary REST API resource using a synchronous http client.
     * All request signing is handled automatically.
     *
     * @param string $path        Path excluding /apps/APP_ID
     * @param array  $params      API params (see http://pusher.com/docs/rest_api)
     * @param bool   $associative When true, return the response body as an associative array, else return as an object
     *
     * @throws ApiErrorException Throws ApiErrorException if the Channels HTTP API responds with an error
     * @throws PusherException
     *
     * @return mixed See Pusher API docs
     */
    public function get(string $path, array $params = [], bool $associative = false)
    {
        $request = $this->getAsync($path, $params);
        $response = $this->executeRequest($request);

        $status = $response->status();
        if ($status !== 200) {
            throw new ApiErrorException($response->body(), $status);
        }

        try {
            return json_decode($response->body(), $associative, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new PusherException('Data decoding error.', 0, $e);
        }
    }

    /**
     * Asynchronously GET arbitrary REST API resource.
     * Returns an HttpRequestContract instance ready to be executed or pooled.
     *
     * @param string $path   Path excluding /apps/APP_ID
     * @param array  $params API params (see http://pusher.com/docs/rest_api)
     * @return HttpRequestContract
     */
    public function getAsync(string $path, array $params = []): HttpRequestContract
    {
        $basePath = str_starts_with($path, '/apps/')
            ? $path
            : $this->settings['base_path'] . (str_starts_with($path, '/') ? $path : '/' . $path);

        $signature = $this->sign($basePath, 'GET', $params);

        return $this->createRequest('GET', $basePath, $signature);
    }

    /**
     * POST arbitrary REST API resource using a synchronous http client.
     * All request signing is handled automatically.
     *
     * @param string $path        Path excluding /apps/APP_ID
     * @param mixed  $body        Request payload (see http://pusher.com/docs/rest_api)
     * @param array  $params      API params (see http://pusher.com/docs/rest_api)
     *
     * @throws ApiErrorException Throws ApiErrorException if the Channels HTTP API responds with an error
     * @throws PusherException
     *
     * @return mixed Post response body
     */
    public function post(string $path, $body, array $params = [])
    {
        $request = $this->postAsync($path, $body, $params);
        $response = $this->executeRequest($request);

        $status = $response->status();
        if ($status !== 200) {
            throw new ApiErrorException($response->body(), $status);
        }

        try {
            return json_decode($response->body(), false, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new PusherException('Data decoding error.', 0, $e);
        }
    }

    /**
     * Asynchronously POST arbitrary REST API resource.
     * Returns an HttpRequestContract instance ready to be executed or pooled.
     *
     * @param string $path        Path excluding /apps/APP_ID
     * @param mixed  $body        Request payload (see http://pusher.com/docs/rest_api)
     * @param array  $params      API params (see http://pusher.com/docs/rest_api)
     *
     * @return HttpRequestContract
     * @throws PusherException
     */
    public function postAsync(string $path, $body, array $params = []): HttpRequestContract
    {
        if (!is_string($body)) {
            try {
                $body = json_encode($body, JSON_THROW_ON_ERROR);
            } catch (\JsonException $e) {
                throw new PusherException('Data encoding error.', 0, $e);
            }
        }

        $basePath = str_starts_with($path, '/apps/')
            ? $path
            : $this->settings['base_path'] . (str_starts_with($path, '/') ? $path : "/$path");

        $params['body_md5'] = md5($body);
        $params_with_signature = $this->sign($basePath, 'POST', $params);

        return $this->createRequest('POST', $basePath, $params_with_signature, $body);
    }

    /**
     * Execute multiple Pusher requests concurrently using HttpPool.
     *
     * @param array<string|int, HttpRequestContract>|callable $requests
     * @return array<string|int, object> Array of decoded response objects keyed as provided
     * @throws ApiErrorException
     * @throws PusherException
     */
    public function pool(array|callable $requests): array
    {
        $pool = new HttpPool();
        if (is_callable($requests)) {
            $poolRequests = $requests($pool);
            if (!is_array($poolRequests)) {
                throw new PusherException('Pool callback must return an array of requests.');
            }
        } else {
            $poolRequests = $requests;
        }

        $setKey = \Closure::bind(function (HttpRequest $req, string|int $key) {
            $req->key = $key;
        }, null, HttpRequest::class);

        foreach ($poolRequests as $key => $request) {
            if ($request instanceof HttpRequest) {
                $setKey($request, $key);
            }
        }

        $rawResponses = $pool->execute($poolRequests);

        $results = [];
        foreach ($rawResponses as $key => $response) {
            $status = $response->status();
            if ($status === 0) {
                throw new ApiErrorException('HTTP request failed (status 0).', 0);
            }
            if ($status !== 200) {
                throw new ApiErrorException($response->body(), $status);
            }
            try {
                $decoded = json_decode($response->body(), false, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException $e) {
                throw new PusherException('Data decoding error.', 0, $e);
            }
            if (is_object($decoded)) {
                $decoded = $this->process_trigger_result($decoded);
            }
            $results[$key] = $decoded;
        }

        return $results;
    }

    /**
     * Creates a user authentication signature.
     *
     * @param string $socket_id
     * @param array $user_data
     *
     * @return string Json encoded authentication string.
     * @throws PusherException Throws exception if $channel is invalid or above or $socket_id is invalid
     */
    public function authenticateUser(string $socket_id, array $user_data): string
    {
        $this->validate_socket_id($socket_id);
        $this->validate_user_data($user_data);

        $serialized_user_data = json_encode($user_data, JSON_THROW_ON_ERROR);
        $signature = hash_hmac('sha256', "$socket_id::user::$serialized_user_data", $this->settings['secret'], false);
        $auth = $this->settings['auth_key'] . ':' . $signature;

        return json_encode(
            ['auth' => $auth, 'user_data' => $serialized_user_data],
            JSON_THROW_ON_ERROR
        );
    }

    /**
     * Creates a channel authorization signature.
     *
     * @param string $channel
     * @param string $socket_id
     * @param string|null $custom_data
     *
     * @return string Json encoded authentication string.
     * @throws PusherException Throws exception if $channel is invalid or above or $socket_id is invalid
     */
    public function authorizeChannel(string $channel, string $socket_id, ?string $custom_data = null): string
    {
        $this->validate_channel($channel);
        $this->validate_socket_id($socket_id);

        if ($custom_data) {
            $signature = hash_hmac('sha256', "$socket_id:$channel:$custom_data", $this->settings['secret'], false);
        } else {
            $signature = hash_hmac('sha256', "$socket_id:$channel", $this->settings['secret'], false);
        }

        $signature = ['auth' => $this->settings['auth_key'] . ':' . $signature];

        // add the custom data if it has been supplied
        if ($custom_data) {
            $signature['channel_data'] = $custom_data;
        }

        if (PusherCrypto::is_encrypted_channel($channel)) {
            if ($this->crypto !== null) {
                $signature['shared_secret'] = base64_encode($this->crypto->generate_shared_secret($channel));
            } else {
                throw new PusherException('You must specify an encryption master key to authorize an encrypted channel');
            }
        }

        try {
            $response = json_encode($signature, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        } catch (\JsonException $e) {
            throw new PusherException('Data encoding error.');
        }

        return $response;
    }

    /**
     * Convenience function for presence channel authorization.
     *
     * @param string $channel
     * @param string $socket_id
     * @param string $user_id
     * @param mixed $user_info
     *
     * @return string
     * @throws PusherException Throws exception if $channel is invalid or above or $socket_id is invalid
     */
    public function authorizePresenceChannel(string $channel, string $socket_id, string $user_id, $user_info = null): string
    {
        $user_data = ['user_id' => $user_id];
        if ($user_info) {
            $user_data['user_info'] = $user_info;
        }

        try {
            return $this->authorizeChannel($channel, $socket_id, json_encode($user_data, JSON_THROW_ON_ERROR));
        } catch (\JsonException $e) {
            throw new PusherException('Data encoding error.');
        }
    }

    /**
     * Verify that a webhook actually came from Pusher, decrypts any encrypted events, and marshals them into a PHP object.
     *
     * @param array  $headers An array of headers from the request
     * @param string $body    The body of the request
     *
     * @throws PusherException
     * @return object Webhook marshalled object with time_ms and events
     */
    public function webhook(array $headers, string $body): object
    {
        $this->verifySignature($headers, $body);

        $decoded_events = [];
        try {
            $decoded_json = json_decode($body, false, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            $this->log('Unable to decrypt webhook event payload.', [], 'warning');
            throw new PusherException('Data encoding error.', 0, $e);
        }

        foreach ($decoded_json->events as $event) {
            if (PusherCrypto::is_encrypted_channel($event->channel)) {
                if ($this->crypto !== null) {
                    $decryptedEvent = $this->crypto->decrypt_event($event);

                    if ($decryptedEvent === false) {
                        $this->log('Unable to decrypt webhook event payload. Wrong key? Ignoring.', [], 'warning');
                        continue;
                    }
                    $decoded_events[] = $decryptedEvent;
                } else {
                    $this->log('Got an encrypted webhook event payload, but no master key specified. Ignoring.', [], 'warning');
                }
            } else {
                $decoded_events[] = $event;
            }
        }
        return new Webhook($decoded_json->time_ms, $decoded_events);
    }

    /**
     * Verify that a given Pusher Signature is valid.
     *
     * @param array  $headers An array of headers from the request
     * @param string $body    The body of the request
     *
     * @throws PusherException if signature is incorrect.
     */
    public function verifySignature(array $headers, string $body): void
    {
        $x_pusher_key = $headers['X-Pusher-Key'] ?? $headers['x-pusher-key'] ?? null;
        $x_pusher_signature = $headers['X-Pusher-Signature'] ?? $headers['x-pusher-signature'] ?? null;
        if ($x_pusher_key === $this->settings['auth_key']) {
            $expected = hash_hmac('sha256', $body, $this->settings['secret']);
            if ($expected === $x_pusher_signature) {
                return;
            }
        }

        throw new PusherException(sprintf('Received WebHook with invalid signature: got %s.', (string) $x_pusher_signature));
    }

    /**
     * Returns an event represented by an associative array to be used in creating events and batch_events requests
     *
     * @param array $channels An array of channel names to publish the event on.
     * @param string $event
     * @param mixed $data Event data
     * @param array $params [optional]
     * @param string|null $info [optional]
     * @param bool $already_encoded [optional]
     *
     * @throws PusherException
     * @return array Event associative array
     */
    private function make_event(array $channels, string $event, $data, array $params = [], ?string $info = null, bool $already_encoded = false): array
    {
        $has_encrypted_channel = false;
        foreach ($channels as $chan) {
            if (PusherCrypto::is_encrypted_channel($chan)) {
                $has_encrypted_channel = true;
                break;
            }
        }

        if ($has_encrypted_channel) {
            if (PusherCrypto::has_mixed_channels($channels)) {
                throw new PusherException('You cannot trigger to encrypted and non-encrypted channels at the same time');
            } else {
                try {
                    $data_encoded = $this->crypto->encrypt_payload(
                        $channels[0],
                        $already_encoded ? $data : json_encode($data, JSON_THROW_ON_ERROR)
                    );
                } catch (\JsonException $e) {
                    throw new PusherException('Data encoding error.');
                }
            }
        } else {
            try {
                $data_encoded = $already_encoded ? $data : json_encode($data, JSON_THROW_ON_ERROR);
            } catch (\JsonException $e) {
                throw new PusherException('Data encoding error.');
            }
        }

        // json_encode might return false on failure
        if (!$data_encoded) {
            $this->log('Failed to perform json_encode on the the provided data: {error}', [
                'error' => print_r($data, true),
            ], 'error');
        }

        $post_params = [];
        $post_params['name'] = $event;
        $post_params['data'] = $data_encoded;
        $channel_values = array_values($channels);
        if (count($channel_values) === 1) {
            $post_params['channel'] = $channel_values[0];
        } else {
            $post_params['channels'] = $channel_values;
        }
        if (!is_null($info)) {
            $post_params['info'] = $info;
        }

        return [...$post_params, ...$params];
    }

    /**
     * Returns the body of a trigger events request serialized as string ready to be sent in a request
     *
     * @param array|string $channels A channel name or an array of channel names to publish the event on.
     * @param string $event
     * @param mixed $data Event data
     * @param array $params [optional]
     * @param bool $already_encoded [optional]
     *
     * @throws PusherException
     * @return string
     */
    private function make_trigger_body($channels, string $event, $data, array $params = [], bool $already_encoded = false): string
    {
        if (is_string($channels) === true) {
            $channels = [$channels];
        }

        $this->validate_channels($channels);
        if (isset($params['socket_id'])) {
            $this->validate_socket_id($params['socket_id']);
        }

        try {
            return json_encode(
                $this->make_event($channels, $event, $data, $params, null, $already_encoded),
                JSON_THROW_ON_ERROR
            );
        } catch (\JsonException $e) {
            throw new PusherException('Data encoding error.');
        }
    }

    /**
     * Returns the body of a trigger batch events request serialized as string ready to be sent in a request
     *
     * @param array $batch An array of events to send
     * @param bool $already_encoded [optional]
     *
     * @throws PusherException
     * @return string
     */
    private function make_trigger_batch_body(array $batch = [], bool $already_encoded = false): string
    {
        foreach ($batch as $key => $event) {
            $this->validate_channel($event['channel']);
            if (isset($event['socket_id'])) {
                $this->validate_socket_id($event['socket_id']);
                $batch[$key] = $this->make_event([$event['channel']], $event['name'], $event['data'], ['socket_id' => $event['socket_id']], $event['info'] ?? null, $already_encoded);
            } else {
                $batch[$key] = $this->make_event([$event['channel']], $event['name'], $event['data'], [], $event['info'] ?? null, $already_encoded);
            }
        }

        try {
            return json_encode(['batch' => $batch], JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new PusherException('Data encoding error.');
        }
    }

    /**
     * Mutates the result of a trigger (batch) request to replace channel names with channel objects
     *
     * @param object $result result of the trigger (batch) request
     * @return object
     */
    private function process_trigger_result(object $result): object
    {
        if (property_exists($result, 'channels') && is_object($result->channels)) {
            $result->channels = get_object_vars($result->channels);
        }

        return $result;
    }

    /**
     * Validate user data structure.
     *
     * @param array|null $user_data
     * @throws PusherException
     */
    private function validate_user_data(?array $user_data): void
    {
        if ($user_data === null) {
            throw new PusherException('user_data is null');
        }
        if (!array_key_exists('id', $user_data)) {
            throw new PusherException('user_data has no id field');
        }
        $this->validate_user_id($user_data['id']);
    }
}
