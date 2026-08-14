<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Auth;

use Magento\Framework\Exception\LocalizedException;

/**
 * The caller was refused for something other than its credentials.
 *
 * Kept separate from {@see \Magento\Framework\Exception\AuthenticationException}
 * so the controller can answer 403 rather than 401: a source address that is not
 * on the allowlist will not become acceptable if the client tries again with a
 * better token, and telling it otherwise makes a well-behaved MCP client retry
 * forever.
 */
class AccessDeniedException extends LocalizedException
{
}
