<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Controller\Adminhtml\Oauth;

use Magenx\AiMcp\Model\OAuth\ClientRegistry;
use Magenx\AiMcp\Model\OAuth\SecretReveal;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\Redirect;

/**
 * Give a client a new secret, shown this once. Its connections are kept.
 */
class RotateClientSecret extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Magenx_AiMcp::oauth_manage';

    /**
     * @param Context $context
     * @param ClientRegistry $clientRegistry
     * @param SecretReveal $secretReveal
     */
    public function __construct(
        Context $context,
        private readonly ClientRegistry $clientRegistry,
        private readonly SecretReveal $secretReveal
    ) {
        parent::__construct($context);
    }

    /**
     * @return Redirect
     */
    public function execute(): Redirect
    {
        $clientId = (string) $this->getRequest()->getPost('client');
        $clientSecret = $this->clientRegistry->rotateSecret(
            $clientId,
            'admin:' . (string) $this->_auth->getUser()?->getUserName()
        );
        if ($clientSecret !== null) {
            $this->secretReveal->stash(
                (string) __('Client secret rotated'),
                [
                    ['label' => (string) __('Client ID'), 'value' => $clientId],
                    ['label' => (string) __('New client secret'), 'value' => $clientSecret],
                ],
                (string) __('The old secret no longer works. Enter the new one in the connector\'s settings: its '
                    . 'connections are kept, but it cannot renew their tokens until it has the new secret. Copy it '
                    . 'now: it is stored only as a hash and cannot be shown again.')
            );
            $this->messageManager->addSuccessMessage(__('The client secret was rotated.'));
        } else {
            $this->messageManager->addErrorMessage(__('That client was not found.'));
        }

        return $this->resultRedirectFactory->create()->setPath('magenx_aimcp/oauth/connections');
    }
}
