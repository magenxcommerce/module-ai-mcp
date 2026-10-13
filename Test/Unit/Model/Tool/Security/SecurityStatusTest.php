<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Test\Unit\Model\Tool\Security;

use Magenx\AiMcp\Model\Tool\Security\AdvisoryClient;
use Magenx\AiMcp\Model\Tool\Security\InstalledRelease;
use Magenx\AiMcp\Model\Tool\Security\PatchEvidence;
use Magenx\AiMcp\Model\Tool\Security\SecurityStatus;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Joining the bulletins open against a release with this store's patch records.
 *
 * The fixture is the advisory API's answer for 2.4.8-p5 in October 2026, cut
 * to what the tool reads: four bulletins, each closed by one isolated patch.
 *
 * @see SecurityStatus
 */
class SecurityStatusTest extends TestCase
{
    private InstalledRelease&MockObject $release;
    private AdvisoryClient&MockObject $advisories;
    private PatchEvidence&MockObject $evidence;

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
        $this->evidence = $this->createMock(PatchEvidence::class);
        $this->evidence->method('collect')->willReturn(['sources' => [], 'entries' => []]);
    }

    /**
     * @return void
     */
    public function testItIsAnOpenWorldReadBehindTheDiagnosticsResource(): void
    {
        $tool = $this->tool();

        $this->assertFalse($tool->isWrite());
        $this->assertSame('Magenx_AiMcp::ops', $tool->getAclResource());
        $this->assertTrue($tool->getAnnotations()['openWorldHint']);
        $this->assertTrue($tool->getAnnotations()['readOnlyHint']);
    }

    /**
     * With no records at all, every bulletin is open and the patches come back
     * oldest first — the order Adobe's non-cumulative patches go on in.
     *
     * @return void
     */
    public function testWithoutRecordsEveryBulletinIsOpenAndTheOrderIsOldestFirst(): void
    {
        $this->advisories->method('getVersion')->willReturn($this->lookup());
        $this->evidence->method('match')->willReturn(['status' => PatchEvidence::NO_EVIDENCE, 'matches' => []]);

        $result = $this->tool()->execute([]);

        $this->assertSame('vulnerable', $result['status']);
        $this->assertSame(['2026-jul', '2026-aug', 'VULN-39341', '2026-sep'], $result['apply_order']);
        $this->assertSame(['no_evidence'], array_values(array_unique(array_column(
            $result['open_bulletins'],
            'local_status'
        ))));
        $this->assertSame(['APSB26-49'], $result['fixed_by_installed_release']);
        $this->assertSame('2028-04-11', $result['lifecycle']['eol_date']);
    }

    /**
     * @return void
     */
    public function testRecordedPatchesCloseTheirBulletins(): void
    {
        $this->advisories->method('getVersion')->willReturn($this->lookup());
        $this->evidence->method('match')->willReturnCallback(
            static fn (array $ids): array => [
                'status' => in_array($ids[0], ['2026-jul', '2026-aug', 'VULN-39341', '2026-sep'], true)
                    ? PatchEvidence::APPLIED
                    : PatchEvidence::NO_EVIDENCE,
                'matches' => [],
            ]
        );

        $result = $this->tool()->execute([]);

        $this->assertSame('patched_per_local_records', $result['status']);
        $this->assertSame([], $result['apply_order']);
    }

    /**
     * Declared in composer.json but not recorded as applied is not patched.
     *
     * @return void
     */
    public function testADeclaredPatchIsOnlyPossiblyPatched(): void
    {
        $this->advisories->method('getVersion')->willReturn($this->lookup());
        $this->evidence->method('match')->willReturnCallback(
            static fn (array $ids): array => [
                'status' => $ids[0] === 'VULN-39341' ? PatchEvidence::DECLARED : PatchEvidence::APPLIED,
                'matches' => [],
            ]
        );

        $result = $this->tool()->execute([]);

        $this->assertSame('possibly_patched', $result['status']);
        $this->assertSame(['VULN-39341'], $result['apply_order']);
    }

    /**
     * The lookup failing costs the bulletins, not the call: the installed
     * release and local records still come back, with the reason.
     *
     * @return void
     */
    public function testAnUnavailableLookupStillReportsTheRelease(): void
    {
        $this->advisories->method('getVersion')->willReturn(null);
        $this->advisories->method('getLastError')->willReturn('magento.watch did not answer: timeout');

        $result = $this->tool()->execute([]);

        $this->assertSame('unknown', $result['status']);
        $this->assertFalse($result['advisories']['available']);
        $this->assertSame('magento.watch did not answer: timeout', $result['advisories']['reason']);
        $this->assertSame('2.4.8-p5', $result['installed']['version']);
    }

    /**
     * @return SecurityStatus
     */
    private function tool(): SecurityStatus
    {
        return new SecurityStatus($this->release, $this->advisories, $this->evidence);
    }

    /**
     * @return array{data: array<string, mixed>, fetched_at: string, cached: bool}
     */
    private function lookup(): array
    {
        $bulletin = static fn (string $id, string $date, string $patch): array => [
            'id' => $id,
            'severity' => 'critical',
            'publishedDate' => $date,
            'cves' => ['CVE-2026-00000'],
            'fixedIn' => null,
            'isolatedPatches' => [['id' => $patch, 'appliesTo' => ['2.4.8-p5'], 'url' => 'https://example.test']],
        ];

        return [
            'data' => [
                'version' => '2.4.8-p5',
                'releaseDate' => '2026-05-12',
                'eolDate' => '2028-04-11',
                'statusLabel' => 'supported',
                'isEOLVersion' => false,
                'security' => [
                    'status' => 'vulnerable',
                    'vulnerableTo' => [
                        $bulletin('APSB26-138', '2026-09-08', '2026-sep'),
                        $bulletin('APSB26-146', '2026-09-07', 'VULN-39341'),
                        $bulletin('APSB26-92', '2026-08-11', '2026-aug'),
                        $bulletin('APSB26-73', '2026-07-14', '2026-jul'),
                    ],
                    'fixedBy' => [['id' => 'APSB26-49', 'fixedIn' => '2.4.8-p5']],
                    'recommendedVersion' => '2.4.8-p5',
                ],
            ],
            'fetched_at' => '2026-10-13 10:19:18 UTC',
            'cached' => false,
        ];
    }
}
