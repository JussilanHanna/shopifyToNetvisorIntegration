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
            Dotenv::createImmutable($root)->safeLoad();
        }

        $stateFile = getenv('STATE_FILE') ?: ($root . '/state.json');

        $shopDomain = (string)(getenv('SHOPIFY_SHOP_DOMAIN') ?: '');
        $shopToken  = (string)(getenv('SHOPIFY_ACCESS_TOKEN') ?: '');
        $apiVersion = (string)(getenv('SHOPIFY_API_VERSION') ?: '2026-01');

        $netvisorBaseUrl = (string)(getenv('NETVISOR_BASE_URL') ?: 'https://isvapi.netvisor.fi');

        $sender = (string)(getenv('NETVISOR_SENDER') ?: '');
        $partnerId = (string)(getenv('NETVISOR_PARTNER_ID') ?: '');
        $customerId = (string)(getenv('NETVISOR_CUSTOMER_ID') ?: '');
        $token = (string)(getenv('NETVISOR_TOKEN') ?: '');
        $macKey = (string)(getenv('NETVISOR_MAC_KEY') ?: '');
        $language = (string)(getenv('NETVISOR_LANGUAGE') ?: 'FI');
        $organizationId = (string)(getenv('NETVISOR_ORG_ID') ?: '');
        $useHttpStatusCodes = (bool)((int)(getenv('NETVISOR_USE_HTTP_STATUS') ?: 1));
        $macAlgorithm = (string)(getenv('NETVISOR_MAC_ALGO') ?: 'HMACSHA256');

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

        $httpTimeout = (int)(getenv('HTTP_TIMEOUT') ?: 20);
        $httpConnectTimeout = (int)(getenv('HTTP_CONNECT_TIMEOUT') ?: 10);
        $httpProxy = getenv('HTTP_PROXY') ?: null;

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

            netvisorDefaultCustomerCode: (string)(getenv('NETVISOR_DEFAULT_CUSTOMER_CODE') ?: 'CASH'),
            netvisorDefaultPaymentTermDays: (int)(getenv('NETVISOR_DEFAULT_PAYMENT_TERM') ?: 14),
            netvisorDefaultVatPercent: (float)(getenv('NETVISOR_DEFAULT_VAT_PERCENT') ?: 25.5),
            netvisorDefaultProductCode: (string)(getenv('NETVISOR_DEFAULT_PRODUCT_CODE') ?: 'SHOPIFY_ITEM'),
        );
    }
}
