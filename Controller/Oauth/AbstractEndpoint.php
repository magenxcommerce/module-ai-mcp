<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Controller\Oauth;

use Laminas\Http\Header\HeaderInterface;
use Magenx\AiMcp\Model\Config;
use Magenx\AiMcp\Model\OAuth\ClientRegistry;
use Magenx\AiMcp\Model\OAuth\JsonResponder;
use Magenx\AiMcp\Model\OAuth\OAuthConfig;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Serialize\Serializer\Json;

/**
 * Shared plumbing for the public OAuth endpoints.
 *
 * These are called by the AI service's servers, not by a browser holding a
 * Magento session, so there is no form key to check: CSRF validation is off,
 * exactly as on the MCP endpoint. Each one answers 404 unless both the MCP
 * endpoint and OAuth are switched on, so a store that does not use OAuth
 * publishes nothing about it.
 */
abstract class AbstractEndpoint implements CsrfAwareActionInterface
{
    /**
     * @param HttpRequest $request
     * @param JsonResponder $responder
     * @param Json $serializer
     * @param Config $config
     * @param OAuthConfig $oauthConfig
     */
    public function __construct(
        protected readonly HttpRequest $request,
        protected readonly JsonResponder $responder,
        protected readonly Json $serializer,
        private readonly Config $config,
        private readonly OAuthConfig $oauthConfig
    ) {
    }

    /**
     * Whether the endpoint should answer at all.
     *
     * @return bool
     */
    protected function isActive(): bool
    {
        return $this->config->isEnabled() && $this->oauthConfig->isEnabled();
    }

    /**
     * The request body as form fields, or as JSON when that is what was sent.
     *
     * @return array<string, mixed>
     */
    protected function readBody(): array
    {
        $type = $this->header('Content-Type');
        if (stripos($type, 'application/json') === 0) {
            try {
                $decoded = $this->serializer->unserialize((string) $this->request->getContent());
            } catch (\InvalidArgumentException) {
                return [];
            }

            return is_array($decoded) ? $decoded : [];
        }

        $post = $this->request->getPostValue();

        return is_array($post) ? $post : [];
    }

    /**
     * A request header as a string; empty when absent.
     *
     * @param string $name
     * @return string
     */
    protected function header(string $name): string
    {
        $raw = $this->request->getHeader($name);
        $value = $raw instanceof HeaderInterface ? $raw->getFieldValue() : (string) $raw;

        // A string cast of Magento's "absent" answer.
        return $value === '1' ? '' : trim($value);
    }

    /**
     * Read the client's credentials from HTTP Basic or the body (RFC 6749 §2.3.1).
     *
     * @param array<string, mixed> $params
     * @return array{0: string|null, 1: string|null, 2: string}
     */
    protected function clientCredentials(array $params): array
    {
        $authorization = $this->header('Authorization');
        if (stripos($authorization, 'Basic ') === 0) {
            // phpcs:ignore Magento2.Functions.DiscouragedFunction -- decoding the RFC 6749 client credentials header, not obfuscated code.
            $decoded = base64_decode(substr($authorization, 6), true);
            if ($decoded !== false && str_contains($decoded, ':')) {
                [$id, $secret] = array_map('urldecode', explode(':', $decoded, 2));

                // A public client may still send Basic with an empty password.
                return $secret === ''
                    ? [$id, null, ClientRegistry::AUTH_NONE]
                    : [$id, $secret, ClientRegistry::AUTH_BASIC];
            }

            return [null, null, ClientRegistry::AUTH_BASIC];
        }

        $clientId = is_string($params['client_id'] ?? null) ? $params['client_id'] : null;
        $clientSecret = is_string($params['client_secret'] ?? null) && $params['client_secret'] !== ''
            ? $params['client_secret']
            : null;

        return [
            $clientId,
            $clientSecret,
            $clientSecret === null ? ClientRegistry::AUTH_NONE : ClientRegistry::AUTH_POST,
        ];
    }

    /**
     * @param RequestInterface $request
     * @return InvalidRequestException|null
     */
    public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException
    {
        return null;
    }

    /**
     * @param RequestInterface $request
     * @return bool|null
     */
    public function validateForCsrf(RequestInterface $request): ?bool
    {
        return true;
    }
}
