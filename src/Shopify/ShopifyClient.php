<?php
declare(strict_types=1);

namespace Demo\Shopify;

use Demo\Config;
use Demo\Logger;
use Demo\StateStore;
use GuzzleHttp\Client;

final class ShopifyClient
{
    private ?string $accessToken;

    public function __construct(
        private readonly Client $http,
        private readonly Logger $logger,
        private readonly Config $config,
        private readonly StateStore $state,
    ) {
        $this->accessToken = $config->shopifyAccessToken !== '' ? $config->shopifyAccessToken : null;
    }

    /**
     * Hakee tilaukset, joita on päivitetty lastRunIso jälkeen.
     * Cursor-paginointi.
     *
     * @return array<int, array<string,mixed>>
     */
    public function fetchUpdatedOrders(string $lastRunIso, int $first = 50): array
    {
        $token = $this->ensureAccessToken();

        $endpoint = sprintf(
            'https://%s/admin/api/%s/graphql.json',
            $this->config->shopifyShopDomain,
            $this->config->shopifyApiVersion
        );

        $orders = [];
        $hasNext = true;
        $after = null;
        $guard = 0;

        $gql = <<<'GQL'
query($first: Int!, $query: String!, $after: String) {
  orders(first: $first, query: $query, after: $after, sortKey: UPDATED_AT, reverse: false) {
    pageInfo { hasNextPage endCursor }
    edges {
      node {
        id
        name
        updatedAt
        processedAt
        totalPriceSet { shopMoney { amount currencyCode } }
        shippingAddress {
          name
          address1
          address2
          zip
          city
          country
        }
        customer { firstName lastName }
        lineItems(first: 50) {
          edges {
            node {
              title
              quantity
              originalUnitPriceSet { shopMoney { amount currencyCode } }
              sku
            }
          }
        }
      }
    }
  }
}
GQL;

        // Shopify query syntax: updated_at:>...
        $queryString = sprintf('updated_at:>%s', $lastRunIso);

        while ($hasNext) {
            $payload = [
                'query' => $gql,
                'variables' => ['first' => $first, 'query' => $queryString, 'after' => $after],
            ];

            $res = $this->http->post($endpoint, [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'X-Shopify-Access-Token' => $token,
                ],
                'json' => $payload,
            ]);

            $status = $res->getStatusCode();
            $body = (string) $res->getBody();

            if ($status < 200 || $status >= 300) {
                $this->logger->error('Shopify fetch failed', ['status' => $status, 'body' => $body]);
                throw new \RuntimeException("Shopify API error: HTTP $status");
            }

            $data = json_decode($body, true);
            if (!is_array($data)) {
                throw new \RuntimeException('Shopify invalid JSON');
            }

            if (!empty($data['errors'])) {
                $this->logger->error('Shopify GraphQL errors', ['errors' => $data['errors']]);
                throw new \RuntimeException('Shopify GraphQL returned errors');
            }

            $edges = $data['data']['orders']['edges'] ?? [];
            foreach ($edges as $edge) {
                $node = $edge['node'] ?? null;
                if (!$node) continue;
                $orders[] = $this->normalizeOrder($node);
            }

            $pageInfo = $data['data']['orders']['pageInfo'] ?? null;
            $hasNext = (bool)($pageInfo['hasNextPage'] ?? false);
            $after = $pageInfo['endCursor'] ?? null;

            $guard++;
            if ($guard > 50) break; // safety
        }

