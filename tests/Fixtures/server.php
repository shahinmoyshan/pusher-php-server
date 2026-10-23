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

// Verify auth query parameters exist on all requests
if (!isset($query['auth_key']) || !isset($query['auth_signature'])) {
    http_response_code(401);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Missing authentication parameters']);
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
