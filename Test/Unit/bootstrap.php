<?php

declare(strict_types=1);

use Magento\Framework\Code\Generator\Io;
use Magento\Framework\Filesystem\Driver\File;
use Magento\Framework\TestFramework\Unit\Autoloader\ExtensionAttributesGenerator;
use Magento\Framework\TestFramework\Unit\Autoloader\ExtensionAttributesInterfaceGenerator;
use Magento\Framework\TestFramework\Unit\Autoloader\FactoryGenerator;
use Magento\Framework\TestFramework\Unit\Autoloader\GeneratedClassesAutoloader;
use Magento\Framework\TestFramework\Unit\Autoloader\ProxyGenerator;

/**
 * Bootstrap for running the unit tests from the module root (phpunit.xml.dist).
 *
 * Inside a store, Magento's own unit test bootstrap generates Factory and Proxy classes on the fly.
 * A standalone install has no store, so this registers the same generator, writing to a temp dir.
 */
require dirname(__DIR__, 2) . '/vendor/autoload.php';

$generatedClasses = new GeneratedClassesAutoloader(
    [
        new ExtensionAttributesGenerator(),
        new ExtensionAttributesInterfaceGenerator(),
        new FactoryGenerator(),
        new ProxyGenerator(),
    ],
    new Io(new File(), sys_get_temp_dir() . '/orangecat-mago-ai-image-generated')
);
spl_autoload_register(static function (string $className) use ($generatedClasses): void {
    $generatedClasses->load($className);
});
