<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Controller\Adminhtml\Oauth;

use Magenx\AiMcp\Block\Adminhtml\Oauth\Consent as ConsentBlock;
use Magenx\AiMcp\Model\OAuth\AuthorizationService;
use Magenx\AiMcp\Model\OAuth\PendingConsent;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\View\Result\Page;
use Magento\Framework\View\Result\PageFactory;

/**
 * The consent screen: which client, which admin, which access.
 *
 * Reached only through the admin login and its two-factor check. Displaying
 * it changes nothing, which is why it accepts a URL without the admin secret
 * key — the browser arrives from another site and cannot have one. The
 * decision itself is {@see Decide}, a POST behind the form key.
 */
class Consent extends Action implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = 'Magenx_AiMcp::oauth_grant';

    /**
     * @param Context $context
     * @param PageFactory $pageFactory
     * @param AuthorizationService $authorizationService
     * @param PendingConsent $pendingConsent
     */
    public function __construct(
        Context $context,
        private readonly PageFactory $pageFactory,
        private readonly AuthorizationService $authorizationService,
        private readonly PendingConsent $pendingConsent
    ) {
        parent::__construct($context);
    }

    /**
     * Skip the secret-key check for this read-only page; keep every other check.
     *
     * @return bool
     */
    public function _processUrlKeys()
    {
        if ($this->getRequest()->isGet()) {
            return true;
        }

        return parent::_processUrlKeys();
    }

    /**
     * @return Page
     */
    public function execute(): Page
    {
        $requestId = (string) $this->getRequest()->getParam('request');
        if ($requestId === '') {
            $requestId = (string) $this->pendingConsent->get();
        }

        $authorization = $this->authorizationService->getPending($requestId);
        if ($authorization === null) {
            // Nothing left to decide: stop treating this browser as mid-flow,
            // so the next sign-in lands on the usual start-up page.
            $this->pendingConsent->forget();
        }

        $page = $this->pageFactory->create();
        $page->getConfig()->getTitle()->prepend(__('Connect AI Client'));
        $block = $page->getLayout()->getBlock('magenx_aimcp.oauth.consent');
        if ($block instanceof ConsentBlock) {
            $block->setAuthorization($authorization);
        }

        return $page;
    }
}
