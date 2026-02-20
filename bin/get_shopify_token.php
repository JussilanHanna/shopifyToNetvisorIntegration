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

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
    CURLOPT_POSTFIELDS => $payload,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HEADER => true,
]);

$raw = curl_exec($ch);
if ($raw === false) {
    fwrite(STDERR, "cURL error: " . curl_error($ch) . "\n");
    exit(1);
}

$status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
$body = substr($raw, $headerSize);

echo "HTTP Status: {$status}\n";

if ($status !== 200) {
    echo "Response: {$body}\n";
    echo "Token fetch failed\n";
    exit(1);
}

$data = json_decode($body, true);
$token = $data['access_token'] ?? '';
$expiresIn = (int)($data['expires_in'] ?? 0);

if (!is_string($token) || $token === '') {
    echo "Response: {$body}\n";
    echo "Token missing in response\n";
    exit(1);
}

// Print only part of token (don’t leak secrets into logs)
$preview = substr($token, 0, 10) . '...' . substr($token, -6);
echo "Access token: {$preview}\n";
if ($expiresIn > 0) {
    echo "Expires in: {$expiresIn} seconds\n";
}