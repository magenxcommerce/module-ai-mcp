<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Protocol;

/**
 * What the controller should put on the wire: an HTTP status plus an optional
 * JSON body.
 *
 * The body is null for JSON-RPC notifications, which MCP answers with a bare
 * 202 Accepted and no content.
 */
class Outcome
{
    /**
     * @param int $httpStatus
     * @param array<string, mixed>|null $body
     */
    public function __construct(
        private readonly int $httpStatus,
        private readonly ?array $body
    ) {
    }

    /**
     * @return int
     */
    public function getHttpStatus(): int
    {
        return $this->httpStatus;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getBody(): ?array
    {
        return $this->body;
    }
}
