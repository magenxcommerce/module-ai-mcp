<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Controller\Oauth;

use Magenx\AiMcp\Model\Config;
use Magenx\AiMcp\Model\OAuth\ClientRegistry;
use Magenx\AiMcp\Model\OAuth\JsonResponder;
use Magenx\AiMcp\Model\OAuth\OAuthConfig;
use Magenx\AiMcp\Model\OAuth\OAuthException;
use Magenx\AiMcp\Model\OAuth\TokenService;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\Controller\Result\Raw;
use Magento\Framework\Serialize\Serializer\Json;

/**
 * POST /magenx-mcp/oauth/token — the authorization_code and refresh_token grants.
 */
class Token extends AbstractEndpoint implements HttpPostActionInterface
{
    /**
     * @param HttpRequest $request
     * @param JsonResponder $responder
     * @param Json $serializer
     * @param Config $config
     * @param OAuthConfig $oauthConfig
     * @param ClientRegistry $clientRegistry
     * @param TokenService $tokenService
     */
    public function __construct(
        HttpRequest $request,
        JsonResponder $responder,
        Json $serializer,
        Config $config,
        OAuthConfig $oauthConfig,
        private readonly ClientRegistry $clientRegistry,
        private readonly TokenService $tokenService
    ) {
        parent::__construct($request, $responder, $serializer, $config, $oauthConfig);
    }

    /**
     * @return Raw
     */
    public function execute(): Raw
    {
        if (!$this->isActive()) {
            return $this->responder->empty(404);
        }

        $params = $this->readBody();
        [$clientId, $clientSecret, $method] = $this->clientCredentials($params);

        try {
            $client = $this->clientRegistry->authenticate($clientId, $clientSecret, $method);

            return $this->responder->respond($this->grant($client, $params));
        } catch (OAuthException $e) {
            $headers = $e->getHttpStatus() === 401 && $method === ClientRegistry::AUTH_BASIC
                ? ['WWW-Authenticate' => 'Basic realm="magenx-mcp"']
                : [];

            return $this->responder->respond($e->toArray(), $e->getHttpStatus(), $headers);
        }
    }

    /**
     * Run the requested grant.
     *
     * @param array<string, mixed> $client
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     * @throws OAuthException
     */
    private function grant(array $client, array $params): array
    {
        return match ($params['grant_type'] ?? null) {
            'authorization_code' => $this->tokenService->exchangeCode($client, $params),
            'refresh_token' => $this->tokenService->refresh($client, $params),
            default => throw new OAuthException(
                'unsupported_grant_type',
                'Use grant_type=authorization_code or grant_type=refresh_token.'
            ),
        };
    }
}
