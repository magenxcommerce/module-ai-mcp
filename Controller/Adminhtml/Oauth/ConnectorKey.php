<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Controller\Adminhtml\Oauth;

use Magenx\AiMcp\Model\OAuth\ConnectorKey as ConnectorKeyModel;
use Magenx\AiMcp\Model\OAuth\SecretReveal;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\Redirect;

/**
 * Generate, replace or remove the connector key, showing a new key this once.
 */
class ConnectorKey extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Magenx_AiMcp::oauth_manage';

    /**
     * @param Context $context
     * @param ConnectorKeyModel $connectorKey
     * @param SecretReveal $secretReveal
     */
    public function __construct(
        Context $context,
        private readonly ConnectorKeyModel $connectorKey,
        private readonly SecretReveal $secretReveal
    ) {
        parent::__construct($context);
    }

    /**
     * @return Redirect
     */
    public function execute(): Redirect
    {
        $adminLabel = 'admin:' . (string) $this->_auth->getUser()?->getUserName();

        if ($this->getRequest()->getPost('operation') === 'remove') {
            $this->connectorKey->remove($adminLabel);
            $this->messageManager->addSuccessMessage(
                __('The connector key was removed. OAuth connections no longer need one.')
            );
        } else {
            $key = $this->connectorKey->generate($adminLabel);
            $this->secretReveal->stash(
                (string) __('Connector key generated'),
                [
                    ['label' => (string) __('Header name'), 'value' => 'x-api-key'],
                    ['label' => (string) __('Header value'), 'value' => $key],
                ],
                (string) __('In the connector\'s settings, add this request header. Every OAuth connection without '
                    . 'it is refused from now on. Copy it now: it is stored only as a hash and cannot be shown again.')
            );
            $this->messageManager->addSuccessMessage(__('The connector key was generated.'));
        }

        return $this->resultRedirectFactory->create()->setPath('magenx_aimcp/oauth/connections');
    }
}
