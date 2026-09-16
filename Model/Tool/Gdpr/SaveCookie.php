<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\Gdpr;

use Magenx\AiMcp\Model\Tool\AbstractTool;
use Magenx\Gdpr\Model\CookieFactory;
use Magenx\Gdpr\Model\CookieGroupFactory;
use Magenx\Gdpr\Model\ResourceModel\Cookie as CookieResource;
use Magenx\Gdpr\Model\ResourceModel\CookieGroup as GroupResource;
use Magento\Framework\Exception\LocalizedException;

/**
 * Declare a cookie, or correct one already declared.
 *
 * The registry is what a cookie policy is generated from and what an auditor
 * compares against the site's actual behaviour, so its failure mode is drift:
 * a script added last quarter that nobody declared. Keeping it current is
 * exactly the kind of chore worth handing to an agent.
 */
class SaveCookie extends AbstractTool
{
    /**
     * @param CookieFactory $cookieFactory
     * @param CookieResource $cookieResource
     * @param CookieGroupFactory $groupFactory
     * @param GroupResource $groupResource
     */
    public function __construct(
        private readonly CookieFactory $cookieFactory,
        private readonly CookieResource $cookieResource,
        private readonly CookieGroupFactory $groupFactory,
        private readonly GroupResource $groupResource
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'save_gdpr_cookie';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Declare a cookie in the consent registry, or change one already declared. Omit '
            . 'cookie_id to add one; pass it to edit, in which case only the fields you pass '
            . 'change. The group decides which consent category the cookie is gated behind — '
            . 'putting a tracking cookie in the "necessary" group is how a site ends up setting it '
            . 'before anyone agreed to it, so check list_gdpr_cookie_groups before choosing.';
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
                    'description' => 'The declaration to change. Omit to add a new one.',
                ],
                'group_id' => [
                    'type' => 'integer',
                    'description' => 'Consent category this cookie belongs to; '
                        . 'list_gdpr_cookie_groups reports the ids. Required when adding.',
                ],
                'name' => [
                    'type' => 'string',
                    'description' => 'The cookie name as it is actually set, e.g. "_ga". Required '
                        . 'when adding.',
                ],
                'source' => [
                    'type' => 'string',
                    'description' => 'Where it comes from, e.g. "storefront" or a vendor name.',
                ],
                'purpose' => [
                    'type' => 'string',
                    'description' => 'What it is for, in the words the policy will show a visitor.',
                ],
                'duration_label' => [
                    'type' => 'string',
                    'description' => 'How long it lasts, as text, e.g. "2 years" or "Session".',
                ],
                'is_active' => ['type' => 'boolean', 'description' => 'Whether it appears in the policy.'],
                'sort_order' => ['type' => 'integer', 'description' => 'Position in its group, lower first.'],
            ],
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
        $cookieId = $this->optionalInt($arguments, 'cookie_id');
        $isNew = $cookieId === null;

        $cookie = $this->cookieFactory->create();
        if (!$isNew) {
            $this->cookieResource->load($cookie, $cookieId);
            if (!$cookie->getId()) {
                throw new LocalizedException(__('No declared cookie exists with cookie_id %1.', $cookieId));
            }
        }

        $changed = [];

        if ($isNew) {
            $cookie->setData('name', $this->requireString($arguments, 'name'));
            $cookie->setData('group_id', $this->assertGroupExists($this->requireInt($arguments, 'group_id')));
            $changed[] = 'name';
            $changed[] = 'group_id';
        } else {
            if (array_key_exists('name', $arguments)) {
                $cookie->setData('name', $this->requireString($arguments, 'name'));
                $changed[] = 'name';
            }
            $groupId = $this->optionalInt($arguments, 'group_id');
            if ($groupId !== null) {
                $cookie->setData('group_id', $this->assertGroupExists($groupId));
                $changed[] = 'group_id';
            }
        }

        foreach (['source', 'purpose', 'duration_label'] as $key) {
            if (!array_key_exists($key, $arguments)) {
                continue;
            }
            if ($arguments[$key] !== null && !is_string($arguments[$key])) {
                throw new LocalizedException(__('The "%1" argument must be a string.', $key));
            }
            $cookie->setData($key, $arguments[$key]);
            $changed[] = $key;
        }

        if (array_key_exists('is_active', $arguments)) {
            if (!is_bool($arguments['is_active'])) {
                throw new LocalizedException(
                    __('The "is_active" argument must be true or false, not a string or a number.')
                );
            }
            $cookie->setData('is_active', $arguments['is_active'] ? 1 : 0);
            $changed[] = 'is_active';
        }

        $sortOrder = $this->optionalInt($arguments, 'sort_order');
        if ($sortOrder !== null) {
            $cookie->setData('sort_order', $sortOrder);
            $changed[] = 'sort_order';
        }

        if (!$isNew && $changed === []) {
            throw new LocalizedException(
                __('Nothing to update: pass at least one field besides cookie_id.')
            );
        }

        $this->cookieResource->save($cookie);

        return [
            $isNew ? 'created' : 'updated' => true,
            'tool' => $this->getName(),
            'cookie_id' => (int) $cookie->getId(),
            'name' => $cookie->getData('name'),
            'group_id' => (int) $cookie->getData('group_id'),
            'changed_fields' => $changed,
        ];
    }

    /**
     * Refuse a group that does not exist.
     *
     * Nothing enforces the column, and a cookie in a group nobody can consent
     * to is a cookie the banner will never gate — the exact failure this
     * registry exists to prevent.
     *
     * @param int $groupId
     * @return int
     * @throws LocalizedException
     */
    private function assertGroupExists(int $groupId): int
    {
        $group = $this->groupFactory->create();
        $this->groupResource->load($group, $groupId);

        if (!$group->getId()) {
            throw new LocalizedException(__(
                'No consent group exists with group_id %1. list_gdpr_cookie_groups reports the ids.',
                $groupId
            ));
        }

        return $groupId;
    }
}
