#!/usr/bin/env php
<?php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Demo\Config;
use Demo\Logger;
use Demo\StateStore;
use Demo\Http\HttpClientFactory;
use Demo\Shopify\ShopifyClient;
use Demo\Netvisor\NetvisorClient;
use Demo\Mapping\NetvisorSalesOrderMapper;
use Demo\Integration\OrderSyncService;

$logger = new Logger();

try {
    $config = Config::fromEnv();
} catch (Throwable $e) {
    $logger->error('Configuration error', ['error' => $e->getMessage()]);
    exit(1);
}

$state = new StateStore($config->stateFile, $logger);
$http = HttpClientFactory::create($config);

$shopify = new ShopifyClient($http, $logger, $config->shopifyShopDomain, $config->shopifyAccessToken, $config->shopifyApiVersion);
$netvisor = new NetvisorClient($http, $logger, $config->netvisorBaseUrl, $config->netvisorAuth);

$mapper = new NetvisorSalesOrderMapper($logger, $config);

$svc = new OrderSyncService($shopify, $netvisor, $mapper, $state, $logger);
$svc->run();
