<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Controller\Adminhtml\Oauth;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\View\Result\Page;
use Magento\Framework\View\Result\PageFactory;

/**
 * System > Extensions > AI MCP Connections: every live OAuth grant, with a revoke button.
 */
class Connections extends Action implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = 'Magenx_AiMcp::oauth_manage';

    /**
     * @param Context $context
     * @param PageFactory $pageFactory
     */
    public function __construct(
        Context $context,
        private readonly PageFactory $pageFactory
    ) {
        parent::__construct($context);
    }

    /**
     * @return Page
     */
    public function execute(): Page
    {
        $page = $this->pageFactory->create();
        $page->setActiveMenu('Magenx_AiMcp::oauth_connections');
        $page->getConfig()->getTitle()->prepend(__('AI MCP Connections'));

        return $page;
    }
}
