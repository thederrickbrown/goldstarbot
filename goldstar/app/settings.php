<?php
declare(strict_types=1);

use DI\ContainerBuilder;
use Monolog\Logger;

return function (ContainerBuilder $containerBuilder) {

    // Load private config (secrets) from above the web root.
    $configFile = __DIR__ . '/../config.local.php';
    if (!is_file($configFile)) {
        $configFile = __DIR__ . '/../config.example.php';
    }
    $config = require $configFile;

    $containerBuilder->addDefinitions([
        'settings' => [
            'displayErrorDetails' => $config['displayErrorDetails'] ?? false,
            'logger' => [
                'name'  => 'goldstarbot',
                'path'  => __DIR__ . '/../logs/app.log',
                'level' => Logger::DEBUG,
            ],
            'db'     => $config['db'],
            'slack'  => $config['slack'],
            'admin'  => $config['admin'] ?? [],
            'stripe' => $config['stripe'] ?? [],
            'mail'   => $config['mail'] ?? [],
        ],
    ]);
};
