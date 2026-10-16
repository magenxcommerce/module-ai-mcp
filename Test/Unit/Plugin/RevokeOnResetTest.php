<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Test\Unit\Plugin;

use Magenx\AiMcp\Model\OAuth\Storage;
use Magenx\AiMcp\Plugin\TwoFactorAuth\RevokeOnReset;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Connections approved behind a second factor end when that factor is reset.
 *
 * @see RevokeOnReset
 */
class RevokeOnResetTest extends TestCase
{
    /**
     * @return void
     */
    public function testResetRevokesTheAdminsGrantsAndKeepsTheResult(): void
    {
        $storage = $this->createMock(Storage::class);
        $storage->expects($this->once())->method('revokeAdmin')->with(7)->willReturn(1);
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('info');

        $result = (new RevokeOnReset($storage, $logger))
            ->afterResetProviderConfig(new \stdClass(), true, 7, 'google');

        $this->assertTrue($result);
    }

    /**
     * @return void
     */
    public function testNothingToRevokeLogsNothing(): void
    {
        $storage = $this->createMock(Storage::class);
        $storage->method('revokeAdmin')->willReturn(0);
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->never())->method('info');

        (new RevokeOnReset($storage, $logger))->afterResetProviderConfig(new \stdClass(), true, 7, 'google');
    }
}
