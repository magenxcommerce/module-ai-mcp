<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Controller\Oauth;

use Magenx\AiMcp\Model\Config;
use Magenx\AiMcp\Model\OAuth\Endpoints;
use Magenx\AiMcp\Model\OAuth\JsonResponder;
use Magenx\AiMcp\Model\OAuth\OAuthConfig;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\Controller\Result\Raw;
use Magento\Framework\Serialize\Serializer\Json;

/**
 * RFC 9728 protected resource metadata for the MCP endpoint.
 *
 * Its URL is what the MCP endpoint's 401 names in `resource_metadata`, and
 * it is how an MCP client learns which authorization server to use.
 */
class ProtectedResource extends AbstractEndpoint implements HttpGetActionInterface
{
    /**
     * @param HttpRequest $request
     * @param JsonResponder $responder
     * @param Json $serializer
     * @param Config $config
     * @param OAuthConfig $oauthConfig
     * @param Endpoints $endpoints
     */
    public function __construct(
        HttpRequest $request,
        JsonResponder $responder,
        Json $serializer,
        Config $config,
        OAuthConfig $oauthConfig,
        private readonly Endpoints $endpoints
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

        return $this->responder->respond($this->endpoints->getProtectedResourceMetadata());
    }
}
