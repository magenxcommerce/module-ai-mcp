<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Audit;

use Magenx\AdminActivity\Model\Activity\ExternalActor;
use Magenx\AdminActivity\Model\Activity\ExternalActorScope;
use Magenx\AiMcp\Model\Auth\Identity;
use Magento\Authorization\Model\UserContextInterface;
use Magento\User\Model\UserFactory;

/**
 * Puts the changes a write tool makes into the admin activity log, under the caller that made them.
 *
 * Magenx_AdminActivity only sees the admin panel on its own: its observers are
 * registered for the admin area and key on the admin session, and this
 * endpoint has neither. Running the tool inside its ExternalActorScope makes
 * every tracked entity the tool commits land in the same grid as an admin's
 * edits, with field-level before and after values, tagged `source = mcp` and
 * naming:
 *
 *  - the user: the admin (OAuth connection or admin-user token) or
 *    `integration:<name>`;
 *  - the connector: the OAuth client or the integration;
 *  - the role: the admin's role at the time, or the integration, whose
 *    resources are its own rather than a shared role's.
 *
 * Only write tools are wrapped. The log records entity changes, which reads
 * do not make, and looking the admin up on every read would be wasted work.
 */
class AdminActivityRecorder
{
    public const SOURCE = 'mcp';

    /**
     * @param ExternalActorScope $scope
     * @param UserFactory $userFactory
     */
    public function __construct(
        private readonly ExternalActorScope $scope,
        private readonly UserFactory $userFactory
    ) {
    }

    /**
     * Run a write tool so that what it changes is recorded as this caller's.
     *
     * @param Identity $identity
     * @param string $toolName
     * @param callable(): array<string, mixed> $work
     * @return array<string, mixed>
     */
    public function run(Identity $identity, string $toolName, callable $work): array
    {
        return $this->scope->run($this->actor($identity, $toolName), $work);
    }

    /**
     * Describe the caller the way the activity log stores it.
     *
     * @param Identity $identity
     * @param string $toolName
     * @return ExternalActor
     */
    public function actor(Identity $identity, string $toolName): ExternalActor
    {
        $action = 'mcp/' . $toolName;
        $connector = $identity->getConnector();

        if ($identity->getUserType() !== UserContextInterface::USER_TYPE_ADMIN) {
            $name = $connector ?? $identity->getLabel();

            return new ExternalActor(
                null,
                'integration:' . $name,
                self::SOURCE,
                $name,
                sprintf('Integration "%s"', $name),
                $action
            );
        }

        $user = $this->userFactory->create()->load($identity->getUserId());
        $username = (string) $user->getUserName();

        return new ExternalActor(
            $identity->getUserId(),
            $username !== '' ? $username : $identity->getLabel(),
            self::SOURCE,
            $connector ?? 'admin token',
            $this->roleName($user),
            $action
        );
    }

    /**
     * The admin's role name, or null when it cannot be read.
     *
     * @param \Magento\User\Model\User $user
     * @return string|null
     */
    private function roleName($user): ?string
    {
        try {
            $role = $user->getRole();
            $name = $role !== null ? (string) $role->getRoleName() : '';
        } catch (\Throwable) {
            return null;
        }

        return $name !== '' ? $name : null;
    }
}
