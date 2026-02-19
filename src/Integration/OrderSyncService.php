<?php
declare(strict_types=1);

namespace Demo\Integration;

use Demo\Logger;
use Demo\StateStore;
use Demo\Shopify\ShopifyClient;
use Demo\Netvisor\NetvisorClient;
use Demo\Mapping\NetvisorSalesOrderMapper;

final class OrderSyncService
{
    public function __construct(
        private readonly ShopifyClient $shopify,
        private readonly NetvisorClient $netvisor,
        private readonly NetvisorSalesOrderMapper $mapper,
        private readonly StateStore $state,
        private readonly Logger $logger
    ) {}

    public function run(): void
    {
        $lastRunIso = $this->state->getLastRunIso();
        $this->logger->info('Starting sync', ['lastRunIso' => $lastRunIso]);

        $orders = $this->shopify->fetchUpdatedOrders($lastRunIso);
        $this->logger->info('Fetched orders', ['count' => count($orders)]);

        $maxUpdatedAt = $lastRunIso;

        foreach ($orders as $order) {
            $id = (string)($order['id'] ?? '');
            if ($id === '') {
                $this->logger->error('Order missing id');
                continue;
            }

            $updatedAt = (string)($order['updatedAt'] ?? '');
            $maxUpdatedAt = $this->maxIso($maxUpdatedAt, $updatedAt);

            if ($this->state->wasSent($id)) {
                $this->logger->info('Skipping already sent', ['id' => $id]);
                continue;
            }

            try {
                $xml = $this->mapper->toSalesOrderXml($order);
                $resp = $this->netvisor->createSalesOrderXml($xml);

                // Demo: jos parsed vastauksessa näkyy NetvisorKey, tallenna se
                $netvisorKey = (string)($resp['parsed']['netvisorkey'] ?? $resp['parsed']['NetvisorKey'] ?? '');
                $this->state->markSent($id, $netvisorKey);

                $this->logger->info('Sent order', ['id' => $id, 'status' => $resp['status'] ?? null, 'netvisorKey' => $netvisorKey]);
            } catch (\Throwable $e) {
                $this->logger->error('Failed to process order', ['id' => $id, 'error' => $e->getMessage()]);
                // tuotannossa: DLQ / retry-queue
            }
        }

        // overlap checkpoint: vähennetään esim. 30s, jotta reunatapaukset eivät huku
        $checkpoint = $this->subtractSeconds($maxUpdatedAt, 30);
        $this->state->setLastRunIso($checkpoint);

        $this->logger->info('Sync finished', ['newLastRunIso' => $checkpoint]);
    }

    private function maxIso(string $a, string $b): string
    {
        try {
            $da = new \DateTimeImmutable($a);
            $db = new \DateTimeImmutable($b ?: $a);
            return ($db > $da) ? $db->format('c') : $da->format('c');
        } catch (\Throwable) {
            return $a;
        }
    }

    private function subtractSeconds(string $iso, int $seconds): string
    {
        try {
            $d = new \DateTimeImmutable($iso);
            return $d->sub(new \DateInterval('PT' . $seconds . 'S'))->format('c');
        } catch (\Throwable) {
            return $iso;
        }
    }
}
