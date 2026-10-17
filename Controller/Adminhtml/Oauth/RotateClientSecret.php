<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Controller\Adminhtml\Oauth;

use Magenx\AiMcp\Model\OAuth\ClientRegistry;
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
     */
    public function __construct(
        Context $context,
        private readonly ClientRegistry $clientRegistry
    ) {
        parent::__construct($context);
    }

    /**
     * @return Redirect
     */
    public function execute(): Redirect
    {
        $clientSecret = $this->clientRegistry->rotateSecret(
            (string) $this->getRequest()->getPost('client'),
            'admin:' . (string) $this->_auth->getUser()?->getUserName()
        );
        if ($clientSecret !== null) {
            $this->messageManager->addSuccessMessage(__(
                'New client secret: %1 — The old one no longer works. Enter the new one in the connector\'s '
                . 'settings: its connections are kept, but it cannot renew their tokens until it has the new '
                . 'secret. Copy it now: it is stored only as a hash and cannot be shown again.',
                $clientSecret
            ));
        } else {
            $this->messageManager->addErrorMessage(__('That client was not found.'));
        }

        return $this->resultRedirectFactory->create()->setPath('magenx_aimcp/oauth/connections');
    }
}
