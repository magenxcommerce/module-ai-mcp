<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Test\Unit\Model\Tool\Link;

use Magenx\AiMcp\Model\Tool\Link\SetProductLinks;
use Magenx\AiMcp\Test\Unit\GeneratedFactory;
use Magento\Catalog\Api\Data\ProductLinkInterface;
use Magento\Catalog\Api\Data\ProductLinkInterfaceFactory;
use Magento\Catalog\Api\Data\ProductLinkTypeInterface;
use Magento\Catalog\Api\ProductLinkManagementInterface;
use Magento\Catalog\Api\ProductLinkTypeListInterface;
use Magento\Framework\Exception\LocalizedException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Replace-versus-append, which is the trap this tool exists to make visible.
 *
 * Magento's setProductLinks replaces the whole list for the types it is given.
 * An agent reading "set these links" as "add these links" would silently drop
 * every link it did not mention, so the append mode has to actually carry the
 * existing ones through.
 *
 * @see SetProductLinks
 */
class SetProductLinksTest extends TestCase
{
    private ProductLinkManagementInterface&MockObject $linkManagement;

    private SetProductLinks $tool;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        GeneratedFactory::ensure(ProductLinkInterfaceFactory::class);

        $this->linkManagement = $this->createMock(ProductLinkManagementInterface::class);

        $linkTypeList = $this->createMock(ProductLinkTypeListInterface::class);
        $linkTypeList->method('getItems')->willReturn([
            $this->linkType('related'),
            $this->linkType('upsell'),
        ]);

        $linkFactory = $this->createMock(ProductLinkInterfaceFactory::class);
        $linkFactory->method('create')->willReturnCallback(fn (): ProductLinkInterface => $this->link());

