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
 * front name can express. Accepted forms:
 *
 *  - /.well-known/oauth-protected-resource/magenx-mcp/server (RFC 9728, the
 *    one the MCP endpoint's 401 names) and the bare root form;
 *  - /.well-known/oauth-authorization-server/magenx-mcp (RFC 8414 for this
 *    issuer) and the bare root form, which clients written against the
 *    2025-03-26 MCP revision fetch.
 *
 * Whether OAuth is switched on is the controllers' decision, so the router
 * stays a pure path match and needs no configuration.
 */
class WellKnown implements RouterInterface
{
    private const ROUTES = [
        Endpoints::PROTECTED_RESOURCE_WELL_KNOWN => 'protectedResource',
        Endpoints::PROTECTED_RESOURCE_WELL_KNOWN . Endpoints::RESOURCE_PATH => 'protectedResource',
        Endpoints::AUTHORIZATION_SERVER_WELL_KNOWN => 'metadata',
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
