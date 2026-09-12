<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Test\Unit;

/**
 * Makes Magento's generated `*Factory` classes mockable in a unit test run.
 *
 * Magento does not ship a file for `RegionInterfaceFactory` and its siblings:
 * they are written out by the framework's code generator, which runs from a
 * Magento application bootstrap. A module is tested in place rather than inside
 * a Magento installation, so nothing generates them and the class name has no
 * definition — and PHPUnit cannot mock a type that does not exist.
 *
 * Giving the name a definition here keeps the production classes idiomatic.
 * A tool that needs a data object injects the generated factory, exactly as
 * Magento's own code does; only the test supplies the missing declaration.
 *
 * Where the generator *is* available — running the suite from inside a Magento
 * root — `class_exists()` triggers it and the real factory is used instead, so
 * the test exercises the same shape either way.
 */
final class GeneratedFactory
{
    /**
     * Ensure the named factory class is loadable.
     *
     * Safe to call more than once for the same name, and safe to call for a
     * factory that already exists.
     *
     * @param string $factoryClass Fully-qualified name of a generated factory.
     * @return void
     */
    public static function ensure(string $factoryClass): void
    {
        // Attempts autoloading, which is what runs Magento's generator when
        // its autoloader is registered.
        if (class_exists($factoryClass)) {
            return;
        }

        class_alias(FactoryStub::class, $factoryClass);
    }
}
