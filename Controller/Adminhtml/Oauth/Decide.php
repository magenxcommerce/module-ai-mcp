<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Controller\Adminhtml\Oauth;

use Magenx\AiMcp\Model\OAuth\AuthorizationService;
use Magenx\AiMcp\Model\OAuth\OAuthException;
use Magenx\AiMcp\Model\OAuth\PendingConsent;
use Magenx\AiMcp\Model\Protocol\Server;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\Redirect;

/**
 * Approve or deny a pending request, as the signed-in admin.
 *
 * POST only, behind the admin form key. On approval the browser is sent to
 * the client's verified callback with a single-use code; on denial, with
 * `error=access_denied`.
 */
class Decide extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Magenx_AiMcp::oauth_grant';

    /**
     * @param Context $context
     * @param AuthorizationService $authorizationService
     * @param PendingConsent $pendingConsent
     */
    public function __construct(
        Context $context,
        private readonly AuthorizationService $authorizationService,
        private readonly PendingConsent $pendingConsent
    ) {
        parent::__construct($context);
    }

    /**
     * @return Redirect
     */
    public function execute(): Redirect
    {
        $redirect = $this->resultRedirectFactory->create();
        $requestId = (string) $this->getRequest()->getPost('request');
        $user = $this->_auth->getUser();
        $adminId = (int) $user?->getId();
        $adminLabel = 'admin:' . (string) $user?->getUserName();
        $back = fn (): Redirect => $redirect->setPath('magenx_aimcp/oauth/consent', ['request' => $requestId]);

        if ($this->getRequest()->getPost('decision') !== 'approve') {
            $url = $this->authorizationService->deny($requestId, $adminLabel);
            $this->pendingConsent->forget();

            return $url === null ? $back() : $redirect->setUrl($url);
        }

        if (!$this->_authorization->isAllowed(Server::ENDPOINT_RESOURCE)) {
            $this->messageManager->addErrorMessage(
                __('Your admin role does not include "AI MCP Server", so a connection made as you could not use the endpoint.')
            );

            return $back();
        }

        try {
            $url = $this->authorizationService->approve(
                $requestId,
                $adminId,
                $adminLabel,
                $this->getRequest()->getPost('grant_write') === '1'
            );
        } catch (OAuthException $e) {
            $this->messageManager->addErrorMessage($e->getMessage());

            return $back();
        }

        $this->pendingConsent->forget();

        return $redirect->setUrl($url);
    }
}
