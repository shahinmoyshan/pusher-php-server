# Pusher PHP Server for TinyMVC / Spark

A lightweight, modern Pusher HTTP REST API client fine-tuned for the **TinyMVC / Spark PHP Framework** (`tinymvc/tinycore`).

[![PHP Version](https://img.shields.io/badge/php-%3E%3D8.2-blue.svg)](https://php.net)
[![License: MIT](https://img.shields.io/badge/License-MIT-green.svg)](LICENSE)
[![Tests](https://img.shields.io/badge/tests-59%20passed-brightgreen.svg)](tests/)

---

## Origin & Credits

This package is a modernized, framework-optimized fork derived from the official [`pusher/pusher-http-php`](https://github.com/pusher/pusher-http-php).

### Why this package?

* **Native Spark HTTP Client**: Replaced `guzzlehttp/guzzle`, `guzzlehttp/psr7`, `psr/http-client`, and `psr/http-factory` entirely with TinyMVC Spark's high-performance native HTTP Client (`Spark\Http\Client` / `tinycore`). No heavy third-party Guzzle dependencies.
* **Pruned Deprecations**: Completely removed legacy PHP 5/7 deprecated aliases (`make_request()`, `make_batch_request()`, `socket_auth()`, `socketAuth()`, `presence_auth()`, `presenceAuth()`, `ensure_valid_signature()`). Only clean, canonical modern API methods remain.
* **Modern PHP 8.2+ Architecture**: Fully typed signatures, constructor property promotion, union & nullable types, and strict return types across the codebase.
* **Concurrent HTTP Pooling**: First-class async API (`triggerAsync()`, `triggerBatchAsync()`, `sendToUserAsync()`, `terminateUserConnectionsAsync()`, `getAsync()`, `postAsync()`) with concurrent execution powered directly by `Spark\Http\Client\HttpPool`.
* **TinyMVC Relay Native**: Tailored to work seamlessly both with Pusher Channels cloud service and with [TinyMVC Relay (`tinymvc/relay`)](https://github.com/tinymvc/relay)—the high-performance, Pusher-compatible WebSocket server for TinyMVC Spark.

---

## Installation

```bash
composer require shahinmoyshan/pusher-php-server
```

> **Requirements:** PHP 8.2 or higher, `ext-curl`, `ext-json`, and `tinymvc/tinycore` (^4.0).

---

## Quick Start

### 1. Initialize the Client

```php
use Pusher\Pusher;

$pusher = new Pusher(
    'YOUR_APP_KEY',
    'YOUR_APP_SECRET',
    'YOUR_APP_ID',
    [
        'cluster' => 'mt1',
        'useTLS'  => true,
    ]
);
```

#### Connecting to a Local WebSocket Server (TinyMVC Relay)

When pointing to a custom Pusher-compatible daemon like **TinyMVC Relay**:

```php
$pusher = new Pusher('relay-key', 'relay-secret', 'relay-app', [
    'host'    => '127.0.0.1',
    'port'    => 8080,
    'scheme'  => 'http',
    'useTLS'  => false,
    'timeout' => 5,
]);
```

---

### 2. Triggering Events

#### Trigger an Event on One or More Channels

```php
// Single channel
$pusher->trigger('chat-room.1', 'message.new', [
    'user'    => 'Alice',
    'message' => 'Hello everyone!',
]);

// Multiple channels (up to 100 channels)
$pusher->trigger(['news', 'announcements'], 'headline', [
    'title' => 'Breaking News',
]);

// Exclude the sender socket from receiving the event
$pusher->trigger('chat-room.1', 'message.new', $payload, [
    'socket_id' => '1234.5678',
]);
```

#### Batch Trigger (Multiple Channels & Events at Once)

Publish up to 10 events across different channels in a single HTTP request:

```php
$pusher->triggerBatch([
    [
        'channel' => 'chat-room.1',
        'name'    => 'message.new',
        'data'    => ['text' => 'Hello Alice'],
    ],
    [
        'channel' => 'notifications.user.42',
        'name'    => 'alert',
        'data'    => ['unread_count' => 3],
    ],
]);
```

#### User Events & Connection Termination

```php
// Send an event to all connections authenticated for a specific user ID
$pusher->sendToUser('user-42', 'account.updated', ['status' => 'active']);

// Terminate all open WebSocket connections of a specific user
$pusher->terminateUserConnections('user-42');
```

---

### 3. Channel Authorization (Private & Presence Channels)

Handle frontend authorization requests (e.g., from `pusher-js` to `/broadcasting/auth`):

#### Private Channel Authorization

```php
$channel  = $_POST['channel_name']; // e.g. 'private-orders.42'
$socketId = $_POST['socket_id'];    // e.g. '1234.5678'

// Returns JSON string: {"auth":"KEY:SIGNATURE"}
echo $pusher->authorizeChannel($channel, $socketId);
```

#### Presence Channel Authorization

```php
$channel  = $_POST['channel_name']; // e.g. 'presence-workspace.team'
$socketId = $_POST['socket_id'];
$userId   = (string) $currentUser->id;

$userInfo = [
    'name'   => $currentUser->name,
    'avatar' => $currentUser->avatar_url,
];

// Returns JSON string: {"auth":"KEY:SIGNATURE","channel_data":"{...}"}
echo $pusher->authorizePresenceChannel($channel, $socketId, $userId, $userInfo);
```

---

### 4. Async Requests & Concurrent HTTP Pooling

`shahinmoyshan/pusher-php-server` returns Spark HTTP request instances (`Spark\Http\Client\Contracts\HttpRequestContract`) for all async methods, allowing non-blocking dispatch and concurrent multi-request pooling via `HttpPool`:

```php
// 1. Prepare async requests
$req1 = $pusher->triggerAsync('feed', 'item.published', ['id' => 101]);
$req2 = $pusher->triggerAsync('metrics', 'visit.recorded', ['page' => '/home']);
$req3 = $pusher->sendToUserAsync('admin-1', 'alert', ['type' => 'high-load']);

// 2. Dispatch all requests concurrently in parallel
$responses = $pusher->pool([
    'feed'    => $req1,
    'metrics' => $req2,
    'alert'   => $req3,
]);

// Or execute a single async request when ready
$response = $req1->send();
```

---

### 5. Channel & Presence Queries

Query the Pusher Channels / Relay HTTP API for real-time information:

```php
// List active channels
$channels = $pusher->getChannels([
    'filter_by_prefix' => 'presence-',
    'info'             => 'user_count',
]);

// Get information about a single channel
$info = $pusher->getChannelInfo('presence-chat.general', [
    'info' => 'user_count,subscription_count',
]);

// Get active user IDs subscribed to a presence channel
$users = $pusher->getPresenceUsers('presence-chat.general');
```

---

### 6. End-to-End Encrypted Channels

Native support for encrypted private channels (`private-encrypted-*`) using AES-256-GCM via `PusherCrypto`:

```php
$pusher = new Pusher('KEY', 'SECRET', 'APP_ID', [
    'cluster'                     => 'mt1',
    'useTLS'                      => true,
    'encryption_master_key_base64' => 'c29tZS0zMi1ieXRlLWtleS1pbi1iYXNlNjQ=',
]);

// Payload is automatically encrypted before transmission
$pusher->trigger('private-encrypted-vault', 'secret.event', [
    'sensitive_token' => 'abc-123',
]);
```

---

### 7. Webhook Verification & Decryption

Verify incoming Pusher webhooks and automatically decrypt events if using encrypted channels:

```php
$headers = getallheaders();
$body    = file_get_contents('php://input');

// Verify signature only (throws PusherException if invalid)
$pusher->verifySignature($headers, $body);

// Verify and unmarshal webhook payload into an object with decrypted events
$webhook = $pusher->webhook($headers, $body);

foreach ($webhook->events as $event) {
    // $event->name, $event->channel, $event->data
}
```

---

### 8. Singleton Instance Manager (`PusherInstance`)

For applications wanting global singleton access:

```php
use Pusher\PusherInstance;

// Configure once at application boot
PusherInstance::configure('APP_KEY', 'APP_SECRET', 'APP_ID', [
    'cluster' => 'mt1',
]);

// Access anywhere in your codebase
$pusher = PusherInstance::get_pusher();
$pusher->trigger('news', 'breaking', ['title' => 'Hello']);
```

---

## Configuration Options

| Option | Type | Default | Description |
| :--- | :--- | :--- | :--- |
| `scheme` | `string` | `'http'` | Protocol scheme (`'http'` or `'https'`). Set to `'https'` when `useTLS` is true. |
| `host` | `string` | `null` | Custom server host (e.g. `'127.0.0.1'`). Overrides `cluster`. Protocol prefixes are automatically stripped. |
| `port` | `int` | `80` / `443` | Port for the HTTP API (default 80 for HTTP, 443 for HTTPS). |
| `timeout` | `int` | `30` | HTTP request timeout in seconds. |
| `useTLS` | `bool` | `true` | If true, automatically sets scheme to `'https'` and port to 443. |
| `cluster` | `string` | `null` | Pusher cloud cluster (e.g. `'mt1'`, `'eu'`, `'ap1'`). Defaults to `api-<cluster>.pusher.com`. |
| `encryption_master_key_base64` | `string` | `null` | 32-byte master key encoded as base64 for End-to-End encrypted channels. |
| `debug` | `bool` | `false` | Enable cURL request/response debugging. |

---

## Running Tests

The test suite runs with zero external dependencies using the built-in test runner:

```bash
composer test
# or
php tests/run.php
```

All 59 unit, feature, crypto, and mock HTTP pool tests are fully covered:

```
Tests:    59 passed (161 assertions)
Duration: ~6.0s
```

---

## License

This package is open-sourced software licensed under the [MIT license](LICENSE).
Credit to the original Pusher team for the initial architecture in [`pusher/pusher-http-php`](https://github.com/pusher/pusher-http-php).
Optimized and maintained by [Shahin Moyshan](https://github.com/shahinmoyshan) for the TinyMVC / Spark ecosystem.
