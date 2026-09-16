<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Test\Unit\Model\Tool\Blog;

use Magenx\AiMcp\Model\Tool\Blog\PostArguments;
use Magenx\Blog\Model\Post;
use Magenx\Blog\Model\UrlKey;
use Magento\Framework\Exception\LocalizedException;
use PHPUnit\Framework\TestCase;

/**
 * Applying the writable fields of a blog post.
 *
 * The relation sets are what this pins. The module's resource model rewrites
 * the category, tag and store link tables only when the model carries that key
 * at all, so an update that sets them unconditionally would silently detach
 * every category from a post whose title was the only thing being changed.
 * Omitting and passing an empty array therefore mean different things here, and
 * both have to keep meaning what they mean.
 *
 * The store case is the sharp one: an empty set writes no rows, and the
 * storefront's inner join reads a post with no rows as non-existent while the
 * admin grid still lists it — live in one place, gone in the other, with no
 * error anywhere.
 *
 * @see PostArguments::applyTo
 */
class PostArgumentsTest extends TestCase
{
    private PostArguments $arguments;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        $urlKey = $this->createMock(UrlKey::class);
        // Mimics a real URL-key normaliser closely enough for the cases here:
        // lowercase, non-alphanumerics become separators, and a string with
        // nothing usable in it normalises to empty.
        $urlKey->method('normalize')->willReturnCallback(
            static fn (string $key, string $fallback = ''): string => trim(
                preg_replace('/[^a-z0-9]+/', '-', strtolower($key !== '' ? $key : $fallback)) ?? '',
                '-'
            )
        );

        $this->arguments = new PostArguments($urlKey);
    }

    /**
     * @return void
     */
    public function testAnUntouchedRelationSetIsNotWrittenAtAll(): void
    {
        $post = new Post();
        $post->setData('title', 'Existing');

        $this->arguments->applyTo($post, ['title' => 'Renamed'], false);

        // hasData() is exactly what the resource model checks before rewriting
        // the link tables.
        $this->assertFalse($post->hasData('category_ids'));
        $this->assertFalse($post->hasData('tag_ids'));
        $this->assertFalse($post->hasData('store_ids'));
    }

    /**
     * @return void
     */
    public function testAnEmptyCategorySetIsAnInstructionToDetachAll(): void
    {
        $post = new Post();
        $post->setData('title', 'Existing');

        $changed = $this->arguments->applyTo($post, ['category_ids' => []], false);

        $this->assertTrue($post->hasData('category_ids'));
        $this->assertSame([], $post->getData('category_ids'));
        $this->assertContains('category_ids', $changed);
    }

    /**
     * The one relation where an empty set is refused rather than obeyed.
     *
     * @return void
     */
    public function testAnEmptyStoreSetIsRefusedWithBothWaysOut(): void
    {
        $post = new Post();
        $post->setData('title', 'Existing');

        try {
            $this->arguments->applyTo($post, ['store_ids' => []], false);
            $this->fail('An empty store_ids must be refused.');
        } catch (LocalizedException $e) {
            $this->assertStringContainsString('[0] for all store views', $e->getMessage());
            $this->assertStringContainsString('is_active false', $e->getMessage());
        }
    }

    /**
     * @return void
     */
    public function testANewPostDefaultsToEveryStoreView(): void
    {
        $post = new Post();

        $this->arguments->applyTo($post, ['title' => 'Hello', 'content' => '<p>Hi</p>'], true);

        $this->assertSame([0], $post->getData('store_ids'));
    }

    /**
     * @return void
     */
    public function testDuplicateIdsAreCollapsed(): void
    {
        $post = new Post();
        $post->setData('title', 'Existing');

        $this->arguments->applyTo($post, ['tag_ids' => [3, 3, 7, 3]], false);

        $this->assertSame([3, 7], array_values($post->getData('tag_ids')));
    }

    /**
     * @return void
     */
    public function testANonNumericIdIsRefused(): void
    {
        $post = new Post();
        $post->setData('title', 'Existing');

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('must be a whole number');

        $this->arguments->applyTo($post, ['category_ids' => ['travel']], false);
    }

    /**
     * @return void
     */
    public function testANewPostDerivesItsUrlKeyFromTheTitle(): void
    {
        $post = new Post();

        $this->arguments->applyTo($post, ['title' => 'Autumn Sale', 'content' => 'x'], true);

        $this->assertSame('autumn-sale', $post->getData('url_key'));
    }

    /**
     * An update that does not mention url_key must not recompute it — the
     * storefront address of a published post is not something a title edit
     * should quietly move.
     *
     * @return void
     */
    public function testAnUpdateLeavesTheUrlKeyAloneUnlessAsked(): void
    {
        $post = new Post();
        $post->setData('title', 'Old title');
        $post->setData('url_key', 'old-title');

        $changed = $this->arguments->applyTo($post, ['title' => 'A brand new title'], false);

        $this->assertSame('old-title', $post->getData('url_key'));
        $this->assertNotContains('url_key', $changed);
    }

    /**
     * @return void
     */
    public function testCreatingWithoutContentIsRefused(): void
    {
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('"content" argument is required');

        $this->arguments->applyTo(new Post(), ['title' => 'Only a title'], true);
    }

    /**
     * @return void
     */
    public function testAStringInsteadOfABooleanIsRefused(): void
    {
        $post = new Post();
        $post->setData('title', 'Existing');

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('must be true or false');

        $this->arguments->applyTo($post, ['is_active' => 'false'], false);
    }

    /**
     * A title of only punctuation normalises to nothing, and a post with an
     * empty url_key is unreachable on the storefront.
     *
     * @return void
     */
    public function testAnUnderivableUrlKeyIsRefused(): void
    {
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('url_key could not be derived');

        $this->arguments->applyTo(new Post(), ['title' => '!!! ???', 'content' => 'x'], true);
    }

    /**
     * A blank title is caught earlier, as a missing required field, which is
     * the better message of the two.
     *
     * @return void
     */
    public function testABlankTitleIsRefusedAsAMissingField(): void
    {
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('"title" argument is required');

        $this->arguments->applyTo(new Post(), ['title' => '   ', 'content' => 'x'], true);
    }
}
