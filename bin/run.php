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

// Shopify client (uses Config + StateStore for token handling)
$shopify = new ShopifyClient(
    http: $http,
    logger: $logger,
    config: $config,
    state: $state
);

// Netvisor client (supports live/mock modes)
$netvisor = new NetvisorClient(
    http: $http,
    logger: $logger,
    baseUrl: $config->netvisorBaseUrl,
    auth: $config->netvisorAuth,
    mode: $config->netvisorMode,
    outDir: $config->netvisorOutDir,
    debugAuth: $config->netvisorDebugAuth
);

// Mapper
$mapper = new NetvisorSalesOrderMapper($logger, $config);

// Orchestration
$svc = new OrderSyncService(
    shopify: $shopify,
    netvisor: $netvisor,
    mapper: $mapper,
    state: $state,
    logger: $logger
);

$svc->run();