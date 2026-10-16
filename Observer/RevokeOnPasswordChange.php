<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Observer;

use Magenx\AiMcp\Model\OAuth\Storage;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\User\Model\User;
use Psr\Log\LoggerInterface;

/**
 * Ends every OAuth connection an admin holds when their password changes.
 *
 * A password reset is what an operator does after a suspected compromise,
 * and it has to cut off what the compromised account already connected, not
 * only future sign-ins. Deactivation, locking, deletion and role changes need
 * nothing here: each token is checked against the live admin row and role on
 * every call.
 */
class RevokeOnPasswordChange implements ObserverInterface
{
    /**
     * @param Storage $storage
     * @param LoggerInterface $auditLogger
     */
    public function __construct(
        private readonly Storage $storage,
        private readonly LoggerInterface $auditLogger
    ) {
    }

    /**
     * @param Observer $observer
     * @return void
     */
    public function execute(Observer $observer): void
    {
        $user = $observer->getEvent()->getData('data_object');
        if (!$user instanceof User || $user->isObjectNew() || !$user->dataHasChangedFor('password')) {
            return;
        }

        $revoked = $this->storage->revokeAdmin((int) $user->getId());
        if ($revoked > 0) {
            $this->auditLogger->info('[magenx-mcp] oauth grants revoked on password change', [
                'admin_user_id' => (int) $user->getId(),
                'revoked' => $revoked,
            ]);
        }
    }
}
