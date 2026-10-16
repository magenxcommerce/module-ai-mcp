<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Test\Unit\Model\OAuth;

use Magenx\AiMcp\Model\OAuth\OAuthException;
use Magenx\AiMcp\Model\OAuth\Scope;
use PHPUnit\Framework\TestCase;

/**
 * @see Scope
 */
class ScopeTest extends TestCase
{
    /**
     * @return void
     */
    public function testEmptyRequestOffersBoth(): void
    {
        $this->assertSame([Scope::READ, Scope::WRITE], (new Scope())->parse(''));
        $this->assertSame([Scope::READ, Scope::WRITE], (new Scope())->parse(null));
    }

    /**
     * @return void
     */
    public function testWriteImpliesRead(): void
    {
        $this->assertSame([Scope::READ, Scope::WRITE], (new Scope())->parse('mcp:write'));
        $this->assertSame([Scope::READ], (new Scope())->parse('mcp:read'));
    }

    /**
     * @return void
     */
    public function testUnknownScopeIsRefused(): void
    {
        $this->expectException(OAuthException::class);
        (new Scope())->parse('mcp:read admin');
    }

    /**
     * @return void
     */
    public function testFormatIsCanonical(): void
    {
        $this->assertSame('mcp:read mcp:write', (new Scope())->format([Scope::WRITE, Scope::READ, Scope::WRITE]));
        $this->assertSame('', (new Scope())->format([]));
    }
}
