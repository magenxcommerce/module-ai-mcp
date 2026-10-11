<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Test\Unit\Model\Protocol;

use Magenx\AiMcp\Model\Auth\Identity;
use Magenx\AiMcp\Model\Config;
use Magenx\AiMcp\Model\Protocol\JsonRpc;
use Magenx\AiMcp\Model\Protocol\Server;
use Magenx\AiMcp\Model\Tool\ToolCatalog;
use Magenx\AiMcp\Model\Tool\ToolRegistry;
use Magenx\AiMcp\Test\Unit\Fixture\Model\Tool\Catalog\Option\NestedTool;
use Magenx\AiMcp\Test\Unit\Fixture\Model\Tool\Sales\FlatTool\Proxy as FlatToolProxy;
use Magento\Framework\Serialize\Serializer\Json;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The toolset a connection narrows itself to in its endpoint URL.
 *
 * What has to hold is that the URL only ever removes tools. It is set by
 * whoever configures the client, not by the store, so a URL that could add a
 * tool back would turn the operator's toolset settings into a suggestion.
 *
 * @see Server::dispatch
 */
class ServerToolsetTest extends TestCase
{
    /**
     * @return void
     */
    public function testTheUrlNarrowsTheList(): void
    {
        $names = $this->listedNames($this->server(), ['domains' => 'sales']);

        $this->assertSame(['flat_tool'], $names);
    }

    /**
     * The store offers only catalog; a URL asking for sales gets nothing, not
     * sales.
     *
     * @return void
     */
    public function testTheUrlCannotWidenTheStoreSettings(): void
    {
        $names = $this->listedNames($this->server(['catalog']), ['domains' => 'sales']);

        $this->assertSame([], $names);
    }

    /**
     * @return void
     */
    public function testTheUrlCannotBringBackADisabledTool(): void
    {
        $names = $this->listedNames($this->server([], ['flat_tool']), ['tools' => 'flat_tool']);

        $this->assertSame([], $names);
    }

    /**
     * @return void
     */
    public function testCallingAToolOutsideTheSliceIsAnsweredAsUnknown(): void
    {
        $body = $this->server()->dispatch(
            'tools/call',
            ['name' => 'nested_tool', 'arguments' => []],
            1,
            $this->identity(),
            ['domains' => 'sales']
        )->getBody();

        $this->assertSame('Unknown tool: nested_tool', $body['error']['message']);
    }

    /**
     * @return void
     */
    public function testAnUnknownDomainIsAnInvalidParamsError(): void
    {
        $body = $this->server()->dispatch('tools/list', [], 1, $this->identity(), ['domains' => 'sails'])
            ->getBody();

        $this->assertSame(JsonRpc::INVALID_PARAMS, $body['error']['code']);
        $this->assertStringContainsString('sails', $body['error']['message']);
    }

    /**
     * A caller without the endpoint grant learns nothing, not even the domain
     * list an unknown name would be answered with.
     *
     * @return void
     */
    public function testTheToolsetIsNotResolvedBeforeTheEndpointGrant(): void
    {
        $body = $this->server()->dispatch(
            'tools/list',
            [],
            1,
            new Identity(1, 1, 'test', []),
            ['domains' => 'sails']
        )->getBody();

        $this->assertStringNotContainsString('This server has', $body['error']['message']);
    }

    /**
     * @return void
     */
    public function testAToolOutsideTheSliceIsNeverBuilt(): void
    {
        $proxy = new FlatToolProxy();
        $registry = new ToolRegistry(['flat_tool' => $proxy, 'nested_tool' => new NestedTool()]);

        $this->listedNames($this->server([], [], $registry), ['domains' => 'catalog']);

        $this->assertFalse($proxy->woken, 'A tool outside the URL toolset was built anyway.');
    }

    /**
     * The handshake names the areas this connection offers, so an agent's
     * first read of the server matches what tools/list will hold.
     *
     * @return void
     */
    public function testTheHandshakeNamesOnlyTheOfferedDomains(): void
    {
        $instructions = $this->server()->dispatch('initialize', [], 1, $this->identity(), ['domains' => 'sales'])
            ->getBody()['result']['instructions'];

        $this->assertStringContainsString('Tool areas on this connection: Sales.', $instructions);
        $this->assertStringNotContainsString('Catalog', $instructions);
    }

    /**
     * @return void
     */
    public function testTheHandshakeBuildsNoTool(): void
    {
        $proxy = new FlatToolProxy();

        $this->server([], [], new ToolRegistry(['flat_tool' => $proxy]))
            ->dispatch('initialize', [], 1, $this->identity());

        $this->assertFalse($proxy->woken);
    }

    /**
     * @param Server $server
     * @param array<string, string> $toolset
     * @return string[]
     */
    private function listedNames(Server $server, array $toolset): array
    {
        $tools = $server->dispatch('tools/list', [], 1, $this->identity(), $toolset)->getBody()['result']['tools'];

        return array_column($tools, 'name');
    }

    /**
     * @param string[] $domains
     * @param string[] $disabled
     * @param ToolRegistry|null $registry
     * @return Server
     */
    private function server(array $domains = [], array $disabled = [], ?ToolRegistry $registry = null): Server
    {
        $config = $this->createMock(Config::class);
        $config->method('isWriteAllowed')->willReturn(true);
        $config->method('getEnabledToolDomains')->willReturn($domains);
        $config->method('getDisabledTools')->willReturn($disabled);

        $registry ??= new ToolRegistry(['flat_tool' => new FlatToolProxy(), 'nested_tool' => new NestedTool()]);

        return new Server(
            $registry,
            new ToolCatalog($registry),
            $config,
            new JsonRpc(),
            new Json(),
            $this->createMock(LoggerInterface::class),
            '1.0.0'
        );
    }

    /**
     * @return Identity
     */
    private function identity(): Identity
    {
        return new Identity(1, 1, 'test', [Server::ENDPOINT_RESOURCE]);
    }
}
