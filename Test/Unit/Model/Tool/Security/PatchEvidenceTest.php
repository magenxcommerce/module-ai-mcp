<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Test\Unit\Model\Tool\Security;

use Magenx\AiMcp\Model\Tool\Security\PatchEvidence;
use Magento\Framework\Filesystem;
use Magento\Framework\Filesystem\Directory\ReadInterface;
use Magento\Framework\Serialize\Serializer\Json;
use PHPUnit\Framework\TestCase;

/**
 * Reading what an installation records about its patches.
 *
 * The distinctions that matter are the ones an agent will repeat to a
 * merchant: applied (composer-patches recorded it), declared (composer.json
 * asks for it, which proves nothing about the last install), present (a file
 * in m2-hotfixes/), and nothing found — which must never read as "missing",
 * and which a hardened store that refuses the read must not produce silently.
 *
 * @see PatchEvidence
 */
class PatchEvidenceTest extends TestCase
{
    /**
     * @return void
     */
    public function testComposerPatchesOneRecordsCountAsApplied(): void
    {
        $evidence = $this->evidence([
            'vendor/composer/installed.json' => json_encode(['packages' => [
                [
                    'name' => 'magento/module-catalog',
                    'extra' => ['patches_applied' => [
                        'Adobe isolated patch VULN-39341' => 'patches/VULN-39341_248-p3.patch',
                    ]],
                ],
                ['name' => 'magento/module-cms'],
            ]]),
        ]);

        $match = $evidence->match(['VULN-39341', 'APSB26-146']);

        $this->assertSame(PatchEvidence::APPLIED, $match['status']);
        $this->assertSame('magento/module-catalog', $match['matches'][0]['package']);
    }

    /**
     * @return void
     */
    public function testComposerPatchesTwoLockCountsAsApplied(): void
    {
        $evidence = $this->evidence([
            'patches.lock.json' => json_encode(['patches' => [
                'magento/module-sales' => [
                    ['description' => 'APSB26-73 security fix', 'url' => 'patches/2026_jul.patch'],
                ],
            ]]),
        ]);

        $this->assertSame(PatchEvidence::APPLIED, $evidence->match(['2026-jul'])['status']);
        $this->assertSame(PatchEvidence::APPLIED, $evidence->match(['APSB26-73'])['status']);
    }

    /**
     * A declaration is what the next install will do, not what the last one
     * did, so it never reads as applied.
     *
     * @return void
     */
    public function testComposerJsonAndItsPatchesFileOnlyDeclare(): void
    {
        $evidence = $this->evidence([
            'composer.json' => json_encode(['extra' => [
                'patches' => ['magento/framework' => ['VULN-39341 hotfix' => 'patches/vuln.patch']],
                'patches-file' => './patches.json',
            ]]),
            'patches.json' => json_encode(['patches' => [
                'magento/module-customer' => ['Adobe 2026-aug isolated patch' => 'patches/aug.patch'],
            ]]),
        ]);

        $this->assertSame(PatchEvidence::DECLARED, $evidence->match(['VULN-39341'])['status']);
        $this->assertSame(PatchEvidence::DECLARED, $evidence->match(['2026-aug'])['status']);
        $this->assertContains(
            ['source' => 'composer_patches_file', 'path' => 'patches.json', 'state' => 'read', 'entries' => 1],
            $evidence->collect()['sources']
        );
    }

    /**
     * @return void
     */
    public function testAFileInTheHotfixDirectoryIsPresent(): void
    {
        $evidence = $this->evidence(['m2-hotfixes/VULN-39341_248-p3.patch' => 'diff --git a/x b/x']);

        $match = $evidence->match(['VULN-39341']);

        $this->assertSame(PatchEvidence::PRESENT, $match['status']);
        $this->assertSame('VULN-39341_248-p3.patch', $match['matches'][0]['description']);
    }

