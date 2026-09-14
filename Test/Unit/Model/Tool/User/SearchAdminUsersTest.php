<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Test\Unit\Model\Tool\User;

use Magenx\AiMcp\Model\Tool\User\SearchAdminUsers;
use Magento\User\Model\ResourceModel\User\Collection;
use Magento\User\Model\ResourceModel\User\CollectionFactory;
use Magento\User\Model\User;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Looking up admin users so a ticket can be assigned to one.
 *
 * The row behind this tool carries the password hash and the password-reset
 * token, so what matters is not that the search works but that the projection
 * is a whitelist: a lookup that leaked either would turn "who can I assign this
 * to" into credential disclosure.
 *
 * @see SearchAdminUsers
 */
class SearchAdminUsersTest extends TestCase
{
    private Collection&MockObject $collection;
    private SearchAdminUsers $tool;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        $this->collection = $this->createMock(Collection::class);

        $factory = $this->createMock(CollectionFactory::class);
        $factory->method('create')->willReturn($this->collection);

        $this->tool = new SearchAdminUsers($factory);
    }

    /**
     * @return void
     */
    public function testItIsAReadBehindMagentosOwnUserResource(): void
    {
        $this->assertFalse($this->tool->isWrite());
        $this->assertSame('Magento_User::acl_users', $this->tool->getAclResource());
    }

    /**
     * The whole point of the tool: the id assign_helpdesk_ticket asks for.
     *
     * @return void
     */
    public function testItReportsTheIdTheAssignmentToolTakes(): void
    {
        $this->collection->method('getIterator')->willReturn(new \ArrayIterator([
            $this->user(7, [
                'username' => 'jane.support',
                'firstname' => 'Jane',
                'lastname' => 'Okafor',
                'email' => 'jane@example.com',
                'is_active' => '1',
                'logdate' => '2026-09-13 08:12:44',
            ]),
        ]));
        $this->collection->method('getSize')->willReturn(1);

        $result = $this->tool->execute([]);

        $this->assertSame(1, $result['total_count']);
        $this->assertSame([
            'admin_user_id' => 7,
            'username' => 'jane.support',
            'name' => 'Jane Okafor',
            'email' => 'jane@example.com',
            'is_active' => true,
            'last_login_at' => '2026-09-13 08:12:44',
        ], $result['items'][0]);
    }

    /**
     * Every other column on the row stays on the row.
     *
     * @return void
     */
    public function testItNeverProjectsACredential(): void
    {
        $this->collection->method('getIterator')->willReturn(new \ArrayIterator([
            $this->user(3, [
                'username' => 'ops',
                'firstname' => 'Ops',
                'lastname' => '',
                'email' => 'ops@example.com',
                'is_active' => '1',
                'logdate' => null,
                'password' => '$2y$10$notarealhashbutstillasecret',
                'rp_token' => 'reset-token-value',
                'rp_token_created_at' => '2026-09-01 00:00:00',
                'failures_number' => '2',
                'lock_expires' => '2026-09-02 00:00:00',
            ]),
        ]));

        $row = $this->tool->execute([])['items'][0];

        $this->assertSame(
            ['admin_user_id', 'username', 'name', 'email', 'is_active', 'last_login_at'],
            array_keys($row)
        );
        foreach (['password', 'rp_token', 'rp_token_created_at', 'failures_number', 'lock_expires'] as $secret) {
            $this->assertArrayNotHasKey($secret, $row);
        }
        $this->assertStringNotContainsString('notarealhash', json_encode($row));
        $this->assertStringNotContainsString('reset-token-value', json_encode($row));
    }

    /**
     * A name is one field to a caller, two columns to Magento, and absent on a
     * record that carries neither.
     *
     * @return void
     */
    public function testAnAccountWithNoNameReportsNullRatherThanAnEmptyString(): void
    {
        $this->collection->method('getIterator')->willReturn(new \ArrayIterator([
            $this->user(9, ['username' => 'svc.integration', 'firstname' => null, 'lastname' => null]),
        ]));

        $this->assertNull($this->tool->execute([])['items'][0]['name']);
    }

    /**
     * Free text has to reach all four columns, or searching by email address
     * would silently miss a match on the username.
     *
     * @return void
     */
    public function testAFreeTextQueryMatchesAnyOfTheFourColumns(): void
    {
        $this->collection->expects($this->once())
            ->method('addFieldToFilter')
            ->with(
                ['username', 'firstname', 'lastname', 'email'],
                [
                    ['like' => '%okafor%'],
                    ['like' => '%okafor%'],
                    ['like' => '%okafor%'],
                    ['like' => '%okafor%'],
                ]
            );
        $this->collection->method('getIterator')->willReturn(new \ArrayIterator([]));

        $this->tool->execute(['query' => 'okafor']);
    }

    /**
     * @return void
     */
    public function testTheActiveFilterIsSentAsMagentosOwnFlag(): void
    {
        $this->collection->expects($this->once())
            ->method('addFieldToFilter')
            ->with('is_active', 0);
        $this->collection->method('getIterator')->willReturn(new \ArrayIterator([]));

        $this->tool->execute(['is_active' => false]);
    }

    /**
     * Results are fed to a model, so nothing may ask for an unbounded page.
     *
     * @return void
     */
    public function testAnOversizedPageIsClampedToTheMaximum(): void
    {
        $this->collection->expects($this->once())->method('setPageSize')->with(100);
        $this->collection->method('getIterator')->willReturn(new \ArrayIterator([]));

        $result = $this->tool->execute(['page_size' => 5000]);

        $this->assertSame(100, $result['page_size']);
    }

    /**
     * @param int $id
     * @param array<string, mixed> $data
     * @return User&MockObject
     */
    private function user(int $id, array $data): User&MockObject
    {
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn($id);
        $user->method('getData')->willReturnCallback(
            static fn (?string $key = null, $index = null) => $data[$key] ?? null
        );

        return $user;
    }
}
