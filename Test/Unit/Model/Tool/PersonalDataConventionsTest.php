<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Test\Unit\Model\Tool;

use Magenx\AiMcp\Api\PersonalDataToolInterface;
use Magenx\AiMcp\Api\ToolInterface;
use Magenx\AiMcp\Test\Unit\RegisteredTools;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Which tools carry {@see PersonalDataToolInterface}.
 *
 * The marker is what "Audit Reads: Personal data only" records, so a read tool
 * that returns people and lacks it is a read that setting silently misses. The
 * risk is the next tool, not the current ones, so this checks every read tool
 * in the domains that hold people and makes each exception a named decision.
 *
 * Classes are reflected and instantiated without a constructor, as the other
 * conventions tests do: `isWrite()` is a literal return.
 */
class PersonalDataConventionsTest extends TestCase
{
    use RegisteredTools;

    /** Domains whose records are mostly about a person. */
    private const PERSONAL_DOMAINS = [
        'customer', 'sales', 'quote', 'rma', 'helpdesk', 'gdpr', 'user', 'adminactivity',
    ];

    /**
     * Read tools in those domains that name no one: reference lists, and a
     * cart's shipping rates and payment methods.
     */
    private const NOT_PERSONAL = [
        'list_customer_groups',
        'list_gdpr_cookie_groups',
        'list_gdpr_cookies',
        'list_helpdesk_custom_fields',
        'list_helpdesk_departments',
        'list_helpdesk_gateways',
        'list_helpdesk_priorities',
        'list_helpdesk_spam_patterns',
        'list_helpdesk_statuses',
        'estimate_cart_shipping',
        'list_cart_payment_methods',
        'list_rma_item_conditions',
        'list_rma_reasons',
        'list_rma_resolution_types',
        'list_rma_statuses',
    ];

    /**
     * @return void
     */
    public function testEveryReadOfAPersonalDomainIsMarkedOrNamedAsAnException(): void
    {
        $unmarked = [];
        foreach ($this->registeredTools() as $name => $class) {
            if (!in_array($this->domainOf($class), self::PERSONAL_DOMAINS, true)
                || in_array($name, self::NOT_PERSONAL, true)
            ) {
                continue;
            }
            $tool = $this->instance($class);
            if (!$tool->isWrite() && !$tool instanceof PersonalDataToolInterface) {
                $unmarked[] = $name;
            }
        }

        $this->assertSame(
            [],
            $unmarked,
            'These reads return personal data but are not marked, so the personal-data read audit '
            . 'would miss them. Implement PersonalDataToolInterface, or add them to NOT_PERSONAL.'
        );
    }

    /**
     * An exception that is marked after all, or no longer registered, is a
     * stale entry that would hide the next mistake.
     *
     * @return void
     */
    public function testEveryExceptionIsStillAnUnmarkedRead(): void
    {
        $tools = $this->registeredTools();
        foreach (self::NOT_PERSONAL as $name) {
            $this->assertArrayHasKey($name, $tools, sprintf('%s is no longer registered.', $name));
            $tool = $this->instance($tools[$name]);
            $this->assertFalse($tool->isWrite(), sprintf('%s is a write.', $name));
            $this->assertNotInstanceOf(PersonalDataToolInterface::class, $tool, sprintf('%s is marked.', $name));
        }
    }

    /**
     * Writes are audited already. A marked write would suggest the marker
     * changes something for it, which it does not.
     *
     * @return void
     */
    public function testNoWriteToolIsMarked(): void
    {
        $marked = [];
        foreach ($this->registeredTools() as $name => $class) {
            $tool = $this->instance($class);
            if ($tool->isWrite() && $tool instanceof PersonalDataToolInterface) {
                $marked[] = $name;
            }
        }

        $this->assertSame([], $marked);
    }

    /**
     * @param class-string $class
     * @return ToolInterface
     */
    private function instance(string $class): ToolInterface
    {
        $tool = (new ReflectionClass($class))->newInstanceWithoutConstructor();
        self::assertInstanceOf(ToolInterface::class, $tool);

        return $tool;
    }

    /**
     * @param string $class
     * @return string
     */
    private function domainOf(string $class): string
    {
        return strtolower(explode('\\', $class)[3] ?? '');
    }
}
