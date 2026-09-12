<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Test\Unit\Model\Tool\Cms;

use Magenx\AiMcp\Model\Tool\Cms\PageContentArguments;
use Magento\Cms\Api\Data\PageInterface;
use Magento\Framework\Exception\LocalizedException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The content fields shared by create_cms_page and update_cms_page.
 *
 * @see PageContentArguments
 */
class PageContentArgumentsTest extends TestCase
{
    private PageContentArguments $content;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        $this->content = new PageContentArguments();
    }

    /**
     * @return void
     */
    public function testOnlyThePassedFieldsAreSet(): void
    {
        $page = $this->createMock(PageInterface::class);
        $page->expects($this->once())->method('setTitle')->with('About Us');
        $page->expects($this->never())->method('setContent');
        $page->expects($this->never())->method('setMetaTitle');

        $this->assertSame(['title'], $this->content->applyTo($page, ['title' => 'About Us']));
    }

    /**
     * Unpublishing is the reversible alternative to delete_cms_page, so a false
     * must be stored rather than treated as a field that was not passed.
     *
     * @return void
     */
    public function testFalseIsActiveIsStored(): void
    {
        $page = $this->createMock(PageInterface::class);
        $page->expects($this->once())->method('setIsActive')->with(false);

        $this->assertSame(['is_active'], $this->content->applyTo($page, ['is_active' => false]));
    }

    /**
     * @return void
     */
    public function testNullClearsAStringField(): void
    {
        $page = $this->createMock(PageInterface::class);
        $page->expects($this->once())->method('setMetaDescription')->with(null);

        $this->content->applyTo($page, ['meta_description' => null]);
    }

    /**
     * Magento stores sort_order as a string column.
     *
     * @return void
     */
    public function testSortOrderIsNormalised(): void
    {
        $page = $this->createMock(PageInterface::class);
        $page->expects($this->once())->method('setSortOrder')->with('5');

        $this->content->applyTo($page, ['sort_order' => '5']);
    }

    /**
     * The design fields are gated behind Magento_Cms::save_design, and a tool
     * declares exactly one ACL resource — so if this handler wrote them, an
     * integration granted only "Save Page" could rewrite a page's layout XML
     * through update_cms_page.
     *
     * @param string $field
     * @return void
     */
    #[DataProvider('designFieldProvider')]
    public function testDesignFieldsAreNeitherOfferedNorWritten(string $field): void
    {
        $this->assertArrayNotHasKey($field, $this->content->schemaProperties());

        $page = $this->createMock(PageInterface::class);

        $this->assertSame([], $this->content->applyTo($page, [$field => '<referenceBlock name="x"/>']));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function designFieldProvider(): array
    {
        return [
            'layout_update_xml' => ['layout_update_xml'],
            'custom_layout_update_xml' => ['custom_layout_update_xml'],
            'custom_theme' => ['custom_theme'],
            'custom_root_template' => ['custom_root_template'],
            'custom_theme_from' => ['custom_theme_from'],
            'custom_theme_to' => ['custom_theme_to'],
        ];
    }

    /**
     * page_layout is the one layout field Magento saves under "Save Page"
     * rather than "Edit Page Design", so it belongs here.
     *
     * @return void
     */
    public function testPageLayoutIsAContentField(): void
    {
        $page = $this->createMock(PageInterface::class);
        $page->expects($this->once())->method('setPageLayout')->with('1column');

        $this->assertSame(['page_layout'], $this->content->applyTo($page, ['page_layout' => '1column']));
        $this->assertArrayHasKey('page_layout', $this->content->schemaProperties());
    }

    /**
     * @param array<string, mixed> $arguments
     * @param string $expectedMessage
     * @return void
     */
    #[DataProvider('malformedArgumentsProvider')]
    public function testMalformedArgumentsAreRefused(array $arguments, string $expectedMessage): void
    {
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage($expectedMessage);

        $this->content->applyTo($this->createMock(PageInterface::class), $arguments);
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function malformedArgumentsProvider(): array
    {
        return [
            'title as array' => [['title' => ['About']], 'The "title" argument must be a string.'],
            // "false" and 1 are shapes a model produces for a boolean, and both
            // would publish a page under a plain cast.
            'is_active as string' => [['is_active' => 'false'], 'must be true or false'],
            'is_active as number' => [['is_active' => 1], 'must be true or false'],
            'sort_order as word' => [['sort_order' => 'first'], 'must be a whole number'],
        ];
    }

    /**
     * @return void
     */
    public function testIdentifierOnlyArgumentsChangeNothing(): void
    {
        $changed = $this->content->applyTo(
            $this->createMock(PageInterface::class),
            ['identifier' => 'about-us', 'page_id' => 3]
        );

        $this->assertSame([], $changed);
    }
}
