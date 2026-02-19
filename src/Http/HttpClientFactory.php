<?php
declare(strict_types=1);

namespace Demo\Http;

use Demo\Config;
use GuzzleHttp\Client;

final class HttpClientFactory
{
    public static function create(Config $config): Client
    {
        $opts = [
            'timeout' => $config->httpTimeout,
            'connect_timeout' => $config->httpConnectTimeout,
            'http_errors' => false,
            'headers' => [
                'User-Agent' => 'demo/shopify-to-netvisor/1.0',
            ],
        ];

        if ($config->httpProxy) {
            $opts['proxy'] = $config->httpProxy;
        }

        return new Client($opts);
    }
}
