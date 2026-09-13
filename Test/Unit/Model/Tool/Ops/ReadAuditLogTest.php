<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Test\Unit\Model\Tool\Ops;

use Magenx\AiMcp\Model\Tool\Ops\ReadAuditLog;
use Magento\Framework\Filesystem;
use Magento\Framework\Filesystem\Directory\ReadInterface as DirectoryRead;
use Magento\Framework\Filesystem\File\ReadInterface as FileRead;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Reading this server's own audit log.
 *
 * The log is the record of what has been changed through the endpoint, so the
 * thing to pin down is that no argument can turn this into a way of reading
 * some other file: the name is fixed, and the only inputs are how many lines to
 * return and what to filter them by.
 *
 * @see ReadAuditLog
 */
class ReadAuditLogTest extends TestCase
{
    private DirectoryRead&MockObject $log;
    private ReadAuditLog $tool;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        $this->log = $this->createMock(DirectoryRead::class);

        $filesystem = $this->createMock(Filesystem::class);
        $filesystem->method('getDirectoryRead')->willReturn($this->log);

        $this->tool = new ReadAuditLog($filesystem);
    }

    /**
     * @return void
     */
    public function testItIsAReadBehindTheModulesOwnResource(): void
    {
        $this->assertFalse($this->tool->isWrite());
        $this->assertSame('Magenx_AiMcp::ops', $this->tool->getAclResource());
    }

    /**
     * No path argument exists, so there is nothing to point somewhere else.
     *
     * @return void
     */
    public function testTheSchemaOffersNoWayToNameAFile(): void
    {
        $schema = $this->tool->getInputSchema();

        $this->assertSame(['lines', 'contains'], array_keys($schema['properties']));
        $this->assertFalse($schema['additionalProperties']);
    }

    /**
     * @return void
     */
    public function testAnAbsentLogIsTheOrdinaryStateRatherThanAnError(): void
    {
        $this->log->method('isExist')->willReturn(false);

        $result = $this->tool->execute([]);

        $this->assertFalse($result['exists']);
        $this->assertSame([], $result['lines']);
        $this->assertSame('magenx_ai_mcp.log', $result['file']);
    }

    /**
     * @return void
     */
    public function testTheMostRecentLinesAreReturned(): void
    {
        $this->logHolds("first\nsecond\nthird\nfourth\n");

        $result = $this->tool->execute(['lines' => 2]);

        $this->assertSame(['third', 'fourth'], $result['lines']);
        $this->assertTrue($result['truncated']);
    }

    /**
     * @return void
     */
    public function testTheLineCountIsCapped(): void
    {
        $this->logHolds("a\nb\n");

        // Well over the tool's own maximum; it must clamp rather than obey.
        $result = $this->tool->execute(['lines' => 99999]);

        $this->assertSame(['a', 'b'], $result['lines']);
        $this->assertFalse($result['truncated']);
    }

    /**
     * @return void
     */
    public function testLinesCanBeFilteredCaseInsensitively(): void
    {
        $this->logHolds("write update_product ok\nwrite DELETE_PRODUCT refused\nwrite get ok\n");

        $result = $this->tool->execute(['contains' => 'delete_product']);

        $this->assertSame(['write DELETE_PRODUCT refused'], $result['lines']);
    }

    /**
     * @param string $contents
     * @return void
     */
    private function logHolds(string $contents): void
    {
        $this->log->method('isExist')->willReturn(true);
        $this->log->method('stat')->willReturn(['size' => strlen($contents)]);

        $offset = 0;
        $file = $this->createMock(FileRead::class);
        $file->method('eof')->willReturnCallback(
            static function () use ($contents, &$offset): bool {
                return $offset >= strlen($contents);
            }
        );
        $file->method('read')->willReturnCallback(
            static function (int $length) use ($contents, &$offset): string {
                $chunk = substr($contents, $offset, $length);
                $offset += strlen($chunk);

                return $chunk;
            }
        );

        $this->log->method('openFile')->willReturn($file);
    }
}
