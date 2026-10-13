<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Test\Unit\Model\Tool\Security;

use Magenx\AiMcp\Model\Tool\Security\SecurityStatus;
use Magenx\AiMcp\Model\Tool\Security\UpgradeCompatibility;
use Magenx\Platform\Model\Security\CompatibilityReport;
use Magenx\Platform\Model\Security\SecurityReport;
use Magento\Framework\Exception\LocalizedException;
use PHPUnit\Framework\TestCase;

/**
 * The two tools are the Platform module's Security and Compatibility reports
 * as data. Every finding is made and tested there; what is pinned here is the
 * hand-over — arguments passed through, refused when malformed rather than
 * cast — and that both sit behind the Platform resource and declare that they
 * reach outside the store.
 *
 * @see SecurityStatus
 * @see UpgradeCompatibility
 */
class SecurityToolsTest extends TestCase
{
    /**
     * @return void
     */
    public function testBothAreOpenWorldReadsBehindThePlatformResource(): void
    {
        $tools = [
            new SecurityStatus($this->createMock(SecurityReport::class)),
            new UpgradeCompatibility($this->createMock(CompatibilityReport::class)),
        ];

        foreach ($tools as $tool) {
            $this->assertFalse($tool->isWrite());
            $this->assertSame('Magenx_Platform::platform', $tool->getAclResource());
            $this->assertTrue($tool->getAnnotations()['openWorldHint']);
            $this->assertTrue($tool->getAnnotations()['readOnlyHint']);
        }
    }

    /**
     * @return void
     */
    public function testSecurityStatusPassesItsArgumentsToTheReport(): void
    {
        $report = $this->createMock(SecurityReport::class);
        $report->expects($this->once())
            ->method('build')
            ->with('magento-commerce', true)
            ->willReturn(['status' => 'vulnerable']);

        $result = (new SecurityStatus($report))->execute([
            'distribution' => 'magento-commerce',
            'list_patch_records' => true,
        ]);

        $this->assertSame(['status' => 'vulnerable'], $result);
    }

    /**
     * @return void
     */
    public function testUpgradeCompatibilityDefaultsToNoOutdatedLookups(): void
    {
        $report = $this->createMock(CompatibilityReport::class);
        $report->expects($this->once())->method('build')->with(null, null, false)->willReturn(['candidates' => []]);

        (new UpgradeCompatibility($report))->execute([]);
    }

    /**
     * @return void
     */
    public function testUpgradeCompatibilityPassesATargetThrough(): void
    {
        $report = $this->createMock(CompatibilityReport::class);
        $report->expects($this->once())->method('build')->with('2.4.9', null, true)->willReturn(['candidates' => []]);

        (new UpgradeCompatibility($report))->execute(['target_version' => '2.4.9', 'include_outdated' => true]);
    }

    /**
     * "false" as a string is refused rather than cast to true.
     *
     * @return void
     */
    public function testAStringBooleanIsRefused(): void
    {
        $report = $this->createMock(CompatibilityReport::class);
        $report->expects($this->never())->method('build');

        $this->expectException(LocalizedException::class);

        (new UpgradeCompatibility($report))->execute(['include_outdated' => 'false']);
    }
}
