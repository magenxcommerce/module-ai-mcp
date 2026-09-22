<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Test\Unit;

/**
 * Reads the registered tools straight out of `etc/di.xml`.
 *
 * The conventions tests check every tool at once, and they read the inventory
 * from di.xml rather than a list kept beside them so that a tool added next
 * year is covered the moment it is registered. That parsing was copied between
 * them before it was shared; it is here now because the `\Proxy` suffix made it
 * three copies with one thing to learn.
 *
 * Each tool is registered as a proxy, which is a generated class that exists
 * only inside a compiled Magento installation — never in this suite, which runs
 * on a bare PHP. So the suffix comes off here, and what callers get back is the
 * tool's own class: the one they can reflect.
 */
trait RegisteredTools
{
    /**
     * Every tool registered in di.xml, as name => fully-qualified class.
     *
     * @return array<string, class-string>
     */
    private function registeredTools(): array
    {
        $di = file_get_contents(__DIR__ . '/../../etc/di.xml');
        self::assertIsString($di);

        preg_match_all(
            '#<item name="([a-z0-9_]+)" xsi:type="object">'
            . '(Magenx\\\\AiMcp\\\\Model\\\\Tool\\\\[A-Za-z\\\\]+?)(\\\\Proxy)?</item>#',
            $di,
            $matches,
            PREG_SET_ORDER
        );
        self::assertNotEmpty($matches, 'No tools found in di.xml — the pattern has drifted.');

        $tools = [];
        foreach ($matches as $match) {
            $tools[$match[1]] = $match[2];
        }

        return $tools;
    }
}
