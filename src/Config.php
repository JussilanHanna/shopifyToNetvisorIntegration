<?php
declare(strict_types=1);

namespace Demo;

use Demo\Netvisor\NetvisorAuth;
use Dotenv\Dotenv;

final class Config
{
    public function __construct(
        public readonly string $stateFile,

        public readonly string $shopifyShopDomain,
        public readonly string $shopifyAccessToken,
        public readonly string $shopifyApiVersion,

        public readonly string $netvisorBaseUrl,
        public readonly NetvisorAuth $netvisorAuth,

        public readonly int $httpTimeout,
        public readonly int $httpConnectTimeout,
        public readonly ?string $httpProxy,

        // mapping defaults (demo)
        public readonly string $netvisorDefaultCustomerCode,
        public readonly int $netvisorDefaultPaymentTermDays,
        public readonly float $netvisorDefaultVatPercent,
        public readonly string $netvisorDefaultProductCode,
    ) {}

    public static function fromEnv(): self
    {
        $root = dirname(__DIR__); 

        if (file_exists($root . '/.env')) {
            $dotenv = Dotenv::createImmutable($root);
            // load into $_ENV / $_SERVER (getenv() may be unreliable on Windows)
            $dotenv->load();
        }

        $env = static function (string $key, $default = null) {
            $v = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);
            return ($v === false || $v === null || $v === '') ? $default : $v;
        };

        $stateFile = (string) $env('STATE_FILE', $root . '/state.json');

        $shopDomain = (string) $env('SHOPIFY_SHOP_DOMAIN', '');
        $shopToken  = (string) $env('SHOPIFY_ACCESS_TOKEN', '');
        $apiVersion = (string) $env('SHOPIFY_API_VERSION', '2026-01');

        $netvisorBaseUrl = (string) $env('NETVISOR_BASE_URL', 'https://isvapi.netvisor.fi');

        $sender = (string) $env('NETVISOR_SENDER', '');
        $partnerId = (string) $env('NETVISOR_PARTNER_ID', '');
        $customerId = (string) $env('NETVISOR_CUSTOMER_ID', '');
        $token = (string) $env('NETVISOR_TOKEN', '');
        $macKey = (string) $env('NETVISOR_MAC_KEY', '');
        $language = (string) $env('NETVISOR_LANGUAGE', 'FI');
        $organizationId = (string) $env('NETVISOR_ORG_ID', '');
        $useHttpStatusCodes = (bool) ((int) $env('NETVISOR_USE_HTTP_STATUS', 1));
        $macAlgorithm = (string) $env('NETVISOR_MAC_ALGO', 'HMACSHA256');

        $missing = [];
        if ($shopDomain === '') $missing[] = 'SHOPIFY_SHOP_DOMAIN';
        if ($shopToken === '')  $missing[] = 'SHOPIFY_ACCESS_TOKEN';
        if ($sender === '')     $missing[] = 'NETVISOR_SENDER';
        if ($partnerId === '')  $missing[] = 'NETVISOR_PARTNER_ID';
        if ($customerId === '') $missing[] = 'NETVISOR_CUSTOMER_ID';
        if ($token === '')      $missing[] = 'NETVISOR_TOKEN';
        if ($macKey === '')     $missing[] = 'NETVISOR_MAC_KEY';
        if ($missing) {
            throw new \InvalidArgumentException('Missing env vars: ' . implode(', ', $missing));
        }

        $auth = new NetvisorAuth(
            sender: $sender,
            partnerId: $partnerId,
            customerId: $customerId,
            token: $token,
            macKey: $macKey,
            language: $language,
            organizationId: $organizationId,
            useHttpStatusCodes: $useHttpStatusCodes,
            macAlgorithm: $macAlgorithm
        );

        $httpTimeout = (int) $env('HTTP_TIMEOUT', 20);
        $httpConnectTimeout = (int) $env('HTTP_CONNECT_TIMEOUT', 10);
        $httpProxy = $env('HTTP_PROXY', null);
        $httpProxy = ($httpProxy === '') ? null : $httpProxy;

        return new self(
            stateFile: $stateFile,
            shopifyShopDomain: $shopDomain,
            shopifyAccessToken: $shopToken,
            shopifyApiVersion: $apiVersion,
            netvisorBaseUrl: $netvisorBaseUrl,
            netvisorAuth: $auth,
            httpTimeout: $httpTimeout,
            httpConnectTimeout: $httpConnectTimeout,
            httpProxy: $httpProxy,

            netvisorDefaultCustomerCode: (string) $env('NETVISOR_DEFAULT_CUSTOMER_CODE', 'CASH'),
            netvisorDefaultPaymentTermDays: (int) $env('NETVISOR_DEFAULT_PAYMENT_TERM', 14),
            netvisorDefaultVatPercent: (float) $env('NETVISOR_DEFAULT_VAT_PERCENT', 25.5),
            netvisorDefaultProductCode: (string) $env('NETVISOR_DEFAULT_PRODUCT_CODE', 'SHOPIFY_ITEM'),
        );
    }
}
