<?php

declare(strict_types=1);

namespace App\Integration\Vehicle;

use RuntimeException;

/**
 * Výjimka konektoru nesoucí bezpečná metadata pro retry a stav připojení.
 *
 * @author    Pavel Filípek <pavel@filipek-czech.cz>
 * @copyright © 2026, Proclient s.r.o.
 * @created   18.09.2026
 */
final class VehicleConnectorException extends RuntimeException
{
    /** @var int */
    private $httpStatus;

    /** @var bool */
    private $needsAttention;

    /** @var string|null */
    private $retryAfterAt;

    /** @var array<string,mixed> */
    private $metadata;

    /** @param array<string,mixed> $metadata */
    public function __construct(
        string $message,
        int $httpStatus = 0,
        bool $needsAttention = false,
        ?string $retryAfterAt = null,
        array $metadata = []
    ) {
        parent::__construct($message, $httpStatus);
        $this->httpStatus = $httpStatus;
        $this->needsAttention = $needsAttention;
        $this->retryAfterAt = $retryAfterAt;
        $this->metadata = $metadata;
    }

    public function httpStatus(): int
    {
        return $this->httpStatus;
    }

    public function needsAttention(): bool
    {
        return $this->needsAttention;
    }

    public function retryAfterAt(): ?string
    {
        return $this->retryAfterAt;
    }

    /** @return array<string,mixed> */
    public function metadata(): array
    {
        return $this->metadata;
    }
}
