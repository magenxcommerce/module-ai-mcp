<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Test\Unit\Observer;

use Magenx\AiMcp\Model\OAuth\Storage;
use Magenx\AiMcp\Observer\RevokeOnPasswordChange;
use Magento\Framework\Event;
use Magento\Framework\Event\Observer;
use Magento\User\Model\User;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * A password reset after a suspected compromise must end what the account already connected.
 *
 * @see RevokeOnPasswordChange
 */
class RevokeOnPasswordChangeTest extends TestCase
{
    /**
     * @return void
     */
    public function testPasswordChangeRevokesTheAdminsGrants(): void
    {
        $storage = $this->createMock(Storage::class);
        $storage->expects($this->once())->method('revokeAdmin')->with(7)->willReturn(2);

        (new RevokeOnPasswordChange($storage, $this->createMock(LoggerInterface::class)))
            ->execute($this->observer($this->user(isNew: false, passwordChanged: true)));
    }

    /**
     * @return void
     */
    public function testOtherSavesRevokeNothing(): void
    {
        $storage = $this->createMock(Storage::class);
        $storage->expects($this->never())->method('revokeAdmin');
        $observer = new RevokeOnPasswordChange($storage, $this->createMock(LoggerInterface::class));

        $observer->execute($this->observer($this->user(isNew: false, passwordChanged: false)));
        $observer->execute($this->observer($this->user(isNew: true, passwordChanged: true)));
    }

    /**
     * @param bool $isNew
     * @param bool $passwordChanged
     * @return User
     */
    private function user(bool $isNew, bool $passwordChanged): User
    {
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn(7);
        $user->method('isObjectNew')->willReturn($isNew);
        $user->method('dataHasChangedFor')->willReturnCallback(
            static fn (string $field): bool => $field === 'password' && $passwordChanged
        );

        return $user;
    }

    /**
     * @param User $user
     * @return Observer
     */
    private function observer(User $user): Observer
    {
        return new Observer(new Event(['data_object' => $user]));
    }
}
