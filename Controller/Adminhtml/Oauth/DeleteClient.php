<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Controller\Adminhtml\Oauth;

use Magenx\AiMcp\Model\OAuth\Secret;
use Magenx\AiMcp\Model\OAuth\Storage;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\Redirect;
use Psr\Log\LoggerInterface;

/**
 * Delete a client an administrator created, ending every connection made with it.
 */
class DeleteClient extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Magenx_AiMcp::oauth_manage';

    /**
     * @param Context $context
     * @param Storage $storage
     * @param LoggerInterface $auditLogger
     */
    public function __construct(
        Context $context,
        private readonly Storage $storage,
        private readonly LoggerInterface $auditLogger
    ) {
        parent::__construct($context);
    }

    /**
     * @return Redirect
     */
    public function execute(): Redirect
    {
        $clientId = (string) $this->getRequest()->getPost('client');
        if (str_starts_with($clientId, Secret::PREFIX_CLIENT) && $this->storage->deleteAdminClient($clientId)) {
            $this->auditLogger->info('[magenx-mcp] oauth client deleted by admin', [
                'admin' => 'admin:' . (string) $this->_auth->getUser()?->getUserName(),
                'client_id' => $clientId,
            ]);
            $this->messageManager->addSuccessMessage(__('The client was deleted, with every connection made with it.'));
        } else {
            $this->messageManager->addErrorMessage(__('That client was not found or was already deleted.'));
        }

        return $this->resultRedirectFactory->create()->setPath('magenx_aimcp/oauth/connections');
    }
}
