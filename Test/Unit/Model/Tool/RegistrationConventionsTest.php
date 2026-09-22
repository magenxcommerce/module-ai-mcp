<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Test\Unit\Model\Tool;

use Magenx\AiMcp\Api\ToolInterface;
use Magenx\AiMcp\Test\Unit\RegisteredTools;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * How the tools are registered in di.xml, checked against every one at once.
 *
 * Two properties of the registration carry weight at runtime and neither is
 * visible in the file that depends on them:
 *
 * The **key is the tool's name**. {@see \Magenx\AiMcp\Model\Tool\ToolRegistry}
 * files each tool under its di.xml key instead of asking the tool, because
 * asking is what wakes the proxy — so a key that disagreed with `getName()`
 * would put a tool in the registry under a name it does not answer to. The
 * server would then list it as one name and refuse it under that name, while
 * `disabled_tools` silenced a different tool than the operator typed. Nothing
 * would throw, so this is the only place it can be caught.
 *
 * The **class is a `\Proxy`**. Miss one and that tool is built on every
 * request, including the `tools/call` that wanted a different tool entirely —
 * which is invisible, because the omission costs only time and the server
 * behaves identically.
 *
 * Read from di.xml rather than a list kept here, for the reason the other
 * conventions tests give: a tool added next year is covered the moment it is
 * registered. The classes are reflected, never constructed — `getName()` is a
 * literal return, and building one would need the whole Magento object graph.
 */
class RegistrationConventionsTest extends TestCase
{
    use RegisteredTools;

    /**
     * @return void
     */
    public function testEveryToolIsFiledUnderTheNameItAnswersTo(): void
    {
        foreach ($this->registeredTools() as $key => $class) {
            $tool = (new ReflectionClass($class))->newInstanceWithoutConstructor();
            self::assertInstanceOf(ToolInterface::class, $tool);

            self::assertSame(
                $tool->getName(),
                $key,
                sprintf(
                    '%s is registered in di.xml under "%s" but calls itself "%s". The registry files '
                    . 'a tool under its di.xml key, so the two have to agree.',
                    $class,
                    $key,
                    $tool->getName()
                )
            );
        }
    }

    /**
     * @return void
     */
    public function testEveryToolIsRegisteredAsAProxy(): void
    {
        $di = file_get_contents(__DIR__ . '/../../../../etc/di.xml');
        self::assertIsString($di);

        preg_match_all(
            '#<item name="[a-z0-9_]+" xsi:type="object">(Magenx\\\\AiMcp\\\\Model\\\\Tool\\\\[A-Za-z\\\\]+)</item>#',
            $di,
            $matches,
            PREG_SET_ORDER
        );
        self::assertNotEmpty($matches, 'No tools found in di.xml — the pattern has drifted.');

        $unproxied = [];
        foreach ($matches as $match) {
            if (!str_ends_with($match[1], '\\Proxy')) {
                $unproxied[] = $match[1];
            }
        }

        self::assertSame(
            [],
            $unproxied,
            'Registered without \Proxy, so these are built on every request whatever the request asked for.'
        );
    }

    /**
     * The registry sorts by key; the server lists in that order. Duplicate keys
     * would be silently collapsed by the object manager, losing a tool.
     *
     * @return void
     */
    public function testNoNameIsRegisteredTwice(): void
    {
        $di = file_get_contents(__DIR__ . '/../../../../etc/di.xml');
        self::assertIsString($di);

        preg_match_all(
            '#<item name="([a-z0-9_]+)" xsi:type="object">Magenx\\\\AiMcp\\\\Model\\\\Tool\\\\#',
            $di,
            $matches
        );
        self::assertNotEmpty($matches[1], 'No tools found in di.xml — the pattern has drifted.');

        $duplicates = array_keys(array_filter(array_count_values($matches[1]), static fn (int $n): bool => $n > 1));

        self::assertSame([], $duplicates, 'Registered more than once in di.xml.');
    }
}
