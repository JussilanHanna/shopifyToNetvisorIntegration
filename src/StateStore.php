<?php
declare(strict_types=1);

namespace Demo;

final class StateStore
{
    private array $state;

    public function __construct(
        private readonly string $path,
        private readonly Logger $logger
    ) {
        $this->state = $this->load();
        $this->state['sent'] ??= [];
        $this->state['shopify'] ??= [];
    }

    // ---------------------------
    // Existing checkpoint logic
    // ---------------------------

    public function getLastRunIso(): string
    {
        return $this->state['lastRunIso'] ?? gmdate('c', time() - 1800);
    }

    public function setLastRunIso(string $iso): void
    {
        $this->state['lastRunIso'] = $iso;
        $this->save();
    }

    public function wasSent(string $shopifyOrderId): bool
    {
        return isset($this->state['sent'][$shopifyOrderId]);
    }

    public function markSent(string $shopifyOrderId, string $netvisorKey = ''): void
    {
        $this->state['sent'][$shopifyOrderId] = [
            'sentAt' => gmdate('c'),
            'netvisorKey' => $netvisorKey
        ];
        $this->save();
    }

    // ---------------------------
    // Shopify token cache
    // ---------------------------

    public function getShopifyAccessToken(): ?string
    {
        $t = $this->state['shopify']['access_token'] ?? null;
        return (is_string($t) && $t !== '') ? $t : null;
    }

    public function getShopifyTokenExpiresAt(): ?int
    {
        $ts = $this->state['shopify']['expires_at'] ?? null;
        return is_int($ts) ? $ts : null;
    }

    public function setShopifyToken(string $token, int $expiresAtEpoch): void
    {
        $this->state['shopify']['access_token'] = $token;
        $this->state['shopify']['expires_at'] = $expiresAtEpoch;
        $this->save();
    }

    // ---------------------------
    // File IO (atomic)
    // ---------------------------

    private function load(): array
    {
        if (!is_file($this->path)) {
            return ['sent' => [], 'shopify' => []];
        }
        $raw = @file_get_contents($this->path);
        $json = json_decode($raw ?: '[]', true);
        return is_array($json) ? $json : ['sent' => [], 'shopify' => []];
    }

    private function save(): void
    {
        $dir = dirname($this->path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }

        $tmp = $this->path . '.tmp';
        $data = json_encode($this->state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $fp = @fopen($tmp, 'c');
        if ($fp === false) {
            $this->logger->error('Failed to open temp state file for writing', ['tmp' => $tmp]);
            return;
        }

        try {
            if (!flock($fp, LOCK_EX)) {
                $this->logger->error('Failed to acquire lock for state file', ['tmp' => $tmp]);
                fclose($fp);
                return;
            }
            ftruncate($fp, 0);
            fwrite($fp, $data);
            fflush($fp);
            flock($fp, LOCK_UN);
            fclose($fp);
            rename($tmp, $this->path);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to save state', ['error' => $e->getMessage()]);
            @fclose($fp);
        }
    }
}