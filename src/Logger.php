<?php
declare(strict_types=1);

namespace Demo;

final class Logger
{
    public function info(string $msg, array $ctx = []): void { $this->log('INFO', $msg, $ctx); }
    public function error(string $msg, array $ctx = []): void { $this->log('ERROR', $msg, $ctx); }

    private function log(string $level, string $msg, array $ctx): void
    {
        $time = gmdate('Y-m-d\TH:i:s\Z');
        $line = sprintf("[%s] %s %s %s\n", $time, $level, $msg, $ctx ? json_encode($ctx, JSON_UNESCAPED_UNICODE) : '');
        fwrite(STDERR, $line);
    }
}
