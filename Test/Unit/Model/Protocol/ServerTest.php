<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Test\Unit\Model\Protocol;

use Magenx\AiMcp\Api\ToolInterface;
use Magenx\AiMcp\Model\Auth\Identity;
use Magenx\AiMcp\Model\Config;
use Magenx\AiMcp\Model\Protocol\JsonRpc;
use Magenx\AiMcp\Model\Protocol\Server;
use Magenx\AiMcp\Model\Tool\AbstractTool;
use Magenx\AiMcp\Model\Tool\ToolRegistry;
use Magento\Framework\Serialize\Serializer\Json;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * How `tools/list` puts a schema on the wire.
 *
 * The shape matters more than it looks: a client that validates the whole
 * response can drop every tool the server offers over one malformed schema,
 * and the failure is silent — the handshake still succeeds and the server
 * still reads as connected, it just has no tools. A tool taking no arguments
 * is the case that invites it, because PHP's empty array encodes as `[]`
 * where JSON Schema requires an object.
 *
 * @see Server::dispatch
 */
class ServerTest extends TestCase
{
    /**
     * A tool with no arguments must advertise `"properties":{}`, not `[]`.
     *
     * @return void
     */
    public function testEmptyPropertiesAreAdvertisedAsAnObject(): void
    {
        $json = $this->listTools($this->tool('list_things', isWrite: false, properties: []));

        $this->assertStringContainsString('"properties":{}', $json);
        $this->assertStringNotContainsString('"properties":[]', $json);
    }

    /**
     * A tool that already returns an object stays one.
     *
     * @return void
     */
    public function testPropertiesGivenAsAnObjectSurvive(): void
    {
        $json = $this->listTools($this->tool('list_things', isWrite: false, properties: (object) []));

        $this->assertStringContainsString('"properties":{}', $json);
    }

    /**
     * The confirm flag is still added to a write tool, and reaches a write
     * tool that declared its properties as an object rather than an array.
     *
     * @return void
     */
    public function testWriteToolCarriesConfirmWhateverShapeItDeclared(): void
    {
        foreach ([[], (object) []] as $declared) {
            $tools = $this->decode($this->listTools($this->tool('do_thing', isWrite: true, properties: $declared)));

            $this->assertArrayHasKey(
                'confirm',
                $tools[0]['inputSchema']['properties'],
                'A write tool must advertise the confirm gate.'
            );
            $this->assertSame('boolean', $tools[0]['inputSchema']['properties']['confirm']['type']);
        }
    }

    /**
     * Every advertised schema has to be a usable JSON Schema object.
     *
     * @return void
     */
    public function testAdvertisedSchemaKeepsItsOtherKeywords(): void
    {
        $tools = $this->decode($this->listTools($this->tool('list_things', isWrite: false, properties: [])));

        $this->assertSame('object', $tools[0]['inputSchema']['type']);
        $this->assertFalse($tools[0]['inputSchema']['additionalProperties']);
    }

    /**
     * A tool contributed by another module against ToolInterface alone must be
     * advertised exactly as it was before annotations existed. Inventing hints
     * for a tool that never declared any would put this server's guess about
     * someone else's tool on the wire as if the tool had said it.
     *
     * @return void
     */
    public function testAToolWithoutAnnotationsIsAdvertisedUnchanged(): void
    {
        $tools = $this->decode($this->listTools($this->tool('list_things', isWrite: false, properties: [])));

        $this->assertSame(['name', 'description', 'inputSchema'], array_keys($tools[0]));
    }

    /**
     * A tool that does declare them reaches the wire with both the top-level
     * title and the copy inside annotations, because which one a client reads
     * depends on how old it is.
     *
     * @return void
     */
    public function testAnnotationsAndTitleReachTheWire(): void
    {
        $tools = $this->decode($this->listTools($this->annotatedTool()));

        $this->assertSame('Do Thing', $tools[0]['title']);
        $this->assertSame('Do Thing', $tools[0]['annotations']['title']);
        $this->assertFalse($tools[0]['annotations']['readOnlyHint']);
        $this->assertTrue($tools[0]['annotations']['destructiveHint']);
    }

    /**
     * Run tools/list for one tool and return the encoded response.
     *
     * @param ToolInterface $tool
     * @return string
     */
    private function listTools(ToolInterface $tool): string
    {
        $config = $this->createMock(Config::class);
        $config->method('isWriteAllowed')->willReturn(true);

        $server = new Server(
            new ToolRegistry([$tool]),
            $config,
            new JsonRpc(),
            new Json(),
            $this->createMock(LoggerInterface::class),
            '1.0.0'
        );

        $identity = new Identity(1, 1, 'test', [Server::ENDPOINT_RESOURCE]);

        return (string) json_encode($server->dispatch('tools/list', [], 1, $identity)->getBody());
    }

    /**
     * @param string $json
     * @return array<int, array<string, mixed>>
     */
    private function decode(string $json): array
    {
        return json_decode($json, true)['result']['tools'];
    }

    /**
     * A tool that does nothing but declare the schema under test.
     *
     * @param string $name
     * @param bool $isWrite
     * @param array<string, mixed>|object $properties
     * @return ToolInterface
     */
    private function tool(string $name, bool $isWrite, array|object $properties): ToolInterface
    {
        return new class ($name, $isWrite, $properties) implements ToolInterface {
            /**
             * @param string $name
             * @param bool $isWrite
             * @param array<string, mixed>|object $properties
             */
            public function __construct(
                private readonly string $name,
                private readonly bool $isWrite,
                private readonly array|object $properties
            ) {
            }

            /**
             * @inheritDoc
             */
            public function getName(): string
            {
                return $this->name;
            }

            /**
             * @inheritDoc
             */
            public function getDescription(): string
            {
                return 'Test tool.';
            }

            /**
             * @inheritDoc
             */
            public function getInputSchema(): array
            {
                return [
                    'type' => 'object',
                    'properties' => $this->properties,
                    'additionalProperties' => false,
                ];
            }

            /**
             * @inheritDoc
             */
            public function getAclResource(): string
            {
                return '';
            }

            /**
             * @inheritDoc
             */
            public function isWrite(): bool
            {
                return $this->isWrite;
            }

            /**
             * @inheritDoc
             */
            public function execute(array $arguments): array
            {
                return [];
            }
        };
    }

    /**
     * A write tool built on AbstractTool, so the derived title and hints under
     * test are the real ones rather than a fixture's idea of them.
     *
     * @return ToolInterface
     */
    private function annotatedTool(): ToolInterface
    {
        return new class extends AbstractTool {
            /**
             * @inheritDoc
             */
            public function getName(): string
            {
                return 'do_thing';
            }

            /**
             * @inheritDoc
             */
            public function getDescription(): string
            {
                return 'Test tool.';
            }

            /**
             * @inheritDoc
             */
            public function getInputSchema(): array
            {
                return ['type' => 'object', 'properties' => [], 'additionalProperties' => false];
            }

            /**
             * @inheritDoc
             */
            public function getAclResource(): string
            {
                return '';
            }

            /**
             * @inheritDoc
             */
            public function isWrite(): bool
            {
                return true;
            }

            /**
             * @inheritDoc
             */
            public function execute(array $arguments): array
            {
                return [];
            }
        };
    }
}
