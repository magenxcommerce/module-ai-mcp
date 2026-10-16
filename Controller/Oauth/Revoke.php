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
 * POST /magenx-mcp/oauth/revoke — RFC 7009 token revocation.
 *
 * Revokes the whole grant the token belongs to, which is what a client
 * means when the user removes the connector.
 */
class Revoke extends AbstractEndpoint implements HttpPostActionInterface
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
        } catch (OAuthException $e) {
            return $this->responder->respond($e->toArray(), $e->getHttpStatus());
        }

        $token = $params['token'] ?? null;
        if (is_string($token) && $token !== '') {
            $this->tokenService->revoke($client, $token);
        }

        return $this->responder->empty(200);
    }
}
