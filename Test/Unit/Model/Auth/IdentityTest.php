<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Test\Unit\Model\Auth;

use Magenx\AiMcp\Model\Auth\Identity;
use Magento\Authorization\Model\UserContextInterface;
use PHPUnit\Framework\TestCase;

/**
 * @see Identity
 */
class IdentityTest extends TestCase
{
    /**
     * An empty resource is the "endpoint grant is enough" case tools use.
     *
     * @return void
     */
    public function testEmptyResourceIsAlwaysAllowed(): void
    {
        $this->assertTrue($this->identity([])->isAllowed(''));
    }

    /**
     * @return void
     */
    public function testHeldResourceIsAllowed(): void
    {
        $identity = $this->identity(['Magenx_AiMcp::server', 'Magento_Catalog::products']);

        $this->assertTrue($identity->isAllowed('Magento_Catalog::products'));
    }

    /**
     * @return void
     */
    public function testResourceNotHeldIsRefused(): void
    {
        $identity = $this->identity(['Magento_Catalog::products']);

        $this->assertFalse($identity->isAllowed('Magenx_AiMcp::server'));
    }

    /**
     * A role with every resource selected resolves to this single grant, and
     * must satisfy any check.
     *
     * @return void
     */
    public function testBackendAllIsASuperUserGrant(): void
    {
        $identity = $this->identity(['Magento_Backend::all']);

        $this->assertTrue($identity->isAllowed('Magenx_AiMcp::server'));
        $this->assertTrue($identity->isAllowed('Magento_Config::config'));
    }

    /**
     * A role that resolved to nothing must grant nothing beyond the empty
     * resource — this is the shape Authenticator falls back to when the ACL
     * retriever throws.
     *
     * @return void
     */
    public function testEmptyGrantListRefusesEveryNamedResource(): void
    {
        $this->assertFalse($this->identity([])->isAllowed('Magenx_AiMcp::server'));
    }

    /**
     * Resource ids are compared exactly; a near-miss must not pass.
     *
     * @return void
     */
    public function testComparisonIsExact(): void
    {
        $identity = $this->identity(['Magenx_AiMcp::serverX']);

        $this->assertFalse($identity->isAllowed('Magenx_AiMcp::server'));
    }

    /**
     * @param string[] $resources
     * @return Identity
     */
    private function identity(array $resources): Identity
    {
        return new Identity(
            UserContextInterface::USER_TYPE_INTEGRATION,
            7,
            'integration#7',
            $resources
        );
    }
}
