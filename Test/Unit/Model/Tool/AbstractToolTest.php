<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Test\Unit\Model\Tool;

use Magenx\AiMcp\Model\Tool\AbstractTool;
use PHPUnit\Framework\TestCase;

/**
 * The annotations every tool gets without asking for them.
 *
 * These are derived from `isWrite()` rather than written out per tool, which
 * is the only reason 167 tools can carry them at all. The risk that buys is a
 * wrong default applied everywhere at once: a write tool that reads as
 * read-only invites a client to call it without review, and a read tool that
 * advertises `destructiveHint` teaches a client to distrust the hint on the
 * tools where it matters. Both are pinned here.
 *
 * @see AbstractTool::getAnnotations
 * @see AbstractTool::getTitle
 */
class AbstractToolTest extends TestCase
{
    /**
     * A read tool says so, and says its world is closed.
     *
     * @return void
     */
    public function testAReadToolIsAnnotatedReadOnly(): void
    {
        $annotations = $this->tool('search_products')->getAnnotations();

        $this->assertTrue($annotations['readOnlyHint']);
        $this->assertFalse($annotations['openWorldHint']);
    }

    /**
     * MCP defines the two write hints only when `readOnlyHint` is false, so a
     * read tool must not advertise a value for them at all.
     *
     * @return void
     */
    public function testAReadToolAdvertisesNeitherWriteHint(): void
    {
        $annotations = $this->tool('search_products')->getAnnotations();

        $this->assertArrayNotHasKey('destructiveHint', $annotations);
        $this->assertArrayNotHasKey('idempotentHint', $annotations);
    }

    /**
     * A write tool defaults to the pessimistic reading of itself.
     *
     * @return void
     */
    public function testAWriteToolAssumesTheWorstOfItself(): void
    {
        $annotations = $this->tool('delete_product', isWrite: true)->getAnnotations();

        $this->assertFalse($annotations['readOnlyHint']);
        $this->assertTrue($annotations['destructiveHint']);
        $this->assertFalse($annotations['idempotentHint']);
    }

    /**
     * A write that only ever adds, and one that can be repeated safely, can
     * each say so — otherwise every write would read as equally dangerous and
     * the hint would carry no information.
     *
     * @return void
     */
    public function testAWriteToolCanCorrectBothDefaults(): void
    {
        $tool = new class extends AbstractTool {
            /**
             * @inheritDoc
             */
            public function getName(): string
            {
                return 'set_tier_prices';
            }

            /**
             * @inheritDoc
             */
            public function getDescription(): string
            {
                return 'Test tool.';
            }

            /**
             * @inheritDoc
             */
            public function getInputSchema(): array
            {
                return ['type' => 'object', 'properties' => [], 'additionalProperties' => false];
            }

            /**
             * @inheritDoc
             */
            public function getAclResource(): string
            {
                return '';
            }

            /**
             * @inheritDoc
             */
            public function isWrite(): bool
            {
                return true;
            }

            /**
             * @inheritDoc
             */
            protected function isDestructive(): bool
            {
                return false;
            }

            /**
             * @inheritDoc
             */
            protected function isIdempotent(): bool
            {
                return true;
            }

            /**
             * @inheritDoc
             */
            public function execute(array $arguments): array
            {
                return [];
            }
        };

        $annotations = $tool->getAnnotations();

        $this->assertFalse($annotations['destructiveHint']);
        $this->assertTrue($annotations['idempotentHint']);
    }

    /**
     * @return void
     */
    public function testTheTitleIsDerivedFromTheToolName(): void
    {
        $this->assertSame('Search Products', $this->tool('search_products')->getTitle());
    }

    /**
     * "Search Cms Pages" reads as a typo in a client's tool picker.
     *
     * @return void
     */
    public function testInitialismsSurviveTheDerivedTitle(): void
    {
        $this->assertSame('Search CMS Pages', $this->tool('search_cms_pages')->getTitle());
        $this->assertSame('Search URL Rewrites', $this->tool('search_url_rewrites')->getTitle());
        $this->assertSame('List RMA Items', $this->tool('list_rma_items')->getTitle());
    }

    /**
     * A tool that does nothing but carry a name and a write flag.
     *
     * @param string $name
     * @param bool $isWrite
     * @return AbstractTool
     */
    private function tool(string $name, bool $isWrite = false): AbstractTool
    {
        return new class ($name, $isWrite) extends AbstractTool {
            /**
             * @param string $name
             * @param bool $isWrite
             */
            public function __construct(
                private readonly string $name,
                private readonly bool $isWrite
            ) {
            }

            /**
             * @inheritDoc
             */
            public function getName(): string
            {
                return $this->name;
            }

            /**
             * @inheritDoc
             */
            public function getDescription(): string
            {
                return 'Test tool.';
            }

            /**
             * @inheritDoc
             */
            public function getInputSchema(): array
            {
                return ['type' => 'object', 'properties' => [], 'additionalProperties' => false];
            }

            /**
             * @inheritDoc
             */
            public function getAclResource(): string
            {
                return '';
            }

            /**
             * @inheritDoc
             */
            public function isWrite(): bool
            {
                return $this->isWrite;
            }

            /**
             * @inheritDoc
             */
            public function execute(array $arguments): array
            {
                return [];
            }
        };
    }
}
