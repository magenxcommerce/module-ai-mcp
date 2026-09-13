<?php
/**
 * Copyright © Magenx. All rights reserved.
 *
 * Bootstrap for the unit suite.
 *
 * A Magento module is tested in place rather than from inside a Magento
 * installation, so nothing has generated an autoloader for it. This maps the
 * module's own PSR-4 prefix onto the checkout, and — only when the real
 * framework is absent — loads the stand-ins beside it so the suite can run on
 * a bare PHP with PHPUnit and nothing else. `registration.php` is deliberately
 * not required: it calls into magento/framework.
 */
declare(strict_types=1);

$root = dirname(__DIR__, 3);

if (is_file($root . '/vendor/autoload.php')) {
    require $root . '/vendor/autoload.php';
}

spl_autoload_register(static function (string $class) use ($root): void {
    $prefix = 'Magenx\\AiMcp\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }

    $file = $root . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

// One probe for the whole set: either a Magento installation is on the
// autoloader and every real class is reachable, or none of them are. Loading
// the stand-ins alongside a real framework would be the harmful case, so the
// check is deliberately conservative about when it declares anything.
if (!class_exists(\Magento\Framework\Exception\LocalizedException::class)) {
    require __DIR__ . '/magento-stubs.php';
}
