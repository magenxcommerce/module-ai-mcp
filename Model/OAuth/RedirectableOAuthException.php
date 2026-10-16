<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\OAuth;

/**
 * An authorization-endpoint error the client is told about by redirect.
 *
 * Only raised once the redirect URI has been verified against the client's
 * registration, so the redirect cannot be used to bounce a browser anywhere
 * the operator did not allow.
 */
class RedirectableOAuthException extends OAuthException
{
    /**
     * @param string $error
     * @param string $description
     * @param string $redirectUri
     * @param string $state
     * @param string $issuer
     */
    public function __construct(
        string $error,
        string $description,
        private readonly string $redirectUri,
        private readonly string $state,
        private readonly string $issuer
    ) {
        parent::__construct($error, $description);
    }

    /**
     * The URL carrying this error back to the client.
     *
     * @return string
     */
    public function getRedirectUrl(): string
    {
        $params = array_filter([
            'error' => $this->getError(),
            'error_description' => $this->getMessage(),
            'state' => $this->state,
            'iss' => $this->issuer,
        ], static fn (string $value): bool => $value !== '');

        return $this->redirectUri . (str_contains($this->redirectUri, '?') ? '&' : '?')
            . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    }
}
