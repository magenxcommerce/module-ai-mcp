<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Test\Unit\Model\Tool\Log;

use Magenx\AiMcp\Model\Tool\Log\LogRedactor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * What the log tools mask before a line leaves the store.
 *
 * Both directions matter. A pattern that misses leaks a customer's data to an
 * agent; a pattern that over-reaches masks the order number or class name the
 * agent is reading the log to find.
 *
 * @see LogRedactor
 */
class LogRedactorTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function masked(): array
    {
        return [
            'email' => [
                'main.ERROR: No such entity with email = jane.doe+shop@example.co.uk',
                'main.ERROR: No such entity with email = [email]',
            ],
            'bearer header' => [
                'Authorization: Bearer abc.DEF-123',
                'Authorization: Bearer [redacted]',
            ],
            'json pair' => [
                '{"username":"admin","password":"hunter2"}',
                '{"username":"admin","password":"[redacted]"}',
            ],
            'json pair logged inside json' => [
                '{"payload":"{\"api_key\":\"sk_live_x\"}"}',
                '{"payload":"{\"api_key\":\"[redacted]\"}"}',
            ],
            'query string' => [
                'GET /checkout?access_token=xyz&step=2',
                'GET /checkout?access_token=[redacted]&step=2',
            ],
            'cookie header' => [
                'Cookie: PHPSESSID=abc123; form_key=xyz',
                'Cookie: [redacted]; form_key=[redacted]',
            ],
            'card number that passes luhn' => [
                'card 4111 1111 1111 1111 declined',
                'card [card] declined',
            ],
            'opaque token' => [
                'integration token 0a1b2c3d4e5f6a7b8c9d0e1f2a3b4c5d rejected',
                'integration token [token] rejected',
            ],
            'ipv4 host part' => [
                'Blocked 203.0.113.42 for too many attempts',
                'Blocked 203.0.113.x for too many attempts',
            ],
        ];
    }

    /**
     * @param string $line
     * @param string $expected
     * @return void
     */
    #[DataProvider('masked')]
    public function testItMasks(string $line, string $expected): void
    {
        $this->assertSame($expected, (new LogRedactor())->redact($line));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function untouched(): array
    {
        return [
            'order increment' => ['Order #000000123 could not be invoiced'],
            'timestamp' => ['[2026-10-06T10:15:00.123456+00:00] main.CRITICAL: Cart 1728208500123 failed'],
            'digit run failing luhn' => ['report 1234567890123 written'],
            'stack frame' => [
                '#3 /var/www/vendor/magento/framework/Interception/Interceptor.php(58): '
                . 'Magento\\Quote\\Model\\QuoteManagement->placeOrder()',
            ],
            'long identifier without digits' => ['Magento\\Catalog\\Model\\ResourceModel\\ProductAttributeRepositoryInterceptor'],
        ];
    }

    /**
     * @param string $line
     * @return void
     */
    #[DataProvider('untouched')]
    public function testItLeavesAlone(string $line): void
    {
        $this->assertSame($line, (new LogRedactor())->redact($line));
    }
}
