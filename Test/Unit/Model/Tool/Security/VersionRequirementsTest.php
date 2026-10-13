<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Test\Unit\Model\Tool\Security;

use Magenx\AiMcp\Model\Tool\Security\VersionRequirements;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Measured versions against a release's listed ones.
 *
 * The lists are tested lines, not ranges, so the thing worth pinning is the
 * prefix rule — "8.4" covers 8.4.22 and "11.4" does not cover 11.40 — and that
 * entries naming another product are never mistaken for this one's version.
 *
 * @see VersionRequirements
 */
class VersionRequirementsTest extends TestCase
{
    /**
     * @return array<string, array{string, string[], string}>
     */
    public static function comparisons(): array
    {
        return [
            'patch level inside a listed minor' => ['8.4.22', ['8.3', '8.4'], VersionRequirements::SUPPORTED],
            'any minor of a listed major' => ['3.1.0', ['3'], VersionRequirements::SUPPORTED],
            'vendor suffix on the database version' => [
                '11.4.10-MariaDB-ubu2404',
                ['11.4', '11.8'],
                VersionRequirements::SUPPORTED,
            ],
            'product name in front of the version' => ['Opensearch 2.19.5', ['3'], VersionRequirements::OLDER],
            'below every listed line' => ['8.4.22', ['8.5'], VersionRequirements::OLDER],
            'above every listed line' => ['8.6.1', ['8.1'], VersionRequirements::NEWER],
            'between two listed lines' => ['11.6.2', ['11.4', '11.8'], VersionRequirements::NOT_LISTED],
            'a two-digit minor is not its one-digit prefix' => ['11.40.1', ['11.4'], VersionRequirements::NEWER],
            'only non-version entries' => ['7.1.0', ['supported', 'redis-7.1'], VersionRequirements::NOT_COMPARABLE],
            'an unreadable installed version' => ['n/a', ['8.4'], VersionRequirements::NOT_COMPARABLE],
        ];
    }

    /**
     * @param string $installed
     * @param string[] $required
     * @param string $expected
     * @return void
     */
    #[DataProvider('comparisons')]
    public function testCompare(string $installed, array $required, string $expected): void
    {
        $this->assertSame($expected, (new VersionRequirements())->compare($installed, $required));
    }

    /**
     * "redis-7.1" is the ElastiCache line, not Redis 7.1 on this host.
     *
     * @return void
     */
    public function testAnotherProductsLineIsNotReadAsAVersion(): void
    {
        $requirements = new VersionRequirements();

        $this->assertNull($requirements->normalize('redis-7.1'));
        $this->assertSame('2.19.5', $requirements->normalize('Opensearch 2.19.5'));
        $this->assertSame('8.4', $requirements->normalize('8.4'));
    }
}
