<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Test\Unit\Model\Auth;

use Magenx\AiMcp\Model\Auth\Authenticator;
use Magenx\AiMcp\Model\Config;
use Magento\Authorization\Model\Acl\AclRetriever;
use Magento\Framework\HTTP\PhpEnvironment\RemoteAddress;
use Magento\Framework\Stdlib\DateTime\DateTime as CoreDate;
use Magento\Integration\Api\IntegrationServiceInterface;
use Magento\Integration\Helper\Oauth\Data as OauthHelper;
use Magento\Integration\Model\Oauth\TokenFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Covers the hand-rolled CIDR matching behind the source-address allowlist.
 *
 * This is the one piece of security-relevant arithmetic in the module that is
 * not delegated to Magento, so it is worth pinning: an off-by-one in the mask
 * either locks out a legitimate agent or admits a neighbouring subnet.
 *
 * @see Authenticator
 */
class AuthenticatorTest extends TestCase
{
    private Authenticator $authenticator;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        $this->authenticator = new Authenticator(
            $this->createMock(TokenFactory::class),
            $this->createMock(IntegrationServiceInterface::class),
            $this->createMock(AclRetriever::class),
            $this->createMock(OauthHelper::class),
            $this->createMock(CoreDate::class),
            $this->createMock(RemoteAddress::class),
            $this->createMock(Config::class),
            $this->createMock(LoggerInterface::class)
        );
    }

    /**
     * @param string $remote
     * @param string $candidate
     * @param bool $expected
     * @return void
     */
    #[DataProvider('addressProvider')]
    public function testMatchesAddress(string $remote, string $candidate, bool $expected): void
    {
        $method = new \ReflectionMethod(Authenticator::class, 'matchesAddress');

        $this->assertSame($expected, $method->invoke($this->authenticator, $remote, $candidate));
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: bool}>
     */
    public static function addressProvider(): array
    {
        return [
            // Plain addresses.
            'exact ipv4' => ['203.0.113.7', '203.0.113.7', true],
            'different ipv4' => ['203.0.113.8', '203.0.113.7', false],
            'exact ipv6' => ['2001:db8::1', '2001:db8::1', true],
            'empty remote never matches' => ['', '203.0.113.7', false],
            'empty remote never matches a range' => ['', '0.0.0.0/0', false],

            // Whole-byte prefixes.
            'ipv4 /8 inside' => ['10.1.2.3', '10.0.0.0/8', true],
            'ipv4 /8 outside' => ['11.1.2.3', '10.0.0.0/8', false],
            'ipv4 /24 inside' => ['192.168.1.42', '192.168.1.0/24', true],
            'ipv4 /24 outside' => ['192.168.2.42', '192.168.1.0/24', false],

            // Partial-byte prefixes: the mask arithmetic.
            'ipv4 /21 low edge' => ['160.79.104.0', '160.79.104.0/21', true],
            'ipv4 /21 high edge' => ['160.79.111.255', '160.79.104.0/21', true],
            'ipv4 /21 just below' => ['160.79.103.255', '160.79.104.0/21', false],
            'ipv4 /21 just above' => ['160.79.112.0', '160.79.104.0/21', false],
            'ipv4 /31 pair member' => ['203.0.113.7', '203.0.113.6/31', true],
            'ipv4 /31 non-member' => ['203.0.113.8', '203.0.113.6/31', false],

            // Boundary prefix lengths.
            'ipv4 /0 matches everything' => ['8.8.8.8', '0.0.0.0/0', true],
            'ipv4 /32 exact' => ['203.0.113.7', '203.0.113.7/32', true],
            'ipv4 /32 near miss' => ['203.0.113.8', '203.0.113.7/32', false],
            'ipv6 /128 exact' => ['2001:db8::1', '2001:db8::1/128', true],
            'ipv6 /32 inside' => ['2001:db8:1234::9', '2001:db8::/32', true],
            'ipv6 /32 outside' => ['2001:db9:1234::9', '2001:db8::/32', false],

            // Families must not be compared against each other.
            'ipv4 remote against ipv6 range' => ['203.0.113.7', '2001:db8::/32', false],
            'ipv6 remote against ipv4 range' => ['2001:db8::1', '10.0.0.0/8', false],

            // Malformed input must be refused, not fatal.
            'prefix above family width' => ['203.0.113.7', '203.0.113.0/33', false],
            'negative prefix' => ['203.0.113.7', '203.0.113.0/-1', false],
            'non-numeric prefix' => ['203.0.113.7', '203.0.113.0/abc', false],
            'garbage subnet' => ['203.0.113.7', 'not-an-address/24', false],
            'garbage remote' => ['not-an-address', '10.0.0.0/8', false],
        ];
    }
}
