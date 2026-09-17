<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Test\Unit\Model\Config\Backend;

use Magenx\AiMcp\Model\Config\Backend\DisabledTools;
use Magenx\AiMcp\Model\Tool\ToolCatalog;
use Magenx\AiMcp\Model\Tool\ToolRegistry;
use Magenx\AiMcp\Test\Unit\Fixture\Model\Tool\Catalog\Option\NestedTool;
use Magenx\AiMcp\Test\Unit\Fixture\Model\Tool\Sales\FlatTool;
use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Model\Context;
use Magento\Framework\Registry;
use PHPUnit\Framework\TestCase;

/**
 * Validating the Disabled Tools list when it is saved.
 *
 * A denylist is the one setting whose typo is invisible: the entry matches no
 * tool, the tool it was meant to hide stays listed and callable, and the page
 * saves without complaint. The operator then believes a capability is withheld
 * when it is not — the wrong direction to fail in for a list whose only job is
 * withholding capability. Everything here exists to make that failure loud.
 *
 * @see DisabledTools::beforeSave
 */
class DisabledToolsTest extends TestCase
{
    /**
     * @return void
     */
    public function testKnownNamesSaveWithoutComplaint(): void
    {
        $model = $this->model();
        $model->setValue("flat_tool\nnested_tool");

        $this->assertSame($model, $model->beforeSave());
    }

    /**
     * @return void
     */
    public function testAnEmptyListIsAcceptable(): void
    {
        $model = $this->model();
        $model->setValue('');

        $this->assertSame($model, $model->beforeSave());
    }

    /**
     * The same separators the runtime splits on, so what saves is what filters.
     *
     * @return void
     */
    public function testCommaSeparatedNamesAreParsedTheWayTheRuntimeParsesThem(): void
    {
        $model = $this->model();
        $model->setValue('flat_tool, nested_tool');

        $this->assertSame($model, $model->beforeSave());
    }

    /**
     * @return void
     */
    public function testAnUnknownNameIsRefused(): void
    {
        $model = $this->model();
        $model->setValue('flat_tools');

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('No tool is named "flat_tools".');

        $model->beforeSave();
    }

    /**
     * The usual cause is a plural or a half-remembered name, so the refusal
     * offers the near miss rather than only reporting the mistake.
     *
     * @return void
     */
    public function testTheRefusalOffersTheNearestRealName(): void
    {
        $model = $this->model();
        $model->setValue('flat_tools');

        try {
            $model->beforeSave();
            $this->fail('An unknown tool name must be refused.');
        } catch (LocalizedException $e) {
            $this->assertStringContainsString('Did you mean: flat_tool?', $e->getMessage());
        }
    }

    /**
     * A name nothing resembles gets the count instead of a useless suggestion.
     *
     * @return void
     */
    public function testANameNothingResemblesGetsTheToolCountInstead(): void
    {
        $model = $this->model();
        $model->setValue('completely_unrelated_nonsense');

        try {
            $model->beforeSave();
            $this->fail('An unknown tool name must be refused.');
        } catch (LocalizedException $e) {
            $this->assertStringContainsString('registers 2 tools', $e->getMessage());
            $this->assertStringNotContainsString('Did you mean', $e->getMessage());
        }
    }

    /**
     * One bad entry among good ones still refuses the whole save — a partial
     * save would leave the operator believing all of them took effect.
     *
     * @return void
     */
    public function testOneBadEntryRefusesTheWholeList(): void
    {
        $model = $this->model();
        $model->setValue("flat_tool\nno_such_tool\nnested_tool");

        $this->expectException(LocalizedException::class);

        $model->beforeSave();
    }

    /**
     * @return DisabledTools
     */
    private function model(): DisabledTools
    {
        $catalog = new ToolCatalog(new ToolRegistry([new FlatTool(), new NestedTool()]));

        return new DisabledTools(
            $this->createMock(Context::class),
            $this->createMock(Registry::class),
            $this->createMock(ScopeConfigInterface::class),
            $this->createMock(TypeListInterface::class),
            $catalog
        );
    }
}
