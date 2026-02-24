#!/usr/bin/env php
<?php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Dotenv\Dotenv;

Dotenv::createImmutable(dirname(__DIR__))->safeLoad();

$shop = $_ENV['SHOPIFY_SHOP_DOMAIN'] ?? '';
$clientId = $_ENV['SHOPIFY_CLIENT_ID'] ?? '';
$clientSecret = $_ENV['SHOPIFY_CLIENT_SECRET'] ?? '';

if ($shop === '' || $clientId === '' || $clientSecret === '') {
    fwrite(STDERR, "Missing env vars. Need SHOPIFY_SHOP_DOMAIN, SHOPIFY_CLIENT_ID, SHOPIFY_CLIENT_SECRET\n");
    exit(1);
}

$url = "https://{$shop}/admin/oauth/access_token";

$payload = json_encode([
    'client_id' => $clientId,
    'client_secret' => $clientSecret,
    'grant_type' => 'client_credentials',
], JSON_THROW_ON_ERROR);

$opts = [
    'http' => [
        'method'  => 'POST',
        'header'  => "Content-Type: application/json\r\n",
        'content' => $payload,
        'ignore_errors' => true,
    ],
];

$context = stream_context_create($opts);
$response = file_get_contents($url, false, $context);

$status = 0;
if (isset($http_response_header[0])) {
    preg_match('/\d{3}/', $http_response_header[0], $m);
    $status = (int)($m[0] ?? 0);
}

echo "HTTP Status: {$status}\n";

if ($status !== 200) {
    echo "Response: {$response}\n";
    echo "Token fetch failed\n";
    exit(1);
}

$data = json_decode($response, true);
$token = $data['access_token'] ?? '';

if (!is_string($token) || $token === '') {
    echo "Response: {$response}\n";
    echo "Token missing in response\n";
    exit(1);
}

$preview = substr($token, 0, 10) . '...' . substr($token, -6);
echo "Access token: {$preview}\n";