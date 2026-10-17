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
 * Disable a client, or enable it again. Its connections are kept either way.
 */
class SetClientStatus extends Action implements HttpPostActionInterface
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
        $disable = $this->getRequest()->getPost('status') === 'disabled';
        if ($this->clientRegistry->setDisabled(
            (string) $this->getRequest()->getPost('client'),
            $disable,
            'admin:' . (string) $this->_auth->getUser()?->getUserName()
        )) {
            $this->messageManager->addSuccessMessage($disable
                ? __('The client was disabled. Its connections are refused until it is enabled again.')
                : __('The client was enabled. Its connections work again.'));
        } else {
            $this->messageManager->addErrorMessage(__('That client was not found.'));
        }

        return $this->resultRedirectFactory->create()->setPath('magenx_aimcp/oauth/connections');
    }
}
