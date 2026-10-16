<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\OAuth;

/**
 * An OAuth error response: the RFC 6749 `error` code, a description, and the HTTP status.
 *
 * Not a LocalizedException: the code is a protocol token a client matches on,
 * and the description is for the developer of that client, not for a
 * translated admin screen.
 */
class OAuthException extends \RuntimeException
{
    /**
     * @param string $error RFC 6749 / RFC 7591 error code, e.g. invalid_grant.
     * @param string $description
     * @param int $httpStatus
     */
    public function __construct(
        private readonly string $error,
        string $description,
        private readonly int $httpStatus = 400
    ) {
        parent::__construct($description);
    }

    /**
     * @return string
     */
    public function getError(): string
    {
        return $this->error;
    }

    /**
     * @return int
     */
    public function getHttpStatus(): int
    {
        return $this->httpStatus;
    }

    /**
     * The JSON error body.
     *
     * @return array<string, string>
     */
    public function toArray(): array
    {
        return ['error' => $this->error, 'error_description' => $this->getMessage()];
    }
}
