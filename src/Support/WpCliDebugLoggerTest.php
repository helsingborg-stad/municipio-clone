<?php

declare(strict_types=1);

namespace MunicipioClone\Tests\Support;

use MunicipioClone\Support\WpCliDebugLogger;
use PHPUnit\Framework\TestCase;

/**
 * @covers \MunicipioClone\Support\WpCliDebugLogger
 */
class WpCliDebugLoggerTest extends TestCase
{
    public function testInfoWritesStructuredEntryToDebugGroup(): void
    {
        \WP_CLI::$debugMessages = [];
        $logger = new WpCliDebugLogger();

        $logger->info('municipio_clone_stage_started', ['stage' => 'remote_export']);

        $this->assertSame([
            ['{"message":"municipio_clone_stage_started","context":{"stage":"remote_export"}}', 'municipio-clone'],
        ], \WP_CLI::$debugMessages);
    }
}
