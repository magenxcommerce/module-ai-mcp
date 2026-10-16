<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Plugin\TwoFactorAuth;

use Magenx\AiMcp\Model\OAuth\Storage;
use Psr\Log\LoggerInterface;

/**
 * Ends an admin's OAuth connections when their two-factor configuration is reset.
 *
 * A 2FA reset is what an operator does when an admin's second factor is lost
 * or suspected compromised, and every connection that admin approved was
 * approved behind that factor. Leaving them alive would let a connection
 * outlast the credential that justified it - the same reasoning as revoking
 * on a password change ({@see \Magenx\AiMcp\Observer\RevokeOnPasswordChange}).
 *
 * Declared against Magento_TwoFactorAuth's interface without making that
 * module a dependency: where it is not installed there is no 2FA to reset,
 * and the plugin never runs.
 */
class RevokeOnReset
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
     * @param object $subject \Magento\TwoFactorAuth\Api\UserConfigManagerInterface
     * @param bool $result
     * @param int $userId
     * @param string $providerCode
     * @return bool
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function afterResetProviderConfig($subject, $result, $userId, $providerCode)
    {
        $revoked = $this->storage->revokeAdmin((int) $userId);
        if ($revoked > 0) {
            $this->auditLogger->info('[magenx-mcp] oauth grants revoked on 2FA reset', [
                'admin_user_id' => (int) $userId,
                'provider' => (string) $providerCode,
                'revoked' => $revoked,
            ]);
        }

        return $result;
    }
}
