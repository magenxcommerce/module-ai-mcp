<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Test\Unit\Model;

use Magenx\AiMcp\Model\MediaPathPolicy;
use Magento\Framework\Exception\LocalizedException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Where a media-gallery upload is allowed to land.
 *
 * Everything under pub/media is served straight off the web server, so this is
 * the check standing between an argument an agent was handed and a public URL.
 * What is pinned here is that nothing escapes the gallery root and nothing is
 * stored under an extension the web server would execute.
 *
 * @see MediaPathPolicy
 */
class MediaPathPolicyTest extends TestCase
{
    private MediaPathPolicy $policy;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        $this->policy = new MediaPathPolicy();
    }

    /**
     * @return void
     */
    public function testAPlainNameLandsInTheGalleryRoot(): void
    {
        $this->assertSame(
            'wysiwyg/hero.jpg',
            $this->policy->resolve('hero.jpg', null, 'image/jpeg')
        );
    }

    /**
     * @return void
     */
    public function testFoldersAreKeptUnderTheRoot(): void
    {
        $this->assertSame(
            'wysiwyg/banners/2026/hero.png',
            $this->policy->resolve('hero.png', 'banners/2026', 'image/png')
        );
        $this->assertSame(
            'wysiwyg/banners/hero.png',
            $this->policy->resolve('hero.png', '/banners/', 'image/png')
        );
    }

    /**
     * @param string $fileName
     * @param string|null $directory
     * @param string $mimeType
     * @param string $expectedMessage
     * @return void
     */
    #[DataProvider('refusedProvider')]
    public function testItIsRefused(
        string $fileName,
        ?string $directory,
        string $mimeType,
        string $expectedMessage
    ): void {
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage($expectedMessage);
        $this->policy->resolve($fileName, $directory, $mimeType);
    }

    /**
     * @return array<string, array{0: string, 1: string|null, 2: string, 3: string}>
     */
    public static function refusedProvider(): array
    {
        return [
            'climbing out of the root' => [
                'hero.jpg',
                '../../app/etc',
                'image/jpeg',
                '".." is not allowed',
            ],
            'climbing out through the file name' => [
                '../hero.jpg',
                null,
                'image/jpeg',
                '".." is not allowed',
            ],
            'a path where a name belongs' => [
                'banners/hero.jpg',
                null,
                'image/jpeg',
                'must be a name, not a path',
            ],
            'a windows separator' => [
                'hero.jpg',
                'banners\\2026',
                'image/jpeg',
                'contains a character that is not allowed in a path',
            ],
            'a null byte truncating the name' => [
                "hero.php\0.jpg",
                null,
                'image/jpeg',
                'contains a character that is not allowed in a path',
            ],
            // The one that matters most: a real image stored where the web
            // server would hand it to PHP.
            'an executable extension' => [
                'hero.phtml',
                null,
                'image/jpeg',
                'has to be named .jpg or .jpeg',
            ],
            'no extension at all' => [
                'hero',
                null,
                'image/png',
                'has to be named .png',
            ],
            'an extension disagreeing with the type' => [
                'hero.png',
                null,
                'image/jpeg',
                'has to be named .jpg or .jpeg',
            ],
            'a type the gallery does not take' => [
                'hero.svg',
                null,
                'image/svg+xml',
                'The "mime_type" argument must be one of',
            ],
        ];
    }

    /**
     * @return void
     */
    public function testAnUppercaseExtensionIsAccepted(): void
    {
        $this->assertSame(
            'wysiwyg/HERO.JPG',
            $this->policy->resolve('HERO.JPG', null, 'image/jpeg')
        );
    }
}
