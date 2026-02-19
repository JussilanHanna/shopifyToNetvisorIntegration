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
        private readonly NetvisorAuth $auth
    ) {}

    public function createSalesOrderXml(string $xml): array
    {
        $url = rtrim($this->baseUrl, '/') . '/salesinvoice.nv';

        $timestamp = gmdate('Y-m-d\TH:i:s');
        $transactionId = bin2hex(random_bytes(16));

        $headers = $this->buildHeaders($url, $timestamp, $transactionId, $xml);

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

    private function buildHeaders(string $url, string $timestamp, string $transactionId, string $payload): array
    {
        $mac = $this->calculateMac($url, $timestamp, $transactionId, $payload);

        $h = [
            'X-Netvisor-Authentication-Sender' => $this->auth->sender,
            'X-Netvisor-Authentication-PartnerId' => $this->auth->partnerId,
            'X-Netvisor-Authentication-CustomerId' => $this->auth->customerId,
            'X-Netvisor-Authentication-Token' => $this->auth->token,
            'X-Netvisor-Authentication-Timestamp' => $timestamp,
            'X-Netvisor-Authentication-TransactionId' => $transactionId,
            'X-Netvisor-Authentication-MACHashCalculationAlgorithm' => $this->auth->macAlgorithm,
            'X-Netvisor-Authentication-MAC' => $mac,
            'X-Netvisor-Interface-Language' => $this->auth->language,
        ];

        if ($this->auth->organizationId !== '') {
            $h['X-Netvisor-Organisation-ID'] = $this->auth->organizationId;
        }
        if ($this->auth->useHttpStatusCodes) {
            $h['X-Netvisor-Authentication-UseHTTPResponseStatusCodes'] = '1';
        }

        return $h;
    }

    private function calculateMac(string $url, string $timestamp, string $transactionId, string $payload): string
    {
        // DEMO canonical: helposti vaihdettavissa Netvisor-dokumentaation mukaiseen tarkkaan muotoon
        $payloadHash = hash('sha256', $payload);
        $canonical = implode('&', [
            'POST',
            $url,
            $this->auth->sender,
            $this->auth->customerId,
            $this->auth->partnerId,
            $timestamp,
            $transactionId,
            $payloadHash,
        ]);

        return base64_encode(hash_hmac('sha256', $canonical, $this->auth->macKey, true));
    }

    private function tryParseXml(string $body): array
    {
        libxml_use_internal_errors(true);
        $xml = simplexml_load_string($body);
        if ($xml === false) return ['raw' => $body];
        return json_decode(json_encode($xml), true) ?: ['raw' => $body];
    }
}
