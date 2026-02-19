<?php
declare(strict_types=1);

namespace Demo\Shopify;

use Demo\Logger;
use GuzzleHttp\Client;

final class ShopifyClient
{
    public function __construct(
        private readonly Client $http,
        private readonly Logger $logger,
        private readonly string $shopDomain,
        private readonly string $accessToken,
        private readonly string $apiVersion,
    ) {}

    public function fetchUpdatedOrders(string $lastRunIso, int $first = 50): array
    {
        $endpoint = sprintf('https://%s/admin/api/%s/graphql.json', $this->shopDomain, $this->apiVersion);

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
        customer { firstName lastName email }
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

        $queryString = sprintf('updated_at:>%s', $lastRunIso);

        $orders = [];
        $after = null;
        $page = 0;

        while (true) {
            $res = $this->http->post($endpoint, [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'X-Shopify-Access-Token' => $this->accessToken,
                ],
                'json' => [
                    'query' => $gql,
                    'variables' => ['first' => $first, 'query' => $queryString, 'after' => $after],
                ],
            ]);

            $status = $res->getStatusCode();
            $body = (string)$res->getBody();

            if ($status < 200 || $status >= 300) {
                $this->logger->error('Shopify fetch failed', ['status' => $status, 'body' => $body]);
                throw new \RuntimeException("Shopify API error: HTTP $status");
            }

            $data = json_decode($body, true);
            if (!is_array($data)) throw new \RuntimeException('Shopify invalid JSON');

            if (!empty($data['errors'])) {
                $this->logger->error('Shopify GraphQL errors', ['errors' => $data['errors']]);
                throw new \RuntimeException('Shopify GraphQL returned errors');
            }

            $edges = $data['data']['orders']['edges'] ?? [];
            foreach ($edges as $edge) {
                $node = $edge['node'] ?? null;
                if ($node) $orders[] = $this->normalizeOrder($node);
            }

            $pageInfo = $data['data']['orders']['pageInfo'] ?? [];
            $hasNext = (bool)($pageInfo['hasNextPage'] ?? false);
            $after = $pageInfo['endCursor'] ?? null;

            $page++;
            if (!$hasNext) break;
            if ($page > 50) break; // safety
        }

        return $orders;
    }

    private function normalizeOrder(array $node): array
    {
        $ship = $node['shippingAddress'] ?? [];
        $customer = $node['customer'] ?? [];

        $lines = [];
        foreach (($node['lineItems']['edges'] ?? []) as $e) {
            $li = $e['node'] ?? [];
            $lines[] = [
                'title' => (string)($li['title'] ?? ''),
                'sku' => (string)($li['sku'] ?? ''),
                'quantity' => (int)($li['quantity'] ?? 0),
                'unitPrice' => (string)($li['originalUnitPriceSet']['shopMoney']['amount'] ?? '0'),
                'currency' => (string)($li['originalUnitPriceSet']['shopMoney']['currencyCode'] ?? ''),
            ];
        }

        $customerName =
            (string)($ship['name'] ?? '') !== ''
                ? (string)$ship['name']
                : trim((string)($customer['firstName'] ?? '') . ' ' . (string)($customer['lastName'] ?? ''));

        return [
            'id' => (string)($node['id'] ?? ''),
            'name' => (string)($node['name'] ?? ''),
            'updatedAt' => (string)($node['updatedAt'] ?? ''),
            'processedAt' => (string)($node['processedAt'] ?? ''),
            'totalAmount' => (string)($node['totalPriceSet']['shopMoney']['amount'] ?? '0'),
            'currency' => (string)($node['totalPriceSet']['shopMoney']['currencyCode'] ?? 'EUR'),
            'customerName' => $customerName !== '' ? $customerName : 'Unknown',
            'customerEmail' => (string)($customer['email'] ?? ''),
            'shippingAddress' => [
                'address1' => (string)($ship['address1'] ?? ''),
                'address2' => (string)($ship['address2'] ?? ''),
                'zip' => (string)($ship['zip'] ?? ''),
                'city' => (string)($ship['city'] ?? ''),
                'country' => (string)($ship['country'] ?? ''),
            ],
            'lines' => $lines,
        ];
    }
}
