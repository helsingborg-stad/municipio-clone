<?php

declare(strict_types=1);

namespace MunicipioClone\Tests\Unit\Authentication;

use MunicipioClone\Authentication\ApiKeyAuthenticator;
use MunicipioClone\Capability\CapabilityRegistrar;
use PHPUnit\Framework\TestCase;
use WpService\Implementations\FakeWpService;

/**
 * @covers \MunicipioClone\Authentication\ApiKeyAuthenticator
 */
class ApiKeyAuthenticatorTest extends TestCase
{
    public function testAuthenticateReturnsUserIdForValidCapabilityBoundKey(): void
    {
        $wpService = new FakeWpService();
        $wpService->users = [(object) ['ID' => 12]];
        $wpService->capabilities[12][CapabilityRegistrar::CAPABILITY] = true;
        $wpService->userMeta[12]['municipio_clone_api_keys'] = [password_hash('secret-key', PASSWORD_DEFAULT)];
        $request = new class() {
            public function get_header(string $name): string
            {
                return $name === 'X-Municipio-Clone-Key' ? 'secret-key' : '';
            }
        };

        $result = (new ApiKeyAuthenticator($wpService))->authenticate($request);

        $this->assertSame(['user_id' => 12], $result);
    }
}
