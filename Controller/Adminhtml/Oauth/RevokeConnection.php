<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Controller\Adminhtml\Oauth;

use Magenx\AiMcp\Model\OAuth\Storage;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\Redirect;
use Psr\Log\LoggerInterface;

/**
 * Revoke one OAuth grant. The client's next call is refused and its refresh token is dead.
 */
class RevokeConnection extends Action implements HttpPostActionInterface
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
        $familyId = (string) $this->getRequest()->getPost('family');
        if (preg_match('/^[0-9a-f]{64}$/', $familyId) === 1 && $this->storage->revokeFamily($familyId) > 0) {
            $this->auditLogger->info('[magenx-mcp] oauth grant revoked by admin', [
                'admin' => 'admin:' . (string) $this->_auth->getUser()?->getUserName(),
                'family' => substr($familyId, 0, 12),
            ]);
            $this->messageManager->addSuccessMessage(__('The connection was revoked.'));
        } else {
            $this->messageManager->addErrorMessage(__('That connection was not found or was already revoked.'));
        }

        return $this->resultRedirectFactory->create()->setPath('magenx_aimcp/oauth/connections');
    }
}
