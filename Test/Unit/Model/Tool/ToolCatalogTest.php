<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Test\Unit\Model\Tool;

use Magenx\AiMcp\Api\ToolInterface;
use Magenx\AiMcp\Model\Tool\ToolCatalog;
use Magenx\AiMcp\Model\Tool\ToolRegistry;
use Magenx\AiMcp\Test\Unit\Fixture\Model\Tool\Catalog\Option\NestedTool;
use Magenx\AiMcp\Test\Unit\Fixture\Model\Tool\Sales\FlatTool;
use Magenx\AiMcp\Test\Unit\Fixture\Model\Tool\UrlRewrite\LabelledTool;
use PHPUnit\Framework\TestCase;

/**
 * Grouping tools into the domains an operator switches on and off.
 *
 * The derivation is the whole design: a hand-kept list beside 170 tools is a
 * list that is wrong within a release, so the directory each tool already lives
 * in is the grouping used. What that buys has to be paid for here, because the
 * failure is quiet in both directions — a tool that lands in the wrong domain
 * is withheld when the operator meant to offer it, or offered when they meant
 * to withhold it, and `tools/list` looks plausible either way.
 *
 * The fixture classes under Test/Unit/Fixture/Model/Tool/ mimic real module
 * namespaces on purpose: an anonymous class has no namespace to derive from, so
 * it could only ever exercise the fallback.
 *
 * @see ToolCatalog
 */
class ToolCatalogTest extends TestCase
{
    /**
     * @return void
     */
    public function testTheSegmentAfterModelToolIsTheDomain(): void
    {
        $this->assertSame('sales', $this->catalog(new FlatTool())->getDomain(new FlatTool()));
    }

    /**
     * A nested directory groups with its parent rather than becoming a domain
     * of its own that an operator has to know to tick separately.
     *
     * @return void
     */
    public function testANestedToolGroupsWithItsParentDomain(): void
    {
        $this->assertSame('catalog', $this->catalog(new NestedTool())->getDomain(new NestedTool()));
    }

    /**
     * A tool with no namespace at all — an anonymous class, which is what a
     * test fixture elsewhere in this suite is — must land somewhere rather than
     * erroring.
     *
     * @return void
     */
    public function testAClassWithNoUsableNamespaceFallsBack(): void
    {
        $anonymous = new class implements ToolInterface {
            /**
             * @inheritDoc
             */
            public function getName(): string
            {
                return 'anonymous_thing';
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
                return [];
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
                return false;
            }

            /**
             * @inheritDoc
             */
            public function execute(array $arguments): array
            {
                return [];
            }
        };

        $this->assertSame('other', $this->catalog($anonymous)->getDomain($anonymous));
    }

    /**
     * Labels are what an operator picks from, so "Url Rewrite" and "Mediagallery"
     * would both read as mistakes in the admin.
     *
     * @return void
     */
    public function testLabelsSplitCamelCaseAndKeepInitialisms(): void
    {
        $domains = $this->catalog(new LabelledTool())->getDomains();

        $this->assertSame(['urlrewrite' => 'URL Rewrite'], $domains);
    }

    /**
     * @return void
     */
    public function testDomainsAreSortedByLabelForTheMultiselect(): void
    {
        $domains = $this->catalog(new LabelledTool(), new FlatTool(), new NestedTool())->getDomains();

        $this->assertSame(['catalog', 'sales', 'urlrewrite'], array_keys($domains));
    }

    /**
     * The denylist is validated against this, so it has to be every name.
     *
     * @return void
     */
    public function testEveryRegisteredNameIsReported(): void
    {
        $names = $this->catalog(new FlatTool(), new NestedTool())->getToolNames();

        sort($names);
        $this->assertSame(['flat_tool', 'nested_tool'], $names);
    }

    /**
     * @param ToolInterface ...$tools
     * @return ToolCatalog
     */
    private function catalog(ToolInterface ...$tools): ToolCatalog
    {
        return new ToolCatalog(new ToolRegistry($tools));
    }
}
