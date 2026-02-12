<?php

declare(strict_types=1);

use Psr\Log\LoggerInterface;
use Yiisoft\Log\Logger;
use Yiisoft\Log\Target\File\FileTarget;

/**
 * Logger DI configuration.
 */
return [
    LoggerInterface::class => [
        'class' => Logger::class,
        '__construct()' => [
            'targets' => [\Yiisoft\Definitions\Reference::to(FileTarget::class)],
        ],
    ],
];
