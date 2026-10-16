<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\OAuth;

use Magento\Framework\Stdlib\Cookie\CookieMetadataFactory;
use Magento\Framework\Stdlib\CookieManagerInterface;

/**
 * Remembers, in the admin's browser, which authorization request is waiting for them.
 *
 * Magento's admin login and its two-factor check both finish by sending the
 * browser to the admin start-up page, so the consent URL the client opened is
 * lost on the way in. The authorize endpoint therefore also drops the request
 * id in a short-lived cookie, and {@see \Magenx\AiMcp\Plugin\Backend\StartupPageUrl}
 * makes the consent page the start-up page while it is set. The cookie is
 * not a credential — the request it names can only be decided by a signed-in
 * admin — so it is only HttpOnly and same-site.
 */
class PendingConsent
{
    public const COOKIE_NAME = 'magenx_mcp_oauth';

    /**
     * @param CookieManagerInterface $cookieManager
     * @param CookieMetadataFactory $cookieMetadataFactory
     */
    public function __construct(
        private readonly CookieManagerInterface $cookieManager,
        private readonly CookieMetadataFactory $cookieMetadataFactory
    ) {
    }

    /**
     * @param string $requestId
     * @return void
     */
    public function remember(string $requestId): void
    {
        $metadata = $this->cookieMetadataFactory->createPublicCookieMetadata()
            ->setDuration(AuthorizationService::REQUEST_LIFETIME)
            ->setPath('/')
            ->setHttpOnly(true)
            ->setSecure(true)
            ->setSameSite('Lax');

        $this->cookieManager->setPublicCookie(self::COOKIE_NAME, $requestId, $metadata);
    }

    /**
     * The remembered request id, if it looks like one.
     *
     * @return string|null
     */
    public function get(): ?string
    {
        $value = (string) $this->cookieManager->getCookie(self::COOKIE_NAME);

        return preg_match('/^[0-9a-f]{64}$/', $value) === 1 ? $value : null;
    }

    /**
     * @return void
     */
    public function forget(): void
    {
        $this->cookieManager->deleteCookie(
            self::COOKIE_NAME,
            $this->cookieMetadataFactory->createCookieMetadata()->setPath('/')
        );
    }
}
