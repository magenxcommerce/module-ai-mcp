<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Test\Unit\Model\Tool\Attachment;

use Magenx\AiMcp\Model\Tool\Attachment\AttachmentProjector;
use Magenx\AiMcp\Model\Tool\Attachment\ProductCacheCleaner;
use Magenx\AiMcp\Model\Tool\Attachment\SaveProductAttachment;
use Magenx\AiMcp\Model\Tool\Media\Base64Payload;
use Magenx\ProductAttachments\Model\AttachmentPath;
use Magenx\ProductAttachments\Model\AttachmentRepository;
use Magenx\ProductAttachments\Model\Config;
use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Filesystem;
use Magento\Framework\Filesystem\Directory\WriteInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The checks in front of an attachment row, and where an upload lands.
 *
 * What is pinned: nothing is written for a bad argument, an upload never
 * overwrites a file already in the product's folder, and an attachment id is
 * only honoured for the product it belongs to.
 *
 * @see SaveProductAttachment
 */
class SaveProductAttachmentTest extends TestCase
{
    private const PRODUCT_ID = 7;

    private AttachmentRepository&MockObject $repository;

    private Config&MockObject $config;

    private WriteInterface&MockObject $write;

    private ProductCacheCleaner&MockObject $cacheCleaner;

    private SaveProductAttachment $tool;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        $product = $this->createMock(ProductInterface::class);
        $product->method('getId')->willReturn(self::PRODUCT_ID);
        $product->method('getSku')->willReturn('SKU-1');
        $products = $this->createMock(ProductRepositoryInterface::class);
        $products->method('get')->willReturn($product);

        $this->repository = $this->createMock(AttachmentRepository::class);

        $path = $this->createMock(AttachmentPath::class);
        $path->method('sanitizeSegment')->willReturnCallback(
            static fn (string $v): string => (string) preg_replace('/[^A-Za-z0-9._-]/', '_', $v)
        );
        $path->method('getUploadDirectory')->willReturnCallback(
            static fn (string $sku): string => 'product_attachments/' . $sku
        );
        $path->method('toMediaPath')->willReturnCallback(
            static fn (string $file): string => 'product_attachments/' . $file
        );
        $path->method('getExtension')->willReturnCallback(
            static fn (string $name): string => strtolower(pathinfo($name, PATHINFO_EXTENSION))
        );
        $path->method('getFileName')->willReturnCallback(
            static fn (string $p): string => basename($p)
        );

        $this->config = $this->createMock(Config::class);
        $this->config->method('isEnabled')->willReturn(true);
        $this->config->method('getMaxFileSize')->willReturn(10485760);
        $this->config->method('getAllowedExtensions')->willReturn(['pdf', 'png']);
        $this->config->method('isExtensionAllowed')->willReturnCallback(
            static fn (string $ext): bool => in_array($ext, ['pdf', 'png'], true)
        );

        $this->write = $this->createMock(WriteInterface::class);
        $filesystem = $this->createMock(Filesystem::class);
        $filesystem->method('getDirectoryWrite')->willReturn($this->write);

        $this->cacheCleaner = $this->createMock(ProductCacheCleaner::class);

