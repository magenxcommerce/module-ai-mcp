<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Test\Unit\Model\Tool;

use Magenx\AiMcp\Model\Tool\ToolCatalog;
use Magenx\AiMcp\Model\Tool\ToolRegistry;
use Magenx\AiMcp\Model\Tool\ToolsetProfiles;
use Magenx\AiMcp\Model\Tool\ToolsetResolver;
use Magenx\AiMcp\Test\Unit\Fixture\Model\Tool\Catalog\Option\NestedTool;
use Magenx\AiMcp\Test\Unit\Fixture\Model\Tool\Sales\FlatTool;
use Magenx\AiMcp\Test\Unit\Fixture\Model\Tool\UrlRewrite\LabelledTool;
use Magento\Framework\Exception\LocalizedException;
use PHPUnit\Framework\TestCase;

/**
 * Reading the toolset a client put in its endpoint URL.
 *
 * The failure worth guarding is the quiet one: a typo that is dropped rather
 * than refused leaves a client with a different toolset from the one it was
 * configured for, and nothing says so.
 *
 * @see ToolsetResolver
 */
class ToolsetResolverTest extends TestCase
{
    /**
     * @return void
     */
    public function testNothingAskedForRestrictsNothing(): void
    {
        $toolset = $this->resolver()->resolve(null, null, null);

        $this->assertFalse($toolset->isRestricted());
        $this->assertTrue($toolset->permits('flat_tool', 'sales'));
    }

    /**
     * @return void
     */
    public function testDomainsNarrowToThoseDomains(): void
    {
        $toolset = $this->resolver()->resolve(null, 'Sales, catalog', null);

        $this->assertTrue($toolset->permits('flat_tool', 'sales'));
        $this->assertTrue($toolset->permits('nested_tool', 'catalog'));
        $this->assertFalse($toolset->permits('labelled_tool', 'urlrewrite'));
    }

    /**
     * @return void
     */
    public function testAnUnknownDomainIsRefusedWithTheKnownOnes(): void
    {
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('Unknown tool domain(s) in the endpoint URL: sails. This server has:');

        $this->resolver()->resolve(null, 'sails', null);
    }

    /**
     * @return void
     */
    public function testAnUnknownProfileIsRefusedWithTheKnownOnes(): void
    {
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('Unknown toolset profile "everything". Use one of: analytics');

        $this->resolver()->resolve('everything', null, null);
    }

    /**
     * A profile names domains a store may not have; those are skipped, and the
     * ones it does have are kept.
     *
     * @return void
     */
    public function testAProfileKeepsOnlyTheDomainsThatExist(): void
    {
        $toolset = $this->resolver()->resolve('analytics', null, null);

        $this->assertTrue($toolset->permits('flat_tool', 'sales'));
        $this->assertFalse($toolset->permits('nested_tool', 'catalog'));
    }

    /**
     * Profile and domains together mean both, never either.
     *
     * @return void
     */
    public function testProfileAndDomainsIntersect(): void
    {
        $toolset = $this->resolver()->resolve('catalog', 'catalog,urlrewrite', null);

        $this->assertTrue($toolset->permits('nested_tool', 'catalog'));
        $this->assertTrue($toolset->permits('labelled_tool', 'urlrewrite'));
        $this->assertFalse($toolset->permits('flat_tool', 'sales'));
    }

    /**
     * An intersection that leaves nothing is a configuration mistake, and
     * offering an empty toolset would read as a broken server.
     *
     * @return void
     */
    public function testAnEmptyIntersectionIsRefused(): void
    {
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('selects no domain at all');

        $this->resolver()->resolve('analytics', 'catalog', null);
    }

    /**
     * @return void
     */
    public function testToolsNarrowToThoseTools(): void
    {
        $toolset = $this->resolver()->resolve(null, null, 'flat_tool');

        $this->assertTrue($toolset->permits('flat_tool', 'sales'));
        $this->assertFalse($toolset->permits('nested_tool', 'catalog'));
    }

    /**
     * The refusal names the bad entry only: the full tool list includes tools
     * this caller's role may not reach.
     *
     * @return void
     */
    public function testAnUnknownToolIsRefusedWithoutListingTheRest(): void
    {
        try {
            $this->resolver()->resolve(null, null, 'flat_tool,flat_tol');
            $this->fail('An unknown tool name was accepted.');
        } catch (LocalizedException $e) {
            $this->assertSame('Unknown tool name(s) in the endpoint URL: flat_tol.', $e->getMessage());
        }
    }

    /**
     * @return ToolsetResolver
     */
    private function resolver(): ToolsetResolver
    {
        $registry = new ToolRegistry([
            'flat_tool' => new FlatTool(),
            'nested_tool' => new NestedTool(),
            'labelled_tool' => new LabelledTool(),
        ]);

        return new ToolsetResolver(new ToolCatalog($registry), new ToolsetProfiles());
    }
}
