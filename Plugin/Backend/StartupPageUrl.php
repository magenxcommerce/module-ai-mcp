<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Plugin\Backend;

use Magenx\AiMcp\Model\OAuth\PendingConsent;
use Magento\Backend\Model\UrlInterface;
use Magento\Framework\AuthorizationInterface;

/**
 * Sends an admin who signed in to approve an AI client straight to the consent page.
 *
 * Magento ends the admin login, and each two-factor provider ends its check,
 * by redirecting to the start-up page rather than to the URL the browser
 * first asked for. While a consent request is pending in this browser, and
 * only for an admin allowed to grant one, that start-up page is the consent
 * page. Everyone else, and this admin once they have decided, gets the
 * configured start-up page as before.
 */
class StartupPageUrl
{
    public const CONSENT_ROUTE = 'magenx_aimcp/oauth/consent';
    public const GRANT_RESOURCE = 'Magenx_AiMcp::oauth_grant';

    /**
     * @param PendingConsent $pendingConsent
     * @param AuthorizationInterface $authorization
     */
    public function __construct(
        private readonly PendingConsent $pendingConsent,
        private readonly AuthorizationInterface $authorization
    ) {
    }

    /**
     * @param UrlInterface $subject
     * @param string $result
     * @return string
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function afterGetStartupPageUrl(UrlInterface $subject, $result)
    {
        if ($this->pendingConsent->get() === null) {
            return $result;
        }

        try {
            return $this->authorization->isAllowed(self::GRANT_RESOURCE) ? self::CONSENT_ROUTE : $result;
        } catch (\Throwable) {
            // No admin resolved yet (the login page asks for this URL too):
            // nothing to redirect.
            return $result;
        }
    }
}
