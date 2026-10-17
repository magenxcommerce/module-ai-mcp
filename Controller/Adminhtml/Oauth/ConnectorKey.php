<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Controller\Adminhtml\Oauth;

use Magenx\AiMcp\Model\OAuth\ConnectorKey as ConnectorKeyModel;
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
     */
    public function __construct(
        Context $context,
        private readonly ConnectorKeyModel $connectorKey
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
            $this->messageManager->addSuccessMessage(__(
                'Connector key: %1 — In the connector\'s settings, add the request header x-api-key with this '
                . 'value. Every OAuth connection without it is refused from now on. Copy it now: it is stored '
                . 'only as a hash and cannot be shown again.',
                $key
            ));
        }

        return $this->resultRedirectFactory->create()->setPath('magenx_aimcp/oauth/connections');
    }
}
