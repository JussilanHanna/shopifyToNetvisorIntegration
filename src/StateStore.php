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
    }

    public function getLastRunIso(): string
    {
        // default: 15 min poll + pieni turvamarginaali
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

    private function load(): array
    {
        if (!is_file($this->path)) return ['sent' => []];
        $raw = @file_get_contents($this->path);
        $json = json_decode($raw ?: '[]', true);
        return is_array($json) ? $json : ['sent' => []];
    }

    private function save(): void
    {
        $dir = dirname($this->path);
        if (!is_dir($dir)) @mkdir($dir, 0777, true);

        $tmp = $this->path . '.tmp';
        $data = json_encode($this->state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        $fp = @fopen($tmp, 'c');
        if ($fp === false) {
            $this->logger->error('Failed to open temp state file', ['tmp' => $tmp]);
            return;
        }

        try {
            if (!flock($fp, LOCK_EX)) {
                $this->logger->error('Failed to lock state temp file', ['tmp' => $tmp]);
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