    /**
     * When the same patch is both declared and recorded, the record wins.
     *
     * @return void
     */
    public function testTheStrongestRecordDecides(): void
    {
        $evidence = $this->evidence([
            'composer.json' => json_encode(['extra' => ['patches' => [
                'magento/framework' => ['VULN-39341' => 'patches/vuln.patch'],
            ]]]),
            'patches.lock.json' => json_encode(['patches' => [
                'magento/framework' => [['description' => 'VULN-39341', 'url' => 'patches/vuln.patch']],
            ]]),
        ]);

        $this->assertSame(PatchEvidence::APPLIED, $evidence->match(['VULN-39341'])['status']);
    }

    /**
     * @return void
     */
    public function testNothingFoundIsNoEvidenceAndEverySourceIsAccountedFor(): void
    {
        $evidence = $this->evidence([]);

        $this->assertSame(PatchEvidence::NO_EVIDENCE, $evidence->match(['VULN-39341'])['status']);
        $this->assertSame(
            ['composer_patches_v1', 'composer_patches_v2', 'composer_json', 'm2_hotfixes'],
            array_column($evidence->collect()['sources'], 'source')
        );
        $this->assertSame(['absent'], array_values(array_unique(array_column(
            $evidence->collect()['sources'],
            'state'
        ))));
    }

    /**
     * A hardened store that keeps the PHP user out of a file has to say so,
     * not report that there were no patches in it.
     *
     * @return void
     */
    public function testARefusedReadIsReportedAsUnreadable(): void
    {
        $evidence = $this->evidence(['composer.json' => '{}'], ['composer.json']);

        $sources = array_column($evidence->collect()['sources'], 'state', 'source');

        $this->assertSame('unreadable', $sources['composer_json']);
    }

    /**
     * @return void
     */
    public function testACustomVendorPathIsFollowed(): void
    {
        $evidence = $this->evidence([
            'app/etc/vendor_path.php' => "<?php\nreturn './lib/vendor';\n",
            'lib/vendor/composer/installed.json' => json_encode([[
                'name' => 'magento/framework',
                'extra' => ['patches_applied' => ['2026-sep' => 'patches/sep.patch']],
            ]]),
        ]);

        $this->assertSame(PatchEvidence::APPLIED, $evidence->match(['2026-sep'])['status']);
        $this->assertSame('lib/vendor/composer/installed.json', $evidence->collect()['sources'][0]['path']);
    }

    /**
     * @param array<string, string> $files Path relative to the Magento root => contents.
     * @param string[] $refused Paths whose read throws, as a permission refusal does.
     * @return PatchEvidence
     */
    private function evidence(array $files, array $refused = []): PatchEvidence
    {
        $isFile = static fn (string $path): bool => array_key_exists($path, $files);
        $isDir = static function (string $path) use ($files): bool {
            foreach (array_keys($files) as $file) {
                if (str_starts_with($file, rtrim($path, '/') . '/')) {
                    return true;
                }
            }

            return false;
        };

        $root = $this->createMock(ReadInterface::class);
        $root->method('isFile')->willReturnCallback($isFile);
        $root->method('isExist')->willReturnCallback(
            static fn ($path = null): bool => $isFile((string) $path) || $isDir((string) $path)
        );
        $root->method('stat')->willReturnCallback(
            static fn (string $path): array => ['size' => strlen($files[$path] ?? '')]
        );
        $root->method('readFile')->willReturnCallback(
            static function (string $path) use ($files, $refused): string {
                if (in_array($path, $refused, true)) {
                    throw new \RuntimeException('Permission denied');
                }

                return $files[$path];
            }
        );
        $root->method('read')->willReturnCallback(
            static fn ($path = null): array => array_values(array_filter(
                array_keys($files),
                static fn (string $file): bool => str_starts_with($file, rtrim((string) $path, '/') . '/')
            ))
        );

        $filesystem = $this->createMock(Filesystem::class);
        $filesystem->method('getDirectoryRead')->willReturn($root);

        return new PatchEvidence($filesystem, new Json());
    }
}
