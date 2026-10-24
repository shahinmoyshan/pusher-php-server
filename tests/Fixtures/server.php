<?php

// Mock Pusher HTTP Channels Server for tests

$uri = $_SERVER['REQUEST_URI'] ?? '/';
$path = parse_url($uri, PHP_URL_PATH);
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$query = [];
if (isset($_SERVER['QUERY_STRING'])) {
    parse_str($_SERVER['QUERY_STRING'], $query);
}

$rawInput = file_get_contents('php://input');

// Verify the exact method, URL path and raw body received by the native HTTP client.
$signature = $query['auth_signature'] ?? '';
unset($query['auth_signature']);
ksort($query);
$parts = [];
foreach ($query as $key => $value) {
    $parts[] = "$key=$value";
}
$expected = hash_hmac('sha256', "$method\n$path\n" . implode('&', $parts), 'test_secret');
if (
    ($query['auth_key'] ?? '') !== 'test_key' || !hash_equals($expected, $signature)
    || ($method === 'POST' && ($query['body_md5'] ?? '') !== md5($rawInput))
) {
    http_response_code(401);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Signature or body mismatch']);
    exit;
}

if (str_starts_with($path, '/proxy/')) {
    $path = substr($path, 6);
}

if ($path === '/apps/123/echo') {
    header('Content-Type: application/json');
    echo json_encode(['method' => $method, 'body' => $rawInput, 'headers' => getallheaders(), 'query' => $query]);
    exit;
}

if ($path === '/apps/123/redirect') {
    header('Location: /apps/123/echo', true, 307);
    exit;
}

if ($path === '/apps/123/incomplete') {
    $counter = sys_get_temp_dir() . '/pusher-test-' . $_SERVER['SERVER_PORT'] . '.count';
    file_put_contents($counter, '1', FILE_APPEND);
    header('Content-Length: 1000');
    header('Connection: close');
    echo '{}';
    exit;
}

if ($path === '/apps/123/channels' && $method === 'GET') {
    http_response_code(200);
    header('Content-Type: application/json');
    echo json_encode([
        'channels' => [
            'presence-lobby' => ['user_count' => 8],
            'public-chat' => ['user_count' => 20],
        ]
    ]);
    exit;
}

if ($path === '/apps/123/channels/presence-lobby' && $method === 'GET') {
    http_response_code(200);
    header('Content-Type: application/json');
    echo json_encode([
        'occupied' => true,
        'user_count' => 8,
        'subscription_count' => 8,
    ]);
    exit;
}

if ($path === '/apps/123/channels/presence-lobby/users' && $method === 'GET') {
    http_response_code(200);
    header('Content-Type: application/json');
    echo json_encode([
        'users' => [
            ['id' => '101'],
            ['id' => '102'],
        ]
    ]);
    exit;
}

if ($path === '/apps/123/events' && $method === 'POST') {
    if (isset($query['body_md5']) && $query['body_md5'] !== md5($rawInput)) {
        http_response_code(400);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Invalid body MD5']);
        exit;
    }

    http_response_code(200);
    header('Content-Type: application/json');
    echo json_encode((object) [
        'channels' => (object) [
            'my-channel' => (object) ['subscription_count' => 1],
        ]
    ]);
    exit;
}

if ($path === '/apps/123/batch_events' && $method === 'POST') {
    http_response_code(200);
    header('Content-Type: application/json');
    echo json_encode((object) [
        'batch' => []
    ]);
    exit;
}

if (str_ends_with($path, '/terminate_connections') && $method === 'POST') {
    http_response_code(200);
    header('Content-Type: application/json');
    echo json_encode((object) ['status' => 'terminated']);
    exit;
}

if ($path === '/apps/123/error_endpoint') {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Forbidden access']);
    exit;
}

http_response_code(404);
header('Content-Type: application/json');
echo json_encode(['error' => 'Endpoint not found']);
