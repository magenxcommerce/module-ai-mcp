<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Controller\Router;

use Magenx\AiMcp\Model\OAuth\Endpoints;
use Magento\Framework\App\Action\Forward;
use Magento\Framework\App\ActionFactory;
use Magento\Framework\App\ActionInterface;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\RouterInterface;

/**
 * Maps the two OAuth discovery documents onto this module's controllers.
 *
 * RFC 8414 and RFC 9728 fix these paths under /.well-known, which no Magento
 * front name can express. Only the path-suffixed forms are served:
 *
 *  - /.well-known/oauth-protected-resource/magenx-mcp/server (RFC 9728, the
 *    one the MCP endpoint's 401 names);
 *  - /.well-known/oauth-authorization-server/magenx-mcp (RFC 8414 for this
 *    issuer).
 *
 * The bare root forms are deliberately not answered: a client fetching them
 * derives the origin as the issuer or resource, which this document's values
 * would then fail to match, so serving them could only produce a confusing
 * validation error instead of a clean 404.
 *
 * Whether OAuth is switched on is the controllers' decision, so the router
 * stays a pure path match and needs no configuration.
 */
class WellKnown implements RouterInterface
{
    private const ROUTES = [
        Endpoints::PROTECTED_RESOURCE_WELL_KNOWN . Endpoints::RESOURCE_PATH => 'protectedResource',
        Endpoints::AUTHORIZATION_SERVER_WELL_KNOWN . Endpoints::ISSUER_PATH => 'metadata',
    ];

    /**
     * @param ActionFactory $actionFactory
     */
    public function __construct(
        private readonly ActionFactory $actionFactory
    ) {
    }

    /**
     * @param RequestInterface $request
     * @return ActionInterface|null
     */
    public function match(RequestInterface $request): ?ActionInterface
    {
        if (!$request instanceof HttpRequest) {
            return null;
        }

        $path = rtrim((string) $request->getPathInfo(), '/');
        $action = self::ROUTES[$path] ?? null;
        if ($action === null) {
            return null;
        }

        $request->setModuleName('magenx-mcp')
            ->setControllerName('oauth')
            ->setActionName($action)
            ->setPathInfo('/magenx-mcp/oauth/' . $action);

        return $this->actionFactory->create(Forward::class);
    }
}
