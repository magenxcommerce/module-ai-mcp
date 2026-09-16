<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Test\Unit\Model\Tool\MediaGallery;

use Magenx\AiMcp\Model\MediaPathPolicy;
use Magenx\AiMcp\Model\Tool\MediaGallery\DeleteMediaGalleryAsset;
use Magento\Framework\Exception\LocalizedException;
use Magento\MediaContentApi\Api\Data\ContentIdentityInterface;
use Magento\MediaContentApi\Api\GetContentByAssetIdsInterface;
use Magento\MediaGalleryApi\Api\Data\AssetInterface;
use Magento\MediaGalleryApi\Api\DeleteAssetsByPathsInterface;
use Magento\MediaGalleryApi\Api\GetAssetsByPathsInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Deleting a media gallery asset.
 *
 * Two silent failures to prevent, and the tests split along them.
 *
 * The first is the in-use asset. Deleting it removes the file and leaves every
 * reference to it in place, so the storefront shows a broken image and nothing
 * anywhere reports an error. Magento's own admin warns and then does it; this
 * refuses and names what is holding the file.
 *
 * The second is the path. A delete is handed a path the caller chose, and
 * `pub/media` is served straight off the web server, so the same guard uploads
 * pass applies here — which is why it lives on {@see MediaPathPolicy} rather
 * than being written twice.
 *
 * @see DeleteMediaGalleryAsset::execute
 */
class DeleteMediaGalleryAssetTest extends TestCase
{
    private GetAssetsByPathsInterface&MockObject $getAssetsByPaths;
    private GetContentByAssetIdsInterface&MockObject $getContentByAssetIds;
    private DeleteAssetsByPathsInterface&MockObject $deleteAssetsByPaths;
    private DeleteMediaGalleryAsset $tool;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        $this->getAssetsByPaths = $this->createMock(GetAssetsByPathsInterface::class);
        $this->getContentByAssetIds = $this->createMock(GetContentByAssetIdsInterface::class);
        $this->deleteAssetsByPaths = $this->createMock(DeleteAssetsByPathsInterface::class);

        $this->tool = new DeleteMediaGalleryAsset(
            new MediaPathPolicy(),
            $this->getAssetsByPaths,
            $this->getContentByAssetIds,
            $this->deleteAssetsByPaths
        );
    }

    /**
     * @return void
     */
    public function testItIsAWriteBehindTheMediaGalleryResource(): void
    {
        $this->assertTrue($this->tool->isWrite());
        $this->assertSame('Magento_Cms::media_gallery', $this->tool->getAclResource());
    }

    /**
     * @return void
     */
    public function testAnUnusedAssetIsDeleted(): void
    {
        $this->assetAt('wysiwyg/banners/hero.jpg', 12);
        $this->getContentByAssetIds->method('execute')->willReturn([]);
        $this->deleteAssetsByPaths->expects($this->once())
            ->method('execute')
            ->with(['wysiwyg/banners/hero.jpg']);

        $result = $this->tool->execute(['path' => 'wysiwyg/banners/hero.jpg']);

        $this->assertTrue($result['deleted']);
        $this->assertSame(12, $result['asset_id']);
    }

    /**
     * The refusal this tool exists for, and the list that makes it actionable.
     *
     * @return void
     */
    public function testAnAssetInUseIsRefusedAndItsUsersAreNamed(): void
    {
        $this->assetAt('wysiwyg/banners/hero.jpg', 12);
        $this->getContentByAssetIds->method('execute')->willReturn([
            $this->identity('cms_page', '4', 'content'),
            $this->identity('catalog_product', '77', 'description'),
        ]);
        $this->deleteAssetsByPaths->expects($this->never())->method('execute');

        try {
            $this->tool->execute(['path' => 'wysiwyg/banners/hero.jpg']);
            $this->fail('An asset still in use must be refused.');
        } catch (LocalizedException $e) {
            $this->assertStringContainsString('cms_page 4 (content)', $e->getMessage());
            $this->assertStringContainsString('catalog_product 77 (description)', $e->getMessage());
            $this->assertStringContainsString('2 item(s)', $e->getMessage());
        }
    }

    /**
     * @return void
     */
    public function testATraversalPathIsRefusedByThePolicy(): void
    {
        $this->getAssetsByPaths->expects($this->never())->method('execute');
        $this->deleteAssetsByPaths->expects($this->never())->method('execute');

        $this->expectException(LocalizedException::class);

        $this->tool->execute(['path' => 'wysiwyg/../../app/etc/env.php']);
    }

    /**
     * A path outside the CMS gallery root is refused rather than normalised
     * into one that is. Product images live out there and have their own tool,
     * which also keeps the product's gallery consistent.
     *
     * @return void
     */
    public function testAProductImagePathIsRefusedAndPointsAtTheRightTool(): void
    {
        $this->getAssetsByPaths->expects($this->never())->method('execute');

        try {
            $this->tool->execute(['path' => 'catalog/product/h/e/hero.jpg']);
            $this->fail('A path outside the gallery root must be refused.');
        } catch (LocalizedException $e) {
            $this->assertStringContainsString('delete_product_media', $e->getMessage());
        }
    }

    /**
     * The root on its own names no file.
     *
     * @return void
     */
    public function testTheGalleryRootItselfIsRefused(): void
    {
        $this->getAssetsByPaths->expects($this->never())->method('execute');

        $this->expectException(LocalizedException::class);

        $this->tool->execute(['path' => 'wysiwyg']);
    }

    /**
     * @return void
     */
    public function testAnAbsentAssetIsReportedRatherThanSilentlySucceeding(): void
    {
        $this->getAssetsByPaths->method('execute')->willReturn([]);
        $this->deleteAssetsByPaths->expects($this->never())->method('execute');

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('No media gallery asset exists at "wysiwyg/gone.png"');

        $this->tool->execute(['path' => 'wysiwyg/gone.png']);
    }

    /**
     * An asset on disk but not indexed has no id, so nothing can reference it
     * through the media content tables and there is nothing to check.
     *
     * @return void
     */
    public function testAnUnindexedAssetSkipsTheUsageCheckRatherThanFailing(): void
    {
        $this->assetAt('wysiwyg/loose.png', null);
        $this->getContentByAssetIds->expects($this->never())->method('execute');
        $this->deleteAssetsByPaths->expects($this->once())->method('execute');

        $result = $this->tool->execute(['path' => 'wysiwyg/loose.png']);

        $this->assertNull($result['asset_id']);
    }

    /**
     * @param string $path
     * @param int|null $assetId
     * @return void
     */
    private function assetAt(string $path, ?int $assetId): void
    {
        $asset = $this->createMock(AssetInterface::class);
        $asset->method('getId')->willReturn($assetId);
        $asset->method('getPath')->willReturn($path);
        $asset->method('getTitle')->willReturn('Hero');

        $this->getAssetsByPaths->method('execute')->willReturn([$asset]);
    }

    /**
     * @param string $type
     * @param string $id
     * @param string $field
     * @return ContentIdentityInterface
     */
    private function identity(string $type, string $id, string $field): ContentIdentityInterface
    {
        $identity = $this->createMock(ContentIdentityInterface::class);
        $identity->method('getEntityType')->willReturn($type);
        $identity->method('getEntityId')->willReturn($id);
        $identity->method('getField')->willReturn($field);

        return $identity;
    }
}
