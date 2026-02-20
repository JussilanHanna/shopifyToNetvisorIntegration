<?php
declare(strict_types=1);

namespace Demo\Netvisor;

use Demo\Logger;
use GuzzleHttp\Client;

final class NetvisorClient
{
    public function __construct(
        private readonly Client $http,
        private readonly Logger $logger,
        private readonly string $baseUrl,
        private readonly NetvisorAuth $auth,
        private readonly string $mode = 'mock',       
        private readonly string $outDir = './out/netvisor',
        private readonly bool $debugAuth = false
    ) {}

    public function createSalesOrderXml(string $xml): array
    {
        if ($this->mode === 'mock') {
            return $this->mockWrite($xml);
        }

        return $this->realRequest($xml);
    }

    private function realRequest(string $xml): array
    {
        $url = rtrim($this->baseUrl, '/') . '/salesinvoice.nv';

        // Netvisor timestamp format (docs: ANSI, UTC). :contentReference[oaicite:8]{index=8}
        $timestamp = gmdate('Y-m-d H:i:s.000');
        $timestampUnix = (string) time();
        $transactionId = bin2hex(random_bytes(16));

        $headers = $this->buildHeaders(
            url: $url,
            timestamp: $timestamp,
            timestampUnix: $timestampUnix,
            transactionId: $transactionId,
            payload: $xml
        );

        $maxAttempts = 3;
        $backoff = 1;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            $res = $this->http->post($url, [
                'headers' => $headers + ['Content-Type' => 'application/xml; charset=utf-8'],
                'body' => $xml,
            ]);

            $status = $res->getStatusCode();
            $body = (string)$res->getBody();

            if ($status >= 200 && $status < 300) {
                return ['status' => $status, 'body' => $body, 'parsed' => $this->tryParseXml($body)];
            }

            $transient = ($status === 429) || ($status >= 500 && $status <= 599);
            if ($transient && $attempt < $maxAttempts) {
                $this->logger->error('Netvisor transient error, retrying', ['status' => $status, 'attempt' => $attempt]);
                sleep($backoff);
                $backoff *= 2;
                continue;
            }

            $this->logger->error('Netvisor create order failed', ['status' => $status, 'body' => $body]);
            throw new \RuntimeException("Netvisor API error: HTTP $status");
        }

        throw new \RuntimeException('Netvisor API error: retries exhausted');
    }

    private function buildHeaders(string $url, string $timestamp, string $timestampUnix, string $transactionId, string $payload): array
    {
        // Netvisor auth headers list :contentReference[oaicite:9]{index=9}
        $h = [
            'X-Netvisor-Authentication-Sender' => $this->auth->sender,
            'X-Netvisor-Authentication-CustomerId' => $this->auth->customerId,
            'X-Netvisor-Authentication-PartnerId' => $this->auth->partnerId,
            'X-Netvisor-Authentication-Timestamp' => $timestamp,
            'X-Netvisor-Authentication-TimestampUnix' => $timestampUnix,
            'X-Netvisor-Authentication-TransactionId' => $transactionId,
            'X-Netvisor-Authentication-MACHashCalculationAlgorithm' => $this->auth->macAlgorithm,
            'X-Netvisor-Authentication-UseHTTPResponseStatusCodes' => $this->auth->useHttpStatusCodes ? '1' : '0',
            'X-Netvisor-Interface-Language' => $this->auth->language,
        ];

        if ($this->auth->organizationId !== '') {
            $h['X-Netvisor-Organisation-ID'] = $this->auth->organizationId;
        }

        // MAC: in production this must be calculated EXACTLY per Netvisor docs.
        // Docs note: partner/user "keys" are used in MAC calculation but NOT sent as headers. :contentReference[oaicite:10]{index=10}
        $h['X-Netvisor-Authentication-MAC'] = $this->calculateMacDemo(
            method: 'POST',
            url: $url,
            sender: $this->auth->sender,
            customerId: $this->auth->customerId,
            partnerId: $this->auth->partnerId,
            timestamp: $timestamp,
            timestampUnix: $timestampUnix,
            transactionId: $transactionId,
            payload: $payload
        );

        if ($this->debugAuth) {
            $this->logger->info('Netvisor auth debug', [
                'timestamp' => $timestamp,
                'timestampUnix' => $timestampUnix,
                'transactionId' => $transactionId,
                'headersSent' => array_keys($h),
            ]);
        }

        return $h;
    }

    /**
     * DEMO MAC:
     * - This is a placeholder that produces a stable MAC-like value.
     * - Replace with Netvisor's official canonical string & include partnerKey/customerKey when available.
     */
    private function calculateMacDemo(
        string $method,
        string $url,
        string $sender,
        string $customerId,
        string $partnerId,
        string $timestamp,
        string $timestampUnix,
        string $transactionId,
        string $payload
    ): string {
        $payloadHash = hash('sha256', $payload);

        $canonical = implode('&', [
            strtoupper($method),
            $url,
            $sender,
            $customerId,
            $partnerId,
            $timestamp,
            $timestampUnix,
            $transactionId,
            $payloadHash,
        ]);

        // NOTE: here we use macKey as "demo secret". Real Netvisor needs partnerKey+customerKey in calc. :contentReference[oaicite:11]{index=11}
        return base64_encode(hash_hmac('sha256', $canonical, $this->auth->macKey, true));
    }

    private function mockWrite(string $xml): array
    {
        $ts = gmdate('Ymd_His');
        $id = substr(bin2hex(random_bytes(8)), 0, 12);
        $dir = rtrim($this->outDir, '/\\');

        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }

        $xmlPath = "{$dir}/{$ts}_{$id}_salesinvoice.xml";
        file_put_contents($xmlPath, $xml);

        $fakeKey = 'DEMO-' . strtoupper(substr(bin2hex(random_bytes(8)), 0, 10));
        $responseXml = $this->fakeResponse($ts, $fakeKey);
        $respPath = "{$dir}/{$ts}_{$id}_response.xml";
        file_put_contents($respPath, $responseXml);

        $meta = [
            'mode' => 'mock',
            'createdAtUtc' => gmdate('c'),
            'fakeNetvisorKey' => $fakeKey,
            'notes' => 'No Netvisor credentials available; wrote XML + fake response for demo.',
        ];
        file_put_contents("{$dir}/{$ts}_{$id}_meta.json", json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        $this->logger->info('MOCK Netvisor create order OK', [
            'xml' => $xmlPath,
            'response' => $respPath,
            'netvisorKey' => $fakeKey,
        ]);

        return [
            'status' => 200,
            'body' => $responseXml,
            'parsed' => $this->tryParseXml($responseXml),
        ];
    }

    private function fakeResponse(string $timestamp, string $key): string
    {
        return <<<XML
<?xml version="1.0" encoding="utf-8" standalone="yes"?>
<Root>
  <ResponseStatus>
    <Status>OK</Status>
    <TimeStamp>{$timestamp}</TimeStamp>
  </ResponseStatus>
  <NetvisorKey>{$key}</NetvisorKey>
</Root>
XML;
    }

    private function tryParseXml(string $body): array
    {
        libxml_use_internal_errors(true);
        $xml = simplexml_load_string($body);
        if ($xml === false) return ['raw' => $body];
        return json_decode(json_encode($xml), true) ?: ['raw' => $body];
    }
}