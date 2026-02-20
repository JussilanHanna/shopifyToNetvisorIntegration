<?php
declare(strict_types=1);

namespace Demo;

use Demo\Netvisor\NetvisorAuth;
use Dotenv\Dotenv;

final class Config
{
    public function __construct(
        public readonly string $stateFile,

        // Shopify
        public readonly string $shopifyShopDomain,
        public readonly string $shopifyApiVersion,
        public readonly string $shopifyAccessToken,
        public readonly ?string $shopifyClientId,
        public readonly ?string $shopifyClientSecret,

        // Netvisor
        public readonly string $netvisorBaseUrl,
        public readonly NetvisorAuth $netvisorAuth,

        // Netvisor demo/mock
        public readonly string $netvisorMode,      // 'live' | 'mock'
        public readonly string $netvisorOutDir,    // esim. ./out/netvisor
        public readonly bool $netvisorDebugAuth,   // true/false

        // HTTP
        public readonly int $httpTimeout,
        public readonly int $httpConnectTimeout,
        public readonly ?string $httpProxy,

        // mapping defaults (demo)
        public readonly string $netvisorDefaultCustomerCode,
        public readonly int $netvisorDefaultPaymentTermDays,
        public readonly float $netvisorDefaultVatPercent,
        public readonly string $netvisorDefaultVatCode,
        public readonly string $netvisorDefaultProductCode,
    ) {}

    public static function fromEnv(): self
    {
        $root = dirname(__DIR__);

        if (file_exists($root . '/.env')) {
            $dotenv = Dotenv::createImmutable($root);
            // Load into $_ENV / $_SERVER (getenv on Windows can be unreliable)
            $dotenv->load();
        }

        $env = static function (string $key, $default = null) {
            $v = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);
            if ($v === false || $v === null) return $default;
            if (is_string($v) && trim($v) === '') return $default;
            return $v;
        };

        $stateFile = (string) $env('STATE_FILE', $root . '/state.json');

        // Shopify
        $shopDomain   = (string) $env('SHOPIFY_SHOP_DOMAIN', '');
        $apiVersion   = (string) $env('SHOPIFY_API_VERSION', '2026-01');
        $accessToken  = (string) $env('SHOPIFY_ACCESS_TOKEN', '');
        $clientId     = $env('SHOPIFY_CLIENT_ID', null);
        $clientSecret = $env('SHOPIFY_CLIENT_SECRET', null);

        $clientId = is_string($clientId) && $clientId !== '' ? $clientId : null;
        $clientSecret = is_string($clientSecret) && $clientSecret !== '' ? $clientSecret : null;

        // Netvisor
        $netvisorBaseUrl = (string) $env('NETVISOR_BASE_URL', 'https://isvapi.netvisor.fi');

        // mode: live|mock
        $netvisorMode = strtolower((string) $env('NETVISOR_MODE', 'mock'));
        if (!in_array($netvisorMode, ['live', 'mock'], true)) {
            $netvisorMode = 'mock';
        }

        $netvisorOutDir = (string) $env('NETVISOR_OUT_DIR', $root . '/out/netvisor');

        // safe default: 0
        $netvisorDebugAuth = ((int) $env('NETVISOR_DEBUG_AUTH', 0)) === 1;

        // Netvisor auth (required only for live)
        $sender         = (string) $env('NETVISOR_SENDER', '');
        $partnerId      = (string) $env('NETVISOR_PARTNER_ID', '');
        $customerId     = (string) $env('NETVISOR_CUSTOMER_ID', '');
        $token          = (string) $env('NETVISOR_TOKEN', '');
        $macKey         = (string) $env('NETVISOR_MAC_KEY', '');
        $language       = (string) $env('NETVISOR_LANGUAGE', 'FI');
        $organizationId = (string) $env('NETVISOR_ORG_ID', '');
        $useHttpStatusCodes = (bool) ((int) $env('NETVISOR_USE_HTTP_STATUS', 1));
        $macAlgorithm   = (string) $env('NETVISOR_MAC_ALGO', 'HMACSHA256');

        // HTTP
        $httpTimeout        = (int) $env('HTTP_TIMEOUT', 20);
        $httpConnectTimeout = (int) $env('HTTP_CONNECT_TIMEOUT', 10);
        $httpProxy          = $env('HTTP_PROXY', null);
        $httpProxy          = (is_string($httpProxy) && $httpProxy !== '') ? $httpProxy : null;

        // mapping defaults
        $defaultCustomerCode = (string) $env('NETVISOR_DEFAULT_CUSTOMER_CODE', 'CASH');
        $defaultPaymentTerm  = (int) $env('NETVISOR_DEFAULT_PAYMENT_TERM', 14);
        $defaultVatPercent   = (float) $env('NETVISOR_DEFAULT_VAT_PERCENT', 25.5);
        $defaultVatCode      = (string) $env('NETVISOR_DEFAULT_VAT_CODE', 'KOMY');
        $defaultProductCode  = (string) $env('NETVISOR_DEFAULT_PRODUCT_CODE', 'SHOPIFY_ITEM');

        // Validate required
        $missing = [];

        if ($shopDomain === '') $missing[] = 'SHOPIFY_SHOP_DOMAIN';

        // Shopify auth: require either access token OR client credentials
        $hasAccessToken = $accessToken !== '';
        $hasClientCreds = ($clientId !== null && $clientSecret !== null);

        if (!$hasAccessToken && !$hasClientCreds) {
            $missing[] = 'SHOPIFY_ACCESS_TOKEN (or SHOPIFY_CLIENT_ID + SHOPIFY_CLIENT_SECRET)';
        }

        // Netvisor auth required only for live mode
        if ($netvisorMode === 'live') {
            if ($sender === '')     $missing[] = 'NETVISOR_SENDER';
            if ($partnerId === '')  $missing[] = 'NETVISOR_PARTNER_ID';
            if ($customerId === '') $missing[] = 'NETVISOR_CUSTOMER_ID';
            if ($token === '')      $missing[] = 'NETVISOR_TOKEN';
            if ($macKey === '')     $missing[] = 'NETVISOR_MAC_KEY';
        }

        if ($missing) {
            throw new \InvalidArgumentException('Missing env vars: ' . implode(', ', $missing));
        }

        // Build auth object always (mock mode can keep empties)
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

        return new self(
            stateFile: $stateFile,

            // Shopify
            shopifyShopDomain: $shopDomain,
            shopifyApiVersion: $apiVersion,
            shopifyAccessToken: $accessToken,
            shopifyClientId: $clientId,
            shopifyClientSecret: $clientSecret,

            // Netvisor
            netvisorBaseUrl: $netvisorBaseUrl,
            netvisorAuth: $auth,

            // Netvisor demo/mock
            netvisorMode: $netvisorMode,
            netvisorOutDir: $netvisorOutDir,
            netvisorDebugAuth: $netvisorDebugAuth,

            // HTTP
            httpTimeout: $httpTimeout,
            httpConnectTimeout: $httpConnectTimeout,
            httpProxy: $httpProxy,

            // mapping defaults
            netvisorDefaultCustomerCode: $defaultCustomerCode,
            netvisorDefaultPaymentTermDays: $defaultPaymentTerm,
            netvisorDefaultVatPercent: $defaultVatPercent,
            netvisorDefaultVatCode: $defaultVatCode,
            netvisorDefaultProductCode: $defaultProductCode,
        );
    }
}