        $this->tool = new SaveProductAttachment(
            $products,
            $this->repository,
            $path,
            $this->config,
            $filesystem,
            new Base64Payload(),
            new AttachmentProjector($this->repository, $path),
            $this->cacheCleaner
        );
    }

    /**
     * @return void
     */
    public function testAnExternalLinkIsStoredWithItsHostAsTheDefaultTitle(): void
    {
        $this->repository->expects(self::once())
            ->method('saveAttachment')
            ->with(null, self::callback(static fn (array $row): bool => $row['type'] === 'external'
                && $row['external_url'] === 'https://example.com/logo.png'
                && $row['title'] === 'example.com'
                && $row['product_id'] === self::PRODUCT_ID))
            ->willReturn(11);
        $this->cacheCleaner->expects(self::once())->method('clean')->with(self::PRODUCT_ID);

        $result = $this->tool->execute([
            'sku' => 'SKU-1',
            'type' => 'external',
            'external_url' => 'https://example.com/logo.png',
        ]);

        self::assertTrue($result['created']);
        self::assertSame(11, $result['attachment']['attachment_id']);
    }

    /**
     * @return void
     */
    public function testANonHttpLinkIsRefusedBeforeAnythingIsWritten(): void
    {
        $this->repository->expects(self::never())->method('saveAttachment');

        $this->expectException(LocalizedException::class);
        $this->tool->execute(['sku' => 'SKU-1', 'type' => 'external', 'external_url' => 'javascript:alert(1)']);
    }

    /**
     * @return void
     */
    public function testAnUploadNeverOverwritesAFileAlreadyInTheFolder(): void
    {
        $this->write->method('isExist')->willReturnCallback(
            static fn (string $p): bool => $p === 'product_attachments/SKU-1/logo.png'
        );
        $this->write->expects(self::once())
            ->method('writeFile')
            ->with('product_attachments/SKU-1/logo_1.png', 'PNGBYTES');
        $this->repository->expects(self::once())
            ->method('saveAttachment')
            ->with(null, self::callback(static fn (array $row): bool => $row['type'] === 'upload'
                && $row['file'] === 'SKU-1/logo_1.png'
                && $row['title'] === 'Logo'))
            ->willReturn(12);

        $this->tool->execute([
            'sku' => 'SKU-1',
            'type' => 'upload',
            'title' => 'Logo',
            'file_content' => ['base64_encoded_data' => base64_encode('PNGBYTES'), 'name' => 'logo.png'],
        ]);
    }

    /**
     * @return void
     */
    public function testAnExtensionTheModuleDoesNotAllowIsRefused(): void
    {
        $this->write->expects(self::never())->method('writeFile');

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('not allowed');
        $this->tool->execute([
            'sku' => 'SKU-1',
            'type' => 'upload',
            'file_content' => ['base64_encoded_data' => base64_encode('<?php'), 'name' => 'shell.php'],
        ]);
    }

    /**
     * @return void
     */
    public function testAnotherProductsAttachmentIsReportedAsMissing(): void
    {
        $this->repository->method('getAttachment')->willReturn(
            ['attachment_id' => 5, 'product_id' => 99, 'type' => 'external']
        );
        $this->repository->expects(self::never())->method('saveAttachment');

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('has no attachment');
        $this->tool->execute(['sku' => 'SKU-1', 'attachment_id' => 5, 'title' => 'x']);
    }

    /**
     * @return void
     */
    public function testAnUploadsFileCannotBeSwappedInPlace(): void
    {
        $this->repository->method('getAttachment')->willReturn(
            ['attachment_id' => 5, 'product_id' => self::PRODUCT_ID, 'type' => 'upload']
        );
        $this->repository->expects(self::never())->method('saveAttachment');

        $this->expectException(LocalizedException::class);
        $this->tool->execute([
            'sku' => 'SKU-1',
            'attachment_id' => 5,
            'file_content' => ['base64_encoded_data' => base64_encode('x'), 'name' => 'a.pdf'],
        ]);
    }

    /**
     * @return void
     */
    public function testAnUpdateChangesOnlyWhatWasPassed(): void
    {
        $this->repository->method('getAttachment')->willReturn(
            ['attachment_id' => 5, 'product_id' => self::PRODUCT_ID, 'type' => 'external', 'title' => 'Old']
        );
        $this->repository->expects(self::once())
            ->method('saveAttachment')
            ->with(5, ['sort_order' => 3]);

        $result = $this->tool->execute(['sku' => 'SKU-1', 'attachment_id' => 5, 'sort_order' => 3]);

        self::assertSame(['sort_order'], $result['changed_fields']);
    }
}
