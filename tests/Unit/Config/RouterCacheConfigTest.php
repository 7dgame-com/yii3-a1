<?php

declare(strict_types=1);

namespace App\Tests\Unit\Config;

use PHPUnit\Framework\TestCase;

final class RouterCacheConfigTest extends TestCase
{
    public function testPersistentRouteCacheIsDisabled(): void
    {
        $params = require dirname(__DIR__, 3) . '/config/common/params.php';

        $this->assertFalse($params['yiisoft/router-fastroute']['enableCache']);
    }
}
