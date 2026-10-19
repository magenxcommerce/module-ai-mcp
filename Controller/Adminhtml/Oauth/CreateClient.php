<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Controller\Adminhtml\Oauth;

use Magenx\AiMcp\Model\OAuth\ClientRegistry;
use Magenx\AiMcp\Model\OAuth\OAuthException;
use Magenx\AiMcp\Model\OAuth\SecretReveal;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\Redirect;

/**
 * Create an OAuth client for an AI service, and show its secret this once.
 */
class CreateClient extends Action implements HttpPostActionInterface
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
        try {
            $client = $this->clientRegistry->createForAdmin(
                (string) $this->getRequest()->getPost('client_name'),
                'admin:' . (string) $this->_auth->getUser()?->getUserName()
            );
            $this->secretReveal->stash(
                (string) __('Client created'),
                [
                    ['label' => (string) __('Client ID'), 'value' => (string) $client['client_id']],
                    ['label' => (string) __('Client secret'), 'value' => (string) $client['client_secret']],
                ],
                (string) __('Enter both in the connector\'s advanced settings ("Use your own OAuth client"). Copy '
                    . 'the secret now: it is stored only as a hash and cannot be shown again.')
            );
            $this->messageManager->addSuccessMessage(__('The client was created.'));
        } catch (OAuthException $e) {
            $this->messageManager->addErrorMessage($e->getMessage());
        }

        return $this->resultRedirectFactory->create()->setPath('magenx_aimcp/oauth/connections');
    }
}
