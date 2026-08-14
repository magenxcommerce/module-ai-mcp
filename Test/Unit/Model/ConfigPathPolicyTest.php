<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Test\Unit\Model;

use Magenx\AiMcp\Model\Config;
use Magenx\AiMcp\Model\ConfigPathPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The gate that decides whether an agent may overwrite a configuration value.
 *
 * @see ConfigPathPolicy
 */
class ConfigPathPolicyTest extends TestCase
{
    private Config&MockObject $config;

    private ConfigPathPolicy $policy;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        $this->config = $this->createMock(Config::class);
        $this->policy = new ConfigPathPolicy($this->config);
    }

    /**
     * @param string $path
     * @return void
     */
    #[DataProvider('secretPathProvider')]
    public function testSecretPathsAreDetected(string $path): void
    {
        $this->assertTrue($this->policy->isSecret($path));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function secretPathProvider(): array
    {
        return [
            'password' => ['some/group/password'],
            'secret' => ['payment/gateway/secret'],
            'private_key' => ['payment/gateway/private_key'],
            'encryption_key' => ['crypt/encryption_key'],
            'api_key' => ['carriers/ups/api_key'],
            'apikey' => ['carriers/ups/apikey'],
            'access_key' => ['aws/s3/access_key'],
            'access_token' => ['social/fb/access_token'],
            'token' => ['some/group/token'],
            'credential' => ['some/group/credential'],
            'signature' => ['some/group/signature'],
            'salt' => ['some/group/salt'],
            'merchant_id' => ['payment/x/merchant_id'],
            'client_secret' => ['oauth/x/client_secret'],
            'uppercase still matches' => ['Payment/Gateway/PASSWORD'],
            'embedded in a longer segment' => ['payment/stripe/webhook_secret_key'],
        ];
    }

    /**
     * @return void
     */
    public function testOrdinaryPathIsNotSecret(): void
    {
        $this->assertFalse($this->policy->isSecret('catalog/frontend/grid_per_page'));
    }

    /**
     * @param string|null $value
     * @param bool $expected
     * @return void
     */
    #[DataProvider('encryptedValueProvider')]
    public function testLooksEncrypted(?string $value, bool $expected): void
    {
        $this->assertSame($expected, $this->policy->looksEncrypted($value));
    }

    /**
     * @return array<string, array{0: string|null, 1: bool}>
     */
    public static function encryptedValueProvider(): array
    {
        return [
            'magento blob' => ['0:3:abcdef==', true],
            'multi digit version' => ['10:22:xyz', true],
            'null' => [null, false],
            'empty' => ['', false],
            'plain text' => ['a plain value', false],
            'looks similar but is not' => ['1:2', false],
            'digits only' => ['12345', false],
        ];
    }

    /**
     * A secret-bearing path is refused before the allowlist is even consulted,
     * so no admin setting can open it.
     *
     * @return void
     */
    public function testSecretPathIsRefusedEvenWhenAllowlisted(): void
    {
        $this->config->method('getAllowedConfigPaths')->willReturn(['*']);

        $refusal = $this->policy->refuseWriteReason('payment/gateway/api_key');

        $this->assertNotNull($refusal);
        $this->assertStringContainsString('credential', $refusal->render());
    }

    /**
     * @param string $path
     * @return void
     */
    #[DataProvider('protectedPathProvider')]
    public function testProtectedPrefixIsRefusedEvenWhenAllowlisted(string $path): void
    {
        $this->config->method('getAllowedConfigPaths')->willReturn(['*']);

        $refusal = $this->policy->refuseWriteReason($path);

        $this->assertNotNull($refusal, sprintf('Expected "%s" to be refused.', $path));
        $this->assertStringContainsString('protected', $refusal->render());
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function protectedPathProvider(): array
    {
        return [
            'admin' => ['admin/url/use_custom'],
            'oauth' => ['oauth/consumer/expiration_period'],
            'crypt' => ['crypt/something'],
            'system security' => ['system/security/max_session_size_admin'],
            'this module itself' => ['magenx_ai_mcp/security/allow_writes'],
            // Case must not be a way around the denylist.
            'uppercase admin' => ['Admin/url/use_custom'],
            'uppercase module' => ['MAGENX_AI_MCP/security/allow_writes'],
        ];
    }

    /**
     * The default: no paths listed means no write is possible.
     *
     * @return void
     */
    public function testEmptyAllowlistRefusesEverything(): void
    {
        $this->config->method('getAllowedConfigPaths')->willReturn([]);

        $refusal = $this->policy->refuseWriteReason('catalog/frontend/grid_per_page');

        $this->assertNotNull($refusal);
        $this->assertStringContainsString('No configuration paths are writable', $refusal->render());
    }

    /**
     * @param string[] $allowed
     * @param string $path
     * @param bool $permitted
     * @return void
     */
    #[DataProvider('allowlistProvider')]
    public function testAllowlistGlobs(array $allowed, string $path, bool $permitted): void
    {
        $this->config->method('getAllowedConfigPaths')->willReturn($allowed);

        $refusal = $this->policy->refuseWriteReason($path);

        $this->assertSame($permitted, $refusal === null);
    }

    /**
     * @return array<string, array{0: string[], 1: string, 2: bool}>
     */
    public static function allowlistProvider(): array
    {
        return [
            'exact match' => [['catalog/frontend/grid_per_page'], 'catalog/frontend/grid_per_page', true],
            'trailing wildcard' => [['catalog/frontend/*'], 'catalog/frontend/grid_per_page', true],
            'section wildcard' => [['catalog/*'], 'catalog/frontend/grid_per_page', true],
            'no match' => [['catalog/*'], 'design/head/logo_alt', false],
            'one of several patterns' => [
                ['catalog/*', 'design/*'],
                'design/head/logo_alt',
                true,
            ],
            'pattern case is normalised too' => [['Catalog/Frontend/*'], 'catalog/frontend/list_mode', true],
        ];
    }
}
