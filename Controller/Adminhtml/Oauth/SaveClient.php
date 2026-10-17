<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Controller\Adminhtml\Oauth;

use Magenx\AiMcp\Model\OAuth\ClientRegistry;
use Magenx\AiMcp\Model\OAuth\OAuthException;
use Magenx\AiMcp\Model\Tool\ToolCatalog;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\Redirect;

/**
 * Save a client's policy: its approvers, the tool areas it may use, and what a second approval does.
 */
class SaveClient extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Magenx_AiMcp::oauth_manage';

    /**
     * @param Context $context
     * @param ClientRegistry $clientRegistry
     * @param ToolCatalog $catalog
     */
    public function __construct(
        Context $context,
        private readonly ClientRegistry $clientRegistry,
        private readonly ToolCatalog $catalog
    ) {
        parent::__construct($context);
    }

    /**
     * @return Redirect
     */
    public function execute(): Redirect
    {
        $request = $this->getRequest();
        $approvers = $request->getPost('approvers');
        $domains = $request->getPost('domains');
        $domains = is_array($domains)
            ? array_values(array_intersect(array_keys($this->catalog->getDomains()), $domains))
            : [];

        try {
            $this->clientRegistry->updatePolicy(
                (string) $request->getPost('client'),
                is_array($approvers) ? array_map('intval', $approvers) : [],
                // Everything ticked means no cap: a cap equal to today's areas
                // would quietly withhold any area a later release adds.
                count($domains) === count($this->catalog->getDomains()) ? null : $domains,
                (string) $request->getPost('reapproval'),
                'admin:' . (string) $this->_auth->getUser()?->getUserName()
            );
            $this->messageManager->addSuccessMessage(__('The client was saved.'));
        } catch (OAuthException $e) {
            $this->messageManager->addErrorMessage($e->getMessage());
        }

        return $this->resultRedirectFactory->create()->setPath('magenx_aimcp/oauth/connections');
    }
}
