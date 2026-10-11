<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Test\Unit\Model\Protocol;

use Magenx\AiMcp\Api\PersonalDataToolInterface;
use Magenx\AiMcp\Api\ToolInterface;
use Magenx\AiMcp\Model\Auth\Identity;
use Magenx\AiMcp\Model\Config;
use Magenx\AiMcp\Model\Protocol\JsonRpc;
use Magenx\AiMcp\Model\Protocol\Server;
use Magenx\AiMcp\Model\Tool\ToolCatalog;
use Magenx\AiMcp\Model\Tool\ToolRegistry;
use Magenx\AiMcp\Test\Unit\Fixture\Model\Tool\Customer\ReadFixture;
use Magento\Framework\Serialize\Serializer\Json;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Recording successful reads in the audit log.
 *
 * Two properties carry the whole feature. Off has to mean off, because every
 * installation that upgrades into the setting had no read audit before. And a
 * recorded read must name the records an agent saw without repeating them: a
 * log line holding the customer's address would make the audit file the most
 * sensitive one on the server.
 *
 * @see Server::dispatch
 */
class ServerReadAuditTest extends TestCase
{
    /** @var array<int, array{0: string, 1: array<string, mixed>}> */
    private array $logged = [];

    /**
     * @return void
     */
    public function testOffRecordsNoRead(): void
    {
        $this->call(Config::AUDIT_READS_OFF, $this->personalTool());

        $this->assertSame([], $this->reads());
    }

    /**
     * @return void
     */
    public function testPersonalDataModeRecordsAMarkedTool(): void
    {
        $this->call(Config::AUDIT_READS_PERSONAL_DATA, $this->personalTool());

        $this->assertCount(1, $this->reads());
    }

    /**
     * @return void
     */
    public function testPersonalDataModeSkipsAnUnmarkedTool(): void
    {
        $this->call(Config::AUDIT_READS_PERSONAL_DATA, $this->plainTool());

        $this->assertSame([], $this->reads());
    }

    /**
     * @return void
     */
    public function testAllModeRecordsAnUnmarkedTool(): void
    {
        $this->call(Config::AUDIT_READS_ALL, $this->plainTool());

        $this->assertCount(1, $this->reads());
    }

    /**
     * @return void
     */
    public function testARecordedReadNamesTheRecordsNotTheirContents(): void
    {
        $this->call(Config::AUDIT_READS_ALL, $this->personalTool(), ['email' => 'ann@example.com']);

        $context = $this->reads()[0];
        $this->assertSame('search_people', $context['tool']);
        $this->assertSame('test', $context['caller']);
        $this->assertSame(2, $context['total_count']);
        $this->assertSame(['000000042', 7], $context['records']);
        $this->assertSame(['email' => 'ann@example.com'], $context['arguments']);
        $this->assertStringNotContainsString('Main Street', (string) json_encode($context));
    }

    /**
     * A write is audited as a write already; recording it again as a read
     * would double every line an operator reads.
     *
     * @return void
     */
    public function testAWriteIsNotAlsoRecordedAsARead(): void
    {
        $this->call(Config::AUDIT_READS_ALL, $this->writeTool(), ['confirm' => true]);

        $this->assertSame([], $this->reads());
        $writes = array_filter(
            $this->logged,
            static fn (array $entry): bool => $entry[0] === '[magenx-mcp] write'
        );
        $this->assertNotEmpty($writes);
    }

    /**
     * @param string $mode
     * @param ToolInterface $tool
     * @param array<string, mixed> $arguments
     * @return void
     */
    private function call(string $mode, ToolInterface $tool, array $arguments = []): void
    {
        $config = $this->createMock(Config::class);
        $config->method('isWriteAllowed')->willReturn(true);
        $config->method('getReadAuditMode')->willReturn($mode);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->method('info')->willReturnCallback(function (string $message, array $context = []): void {
            $this->logged[] = [$message, $context];
        });

        $registry = new ToolRegistry([$tool]);
        $server = new Server(
            $registry,
            new ToolCatalog($registry),
            $config,
            new JsonRpc(),
            new Json(),
            $logger,
            '1.0.0'
        );

        $body = $server->dispatch(
            'tools/call',
            ['name' => $tool->getName(), 'arguments' => $arguments],
            1,
            new Identity(1, 1, 'test', [Server::ENDPOINT_RESOURCE])
        )->getBody();
        $this->assertArrayHasKey('result', $body);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function reads(): array
    {
        return array_values(array_map(
            static fn (array $entry): array => $entry[1],
            array_filter($this->logged, static fn (array $entry): bool => $entry[0] === '[magenx-mcp] read')
        ));
    }

    /**
     * @return ToolInterface
     */
    private function personalTool(): ToolInterface
    {
        return new class extends ReadFixture implements PersonalDataToolInterface {
        };
    }

    /**
     * @return ToolInterface
     */
    private function plainTool(): ToolInterface
    {
        return new ReadFixture();
    }

    /**
     * @return ToolInterface
     */
    private function writeTool(): ToolInterface
    {
        return new class extends ReadFixture {
            /**
             * @inheritDoc
             */
            public function isWrite(): bool
            {
                return true;
            }
        };
    }
}
