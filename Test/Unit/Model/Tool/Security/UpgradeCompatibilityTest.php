<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Test\Unit\Model\Tool\Security;

use Magenx\AiMcp\Model\Tool\Security\AdvisoryClient;
use Magenx\AiMcp\Model\Tool\Security\EnvironmentVersions;
use Magenx\AiMcp\Model\Tool\Security\InstalledRelease;
use Magenx\AiMcp\Model\Tool\Security\UpgradeCompatibility;
use Magenx\AiMcp\Model\Tool\Security\VersionRequirements;
use Magento\Framework\Exception\LocalizedException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * A store's measured services against the releases it could move to.
 *
 * The environment is the one this module was written against: 2.4.8-p5 on PHP
 * 8.4, MariaDB 11.4, OpenSearch 2.19, Redis 8.6 and RabbitMQ 4.1. The release
 * data is the advisory API's, cut to the keys compared. 2.4.9 tests PHP 8.5
 * only, which is the blocker an agent most needs to be told about before
 * recommending it.
 *
 * @see UpgradeCompatibility
 */
class UpgradeCompatibilityTest extends TestCase
{
    private InstalledRelease&MockObject $release;
    private AdvisoryClient&MockObject $advisories;
    private EnvironmentVersions&MockObject $environment;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        $this->release = $this->createMock(InstalledRelease::class);
        $this->release->method('get')->willReturn([
            'version' => '2.4.8-p5',
            'edition' => 'Community',
            'distribution' => 'magento-community',
            'magento_version' => '2.4.8-p5',
        ]);
        $this->advisories = $this->createMock(AdvisoryClient::class);
        $this->environment = $this->createMock(EnvironmentVersions::class);
        $this->environment->method('get')->willReturn([
            'measured' => [
                ['component' => 'php', 'product' => 'php', 'version' => '8.4.22', 'source' => 'runtime'],
                [
                    'component' => 'database',
                    'product' => 'mariadb',
                    'version' => '11.4.10-MariaDB-ubu2404',
                    'source' => 'database',
                ],
                [
                    'component' => 'search',
                    'product' => 'opensearch',
                    'version' => 'Opensearch 2.19.5',
                    'source' => 'platform:opensearch',
                ],
                ['component' => 'cache', 'product' => 'redis', 'version' => '8.6.1', 'source' => 'platform:redis'],
                [
                    'component' => 'queue',
                    'product' => 'rabbitmq',
                    'version' => '4.1.8',
                    'source' => 'platform:rabbitmq',
                ],
            ],
            'unmeasured' => [],
        ]);
    }

    /**
     * @return void
     */
    public function testTheNewestReleaseIsBlockedByPhpAndThePatchLineIsCurrent(): void
    {
        $this->advisories->method('getVersion')->willReturn($this->wrap($this->p5()));
        $this->advisories->method('getSupportedVersions')->willReturn($this->wrap([
            '2.4.8-p4' => ['releaseDate' => '2026-03-10'] + $this->p5(),
            '2.4.8-p5' => $this->p5(),
            '2.4.9' => $this->v249(),
        ]));

        $result = $this->tool()->execute([]);

        $this->assertCount(1, $result['candidates'], 'Only 2.4.9 is newer; p4 is older and p5 is installed.');
        $candidate = $result['candidates'][0];
        $this->assertSame('2.4.9', $candidate['version']);
        $this->assertSame(['latest_release'], $candidate['reasons']);
        $this->assertFalse($candidate['compatible']);
        $this->assertSame(['php'], $candidate['blockers']);
        $this->assertStringContainsString('2.4.9: blocked by php', $result['summary']);
    }

    /**
     * The installed release is checked too: this store already runs search and
     * the queue below what 2.4.8-p5 was tested with, and Redis where the
     * release lists Valkey.
     *
     * @return void
     */
    public function testTheInstalledReleaseIsCheckedAgainstItsOwnRequirements(): void
    {
        $this->advisories->method('getVersion')->willReturn($this->wrap($this->p5()));
        $this->advisories->method('getSupportedVersions')->willReturn($this->wrap([]));

        $current = $this->tool()->execute([])['current'];
        $verdicts = array_column($current['components'], 'verdict', 'component');

        $this->assertTrue($current['compatible']);
        $this->assertSame(VersionRequirements::SUPPORTED, $verdicts['php']);
        $this->assertSame(VersionRequirements::SUPPORTED, $verdicts['database']);
        $this->assertSame(VersionRequirements::OLDER, $verdicts['search']);
        $this->assertSame(VersionRequirements::OLDER, $verdicts['queue']);
        $this->assertSame('product_not_listed', $verdicts['cache']);
        $this->assertSame(['search', 'cache', 'queue'], $current['warnings']);
        $this->assertSame(['composer', 'nginx'], $current['not_checked']);
    }

    /**
     * @return void
     */
    public function testANamedTargetIsTheOnlyCandidate(): void
    {
        $this->advisories->method('getVersion')->willReturnCallback(
            fn (string $distribution, string $version): array => $this->wrap(
                $version === '2.4.9' ? $this->v249() : $this->p5()
            )
        );
        $this->advisories->expects($this->never())->method('getSupportedVersions');

        $result = $this->tool()->execute(['target_version' => '2.4.9']);

        $this->assertSame(['2.4.9'], array_column($result['candidates'], 'version'));
        $this->assertSame(['requested'], $result['candidates'][0]['reasons']);
    }

    /**
     * @return void
     */
    public function testATargetWithNoReleaseDataIsAnError(): void
    {
        $this->advisories->method('getVersion')->willReturnCallback(
            fn (string $distribution, string $version): ?array => $version === '2.4.8-p5'
                ? $this->wrap($this->p5())
                : null
        );
        $this->advisories->method('getLastError')->willReturn('HTTP 404');

        $this->expectException(LocalizedException::class);

        $this->tool()->execute(['target_version' => '2.5.0']);
    }

    /**
     * @return void
     */
    public function testAnUnavailableLookupStillReportsTheEnvironment(): void
    {
        $this->advisories->method('getVersion')->willReturn(null);
        $this->advisories->method('getLastError')->willReturn('switched off');

        $result = $this->tool()->execute([]);

        $this->assertFalse($result['advisories']['available']);
        $this->assertSame([], $result['candidates']);
        $this->assertCount(5, $result['environment']['measured']);
    }

    /**
     * @return UpgradeCompatibility
     */
    private function tool(): UpgradeCompatibility
    {
        return new UpgradeCompatibility(
            $this->release,
            $this->advisories,
            $this->environment,
            new VersionRequirements()
        );
    }

    /**
     * @param array<string, mixed> $data
     * @return array{data: array<string, mixed>, fetched_at: string, cached: bool}
     */
    private function wrap(array $data): array
    {
        return ['data' => $data, 'fetched_at' => '2026-10-13 10:19:18 UTC', 'cached' => false];
    }

    /**
     * @return array<string, mixed>
     */
    private function p5(): array
    {
        return [
            'version' => '2.4.8-p5',
            'releaseDate' => '2026-05-12',
            'eolDate' => '2028-04-11',
            'statusLabel' => 'supported',
            'isLatestVersion' => false,
            'systemRequirements' => [
                'php' => ['8.3', '8.4'],
                'composer' => ['2.10'],
                'mysql' => ['8.4'],
                'mariadb' => ['11.4', '11.8'],
                'opensearch' => ['3'],
                'rabbitmq' => ['4.3'],
                'valkey' => ['8.1'],
                'nginx' => ['1.30'],
                'aws-opensearch' => ['3.1'],
            ],
            'security' => ['status' => 'vulnerable', 'vulnerableTo' => [], 'recommendedVersion' => '2.4.8-p5'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function v249(): array
    {
        return [
            'version' => '2.4.9',
            'releaseDate' => '2026-05-12',
            'eolDate' => '2029-05-12',
            'statusLabel' => 'supported (latest)',
            'isLatestVersion' => true,
            'securitySummary' => ['status' => 'vulnerable', 'requiredPatches' => 4, 'upgradeRequired' => false],
            'systemRequirements' => [
                'php' => ['8.5'],
                'mariadb' => ['11.8', '12.3'],
                'opensearch' => ['3'],
                'rabbitmq' => ['4.3'],
                'valkey' => ['9'],
            ],
        ];
    }
}