        $this->tool = new SetProductLinks($this->linkManagement, $linkTypeList, $linkFactory);
    }

    /**
     * @return void
     */
    public function testReplaceSendsOnlyWhatWasGiven(): void
    {
        // Replace is Magento's own behaviour, so the current links must not
        // even be read: reading them and sending them back would make replace
        // behave like append.
        $this->linkManagement->expects($this->never())->method('getLinkedItemsByType');
        $this->linkManagement->expects($this->once())->method('setProductLinks')->with(
            'PARENT',
            $this->callback(static fn (array $links): bool => count($links) === 1
                && $links[0]->getLinkedProductSku() === 'NEW-1')
        );

        $result = $this->tool->execute([
            'sku' => 'PARENT',
            'link_type' => 'related',
            'linked_products' => [['sku' => 'NEW-1']],
        ]);

        $this->assertSame('replace', $result['mode']);
        $this->assertSame(['NEW-1'], $result['linked_skus']);
    }

    /**
     * @return void
     */
    public function testAppendKeepsTheLinksTheProductAlreadyHas(): void
    {
        $existing = $this->link();
        $existing->setLinkedProductSku('OLD-1');
        $this->linkManagement->expects($this->once())
            ->method('getLinkedItemsByType')
            ->with('PARENT', 'related')
            ->willReturn([$existing]);

        $sent = [];
        $this->linkManagement->expects($this->once())
            ->method('setProductLinks')
            ->willReturnCallback(static function (string $sku, array $links) use (&$sent): void {
                $sent = array_map(
                    static fn (ProductLinkInterface $link): string => (string) $link->getLinkedProductSku(),
                    $links
                );
            });

        $result = $this->tool->execute([
            'sku' => 'PARENT',
            'link_type' => 'related',
            'mode' => 'append',
            'linked_products' => [['sku' => 'NEW-1']],
        ]);

        $this->assertSame(['OLD-1', 'NEW-1'], $sent);
        $this->assertSame('append', $result['mode']);
    }

    /**
     * Appending a sku the product already links to must restate it, not list it
     * twice — Magento would otherwise store a duplicate row.
     *
     * @return void
     */
    public function testAppendingAnExistingSkuDoesNotDuplicateIt(): void
    {
        $existing = $this->link();
        $existing->setLinkedProductSku('OLD-1');
        $existing->setPosition(1);
        $this->linkManagement->method('getLinkedItemsByType')->willReturn([$existing]);

        $sent = [];
        $this->linkManagement->method('setProductLinks')->willReturnCallback(
            static function (string $sku, array $links) use (&$sent): void {
                $sent = $links;
            }
        );

        $this->tool->execute([
            'sku' => 'PARENT',
            'link_type' => 'related',
            'mode' => 'append',
            'linked_products' => [['sku' => 'old-1', 'position' => 9]],
        ]);

        $this->assertCount(1, $sent);
        $this->assertSame(9, (int) $sent[0]->getPosition(), 'the requested position wins');
    }

    /**
     * @param array<string, mixed> $arguments
     * @param string $expectedMessage
     * @return void
     */
    #[DataProvider('refusedProvider')]
    public function testMalformedCallsAreRefusedWithoutWriting(array $arguments, string $expectedMessage): void
    {
        $this->linkManagement->expects($this->never())->method('setProductLinks');

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage($expectedMessage);

        $this->tool->execute(['sku' => 'PARENT', 'link_type' => 'related'] + $arguments);
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function refusedProvider(): array
    {
        return [
            'no linked products' => [
                ['linked_products' => []],
                'Pass at least one entry in "linked_products".',
            ],
            'entry without a sku' => [
                ['linked_products' => [['position' => 1]]],
                'Entry 1 needs a "sku".',
            ],
            // A product related to itself renders as an empty block.
            'product linked to itself' => [
                ['linked_products' => [['sku' => 'parent']]],
                'links "parent" to itself',
            ],
            'same sku twice' => [
                ['linked_products' => [['sku' => 'A'], ['sku' => 'a']]],
                'Entries 1 and 2 both name sku "a".',
            ],
            'bad position' => [
                ['linked_products' => [['sku' => 'A', 'position' => 'first']]],
                'Entry 1 has an unusable "position"',
            ],
        ];
    }

    /**
     * @return void
     */
    public function testAnUnknownLinkTypeIsRefused(): void
    {
        $this->linkManagement->expects($this->never())->method('setProductLinks');

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('Unknown link type "sidegrade". This installation has: related, upsell.');

        $this->tool->execute([
            'sku' => 'PARENT',
            'link_type' => 'sidegrade',
            'linked_products' => [['sku' => 'A']],
        ]);
    }

    /**
     * A link object that holds what is written to it, so the tests can assert
     * on the list that reaches Magento.
     *
     * @return ProductLinkInterface
     */
    private function link(): ProductLinkInterface
    {
        $link = $this->createMock(ProductLinkInterface::class);
        $state = ['sku' => null, 'type' => null, 'linked' => null, 'position' => null];

        $link->method('setSku')->willReturnCallback(
            function ($value) use (&$state, $link) {
                $state['sku'] = $value;
                return $link;
            }
        );
        $link->method('setLinkType')->willReturnCallback(
            function ($value) use (&$state, $link) {
                $state['type'] = $value;
                return $link;
            }
        );
        $link->method('setLinkedProductSku')->willReturnCallback(
            function ($value) use (&$state, $link) {
                $state['linked'] = $value;
                return $link;
            }
        );
        $link->method('setPosition')->willReturnCallback(
            function ($value) use (&$state, $link) {
                $state['position'] = $value;
                return $link;
            }
        );
        // Closures, not arrow functions: an arrow function captures by value,
        // so these getters would keep answering with the initial nulls.
        $link->method('getSku')->willReturnCallback(static function () use (&$state) {
            return $state['sku'];
        });
        $link->method('getLinkType')->willReturnCallback(static function () use (&$state) {
            return $state['type'];
        });
        $link->method('getLinkedProductSku')->willReturnCallback(static function () use (&$state) {
            return $state['linked'];
        });
        $link->method('getPosition')->willReturnCallback(static function () use (&$state) {
            return $state['position'];
        });

        return $link;
    }

    /**
     * @param string $name
     * @return ProductLinkTypeInterface
     */
    private function linkType(string $name): ProductLinkTypeInterface
    {
        $type = $this->createMock(ProductLinkTypeInterface::class);
        $type->method('getName')->willReturn($name);

        return $type;
    }
}
