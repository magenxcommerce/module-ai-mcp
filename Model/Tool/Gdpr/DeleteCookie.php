<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\Gdpr;

use Magenx\AiMcp\Model\Tool\AbstractTool;
use Magenx\Gdpr\Model\CookieFactory;
use Magenx\Gdpr\Model\ResourceModel\Cookie as CookieResource;
use Magento\Framework\Exception\LocalizedException;

/**
 * Undeclare a cookie.
 *
 * Deleting the declaration does not stop the cookie being set — it only stops
 * the policy mentioning it, which is the wrong direction to be wrong in. The
 * description says so, and points at `is_active` for the case where a cookie is
 * genuinely gone and the record should stay.
 */
class DeleteCookie extends AbstractTool
{
    /**
     * @param CookieFactory $cookieFactory
     * @param CookieResource $cookieResource
     */
    public function __construct(
        private readonly CookieFactory $cookieFactory,
        private readonly CookieResource $cookieResource
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'delete_gdpr_cookie';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Remove a cookie from the consent registry. This changes the declaration only — it '
            . 'does not stop anything setting the cookie, so removing an entry for a cookie the '
            . 'site still sets leaves the policy understating what happens. Use it when a script '
            . 'has genuinely been removed; set is_active false instead if you only want it off the '
            . 'published list.';
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'cookie_id' => [
                    'type' => 'integer',
                    'description' => 'The declaration to remove; list_gdpr_cookies reports the ids.',
                ],
            ],
            'required' => ['cookie_id'],
            'additionalProperties' => false,
        ];
    }

    /**
     * @inheritDoc
     */
    public function getAclResource(): string
    {
        return 'Magenx_Gdpr::cookies';
    }

    /**
     * @inheritDoc
     */
    public function isWrite(): bool
    {
        return true;
    }

    /**
     * @inheritDoc
     */
    public function execute(array $arguments): array
    {
        $cookieId = $this->requireInt($arguments, 'cookie_id');

        $cookie = $this->cookieFactory->create();
        $this->cookieResource->load($cookie, $cookieId);
        if (!$cookie->getId()) {
            throw new LocalizedException(__('No declared cookie exists with cookie_id %1.', $cookieId));
        }

        $name = (string) $cookie->getData('name');
        $this->cookieResource->delete($cookie);

        return [
            'deleted' => true,
            'tool' => $this->getName(),
            'cookie_id' => $cookieId,
            'name' => $name,
        ];
    }
}
