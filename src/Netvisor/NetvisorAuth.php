<?php
declare(strict_types=1);

namespace Demo\Netvisor;

final class NetvisorAuth
{
    public function __construct(
        public readonly string $sender,
        public readonly string $partnerId,
        public readonly string $customerId,
        public readonly string $token,
        public readonly string $macKey,
        public readonly string $language,
        public readonly string $organizationId,
        public readonly bool $useHttpStatusCodes,
        public readonly string $macAlgorithm,
    ) {}
}