        return $orders;
    }

    // ------------------------
    // Token handling
    // ------------------------

    private function ensureAccessToken(): string
    {
        // 1) If env token present, use it (no expiry known)
        if ($this->accessToken !== null && $this->accessToken !== '') {
            return $this->accessToken;
        }

        // 2) If state token exists and not expired, use it
        $stateToken = $this->state->getShopifyAccessToken();
        $expiresAt = $this->state->getShopifyTokenExpiresAt();

        if ($this->isTokenValid($stateToken, $expiresAt)) {
            $this->accessToken = $stateToken;
            return $stateToken;
        }

        // 3) Fetch new token with client_credentials (if configured)
        $clientId = $this->config->shopifyClientId;
        $clientSecret = $this->config->shopifyClientSecret;

        if ($clientId === null || $clientSecret === null) {
            throw new \RuntimeException(
                'Shopify token missing. Provide SHOPIFY_ACCESS_TOKEN or SHOPIFY_CLIENT_ID + SHOPIFY_CLIENT_SECRET.'
            );
        }

        $shop = $this->config->shopifyShopDomain;
        $url = "https://{$shop}/admin/oauth/access_token";

        $res = $this->http->post($url, [
            'headers' => ['Content-Type' => 'application/json'],
            'json' => [
                'client_id' => $clientId,
                'client_secret' => $clientSecret,
                'grant_type' => 'client_credentials',
            ],
        ]);

        $status = $res->getStatusCode();
        $body = (string) $res->getBody();

        if ($status !== 200) {
            $this->logger->error('Shopify token fetch failed', ['status' => $status, 'body' => $body]);
            throw new \RuntimeException("Shopify token fetch failed: HTTP {$status}");
        }

        $data = json_decode($body, true);
        if (!is_array($data)) {
            throw new \RuntimeException('Shopify token fetch returned invalid JSON');
        }

        $token = $data['access_token'] ?? null;
        if (!is_string($token) || $token === '') {
            throw new \RuntimeException('Shopify token fetch failed: missing access_token');
        }

        // Use expires_in if provided, else assume 24h
        $expiresIn = isset($data['expires_in']) ? (int)$data['expires_in'] : (24 * 3600);
        $expiresAtEpoch = time() + max(60, $expiresIn);
        // small buffer so we refresh before it expires
        $expiresAtEpoch -= 60;

        $this->state->setShopifyToken($token, $expiresAtEpoch);
        $this->accessToken = $token;

        $this->logger->info('Shopify access token refreshed', ['expiresAt' => $expiresAtEpoch]);

        return $token;
    }

    private function isTokenValid(?string $token, ?int $expiresAtEpoch): bool
    {
        if ($token === null || $token === '') return false;
        if ($expiresAtEpoch === null) return true; // if no expiry known, assume valid
        return time() < $expiresAtEpoch;
    }

    // ------------------------
    // Normalize
    // ------------------------

    /**
     * @param array<string,mixed> $node
     * @return array<string,mixed>
     */
    private function normalizeOrder(array $node): array
    {
        $lines = [];
        foreach (($node['lineItems']['edges'] ?? []) as $e) {
            $li = $e['node'] ?? [];
            $lines[] = [
                'title' => (string) ($li['title'] ?? ''),
                'sku' => (string) ($li['sku'] ?? ''),
                'quantity' => (int) ($li['quantity'] ?? 0),
                'unitPrice' => (string) ($li['originalUnitPriceSet']['shopMoney']['amount'] ?? '0'),
                'currency' => (string) ($li['originalUnitPriceSet']['shopMoney']['currencyCode'] ?? ''),
            ];
        }

        $ship = $node['shippingAddress'] ?? [];

        return [
            'id' => (string) ($node['id'] ?? ''),
            'name' => (string) ($node['name'] ?? ''),
            'updatedAt' => (string) ($node['updatedAt'] ?? ''),
            'totalAmount' => (string) ($node['totalPriceSet']['shopMoney']['amount'] ?? '0'),
            'currency' => (string) ($node['totalPriceSet']['shopMoney']['currencyCode'] ?? 'EUR'),
            'customerName' => (string) (
                $ship['name']
                ?? trim((string)($node['customer']['firstName'] ?? '') . ' ' . (string)($node['customer']['lastName'] ?? ''))
            ),
            'shippingAddress' => [
                'address1' => (string) ($ship['address1'] ?? ''),
                'address2' => (string) ($ship['address2'] ?? ''),
                'zip' => (string) ($ship['zip'] ?? ''),
                'city' => (string) ($ship['city'] ?? ''),
                'country' => (string) ($ship['country'] ?? ''),
            ],
            'lines' => $lines,
        ];
    }
}