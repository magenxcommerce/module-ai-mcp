<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Protocol;

use Magenx\AiMcp\Api\ToolInterface;
use Magenx\AiMcp\Model\Auth\Identity;
use Magenx\AiMcp\Model\Config;
use Magenx\AiMcp\Model\Tool\ToolRegistry;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Serialize\Serializer\Json;
use Psr\Log\LoggerInterface;

/**
 * The MCP method dispatcher.
 *
 * Owns every cross-cutting guard so an individual tool cannot forget one:
 *
 *  1. ACL — the calling integration must hold the tool's resource.
 *  2. The store-level write switch — write tools are neither listed nor callable
 *     while it is off.
 *  3. The confirm gate — a write tool called without `confirm: true` returns a
 *     preview of what it would do and changes nothing.
 *  4. The audit log — every attempted write is recorded with the caller.
 *
 * A tool that is not permitted is also not *listed*, so an agent never plans
 * around a capability it cannot use.
 */
class Server
{
    /** MCP protocol revision this server implements. */
    public const PROTOCOL_VERSION = '2025-06-18';

    private const SERVER_NAME = 'magenx-magento';
    private const SERVER_VERSION = '1.0.0';

    /**
     * @param ToolRegistry $registry
     * @param Config $config
     * @param JsonRpc $jsonRpc
     * @param Json $serializer
     * @param LoggerInterface $auditLogger
     */
    public function __construct(
        private readonly ToolRegistry $registry,
        private readonly Config $config,
        private readonly JsonRpc $jsonRpc,
        private readonly Json $serializer,
        private readonly LoggerInterface $auditLogger
    ) {
    }

    /**
     * Dispatch one JSON-RPC call.
     *
     * @param string $method
     * @param array<string, mixed> $params
     * @param string|int|null $id
     * @param Identity $identity
     * @return Outcome
     */
    public function dispatch(string $method, array $params, string|int|null $id, Identity $identity): Outcome
    {
        // Notifications carry no id and take no response body.
        if (str_starts_with($method, 'notifications/')) {
            return new Outcome(202, null);
        }

        return match ($method) {
            'initialize' => $this->ok($id, $this->initialize()),
            // MCP's ping result is an empty *object*; an empty PHP array would
            // serialize as `[]` and some clients reject that.
            'ping' => $this->ok($id, new \stdClass()),
            'tools/list' => $this->ok($id, ['tools' => $this->listTools($identity)]),
            'tools/call' => $this->callTool($params, $id, $identity),
            default => $this->fail($id, JsonRpc::METHOD_NOT_FOUND, sprintf('Unknown method: %s', $method)),
        };
    }

    /**
     * Handshake payload.
     *
     * @return array<string, mixed>
     */
    private function initialize(): array
    {
        return [
            'protocolVersion' => self::PROTOCOL_VERSION,
            'capabilities' => ['tools' => ['listChanged' => false]],
            'serverInfo' => ['name' => self::SERVER_NAME, 'version' => self::SERVER_VERSION],
            'instructions' => 'Manage this Magento store: read and edit products, categories, CMS '
                . 'content and store configuration, and flush caches or invalidate indexers. '
                . 'Call list_stores first when a change should apply to one store view rather than '
                . 'the default scope. Tools that change data require "confirm": true — calling them '
                . 'without it returns a preview and changes nothing.',
        ];
    }

    /**
     * The tools this caller may actually use.
     *
     * @param Identity $identity
     * @return array<int, array<string, mixed>>
     */
    private function listTools(Identity $identity): array
    {
        $tools = [];
        foreach ($this->registry->getAll() as $tool) {
            if (!$this->isAvailable($tool, $identity)) {
                continue;
            }
            $tools[] = [
                'name' => $tool->getName(),
                'description' => $tool->getDescription(),
                'inputSchema' => $this->buildInputSchema($tool),
            ];
        }

        return $tools;
    }

    /**
     * Run a tool call through the guards.
     *
     * @param array<string, mixed> $params
     * @param string|int|null $id
     * @param Identity $identity
     * @return Outcome
     */
    private function callTool(array $params, string|int|null $id, Identity $identity): Outcome
    {
        $name = $params['name'] ?? null;
        if (!is_string($name) || $name === '') {
            return $this->fail($id, JsonRpc::INVALID_PARAMS, 'Missing tool name.');
        }

        $tool = $this->registry->get($name);
        if ($tool === null || !$identity->isAllowed($tool->getAclResource())) {
            // Deliberately the same answer for "no such tool" and "your
            // integration may not use it": an unauthorized caller learns
            // nothing about the surface it cannot reach.
            return $this->fail($id, JsonRpc::METHOD_NOT_FOUND, sprintf('Unknown tool: %s', $name));
        }

        if ($tool->isWrite() && !$this->config->isWriteAllowed()) {
            // The caller is entitled to this tool; the store has writes turned
            // off. Saying so plainly beats an agent retrying a phantom tool.
            return $this->ok($id, $this->toolError(
                'This server is currently read-only. An administrator can enable changes under '
                . 'Stores > Configuration > Magenx > AI MCP Server > Allow Write Tools.'
            ));
        }

        $arguments = $params['arguments'] ?? [];
        if (!is_array($arguments)) {
            return $this->fail($id, JsonRpc::INVALID_PARAMS, 'Tool arguments must be an object.');
        }

        if ($tool->isWrite() && ($arguments['confirm'] ?? false) !== true) {
            return $this->ok($id, $this->preview($tool, $arguments));
        }

        try {
            $result = $tool->execute($arguments);
            if ($tool->isWrite()) {
                $this->audit($tool, $arguments, $identity, 'applied');
            }

            return $this->ok($id, $this->toolResult($result));
        } catch (LocalizedException $e) {
            // An expected failure the agent can act on: wrong sku, invalid
            // value, disallowed config path. Reported as a tool error, not a
            // protocol error, so the conversation can continue.
            if ($tool->isWrite()) {
                $this->audit($tool, $arguments, $identity, 'refused: ' . $e->getMessage());
            }

            return $this->ok($id, $this->toolError($e->getMessage()));
        } catch (\Throwable $e) {
            $this->auditLogger->error('[magenx-mcp] tool failure', [
                'tool' => $tool->getName(),
                'caller' => $identity->getLabel(),
                'exception' => $e->getMessage(),
            ]);

            return $this->ok($id, $this->toolError('The tool failed unexpectedly. See var/log for details.'));
        }
    }

    /**
     * Whether a tool is both permitted by ACL and enabled by the store switch.
     *
     * @param ToolInterface $tool
     * @param Identity $identity
     * @return bool
     */
    private function isAvailable(ToolInterface $tool, Identity $identity): bool
    {
        if ($tool->isWrite() && !$this->config->isWriteAllowed()) {
            return false;
        }

        return $identity->isAllowed($tool->getAclResource());
    }

    /**
     * A write tool's schema always carries the confirm flag, so the agent can
     * see the gate rather than discovering it by being refused.
     *
     * @param ToolInterface $tool
     * @return array<string, mixed>
     */
    private function buildInputSchema(ToolInterface $tool): array
    {
        $schema = $tool->getInputSchema();
        if (!$tool->isWrite()) {
            return $schema;
        }

        $schema['properties']['confirm'] = [
            'type' => 'boolean',
            'description' => 'Set true to apply the change. Omit or set false to receive a preview '
                . 'of exactly what would be changed, without changing anything.',
        ];

        return $schema;
    }

    /**
     * The dry run: what this call would do, without doing it.
     *
     * @param ToolInterface $tool
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    private function preview(ToolInterface $tool, array $arguments): array
    {
        unset($arguments['confirm']);

        return $this->toolResult([
            'preview' => true,
            'tool' => $tool->getName(),
            'arguments' => $arguments,
            'message' => sprintf(
                'Nothing was changed. Call %s again with "confirm": true to apply this.',
                $tool->getName()
            ),
        ]);
    }

    /**
     * Wrap a structured payload as an MCP tool result.
     *
     * The JSON also goes in the `content` text block because not every client
     * surfaces `structuredContent` to the model yet.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function toolResult(array $payload): array
    {
        return [
            'content' => [['type' => 'text', 'text' => $this->serializer->serialize($payload)]],
            'structuredContent' => $payload,
        ];
    }

    /**
     * A tool-level error: `isError` is reserved for "there is no result to
     * return", never for a business outcome the agent should read.
     *
     * @param string $message
     * @return array<string, mixed>
     */
    private function toolError(string $message): array
    {
        return [
            'content' => [['type' => 'text', 'text' => $message]],
            'isError' => true,
        ];
    }

    /**
     * Record an attempted write.
     *
     * @param ToolInterface $tool
     * @param array<string, mixed> $arguments
     * @param Identity $identity
     * @param string $verdict
     * @return void
     */
    private function audit(ToolInterface $tool, array $arguments, Identity $identity, string $verdict): void
    {
        unset($arguments['confirm']);
        $this->auditLogger->info('[magenx-mcp] write', [
            'tool' => $tool->getName(),
            'caller' => $identity->getLabel(),
            'user_type' => $identity->getUserType(),
            'user_id' => $identity->getUserId(),
            'verdict' => $verdict,
            'arguments' => $arguments,
        ]);
    }

    /**
     * @param string|int|null $id
     * @param array<string, mixed>|object $result
     * @return Outcome
     */
    private function ok(string|int|null $id, array|object $result): Outcome
    {
        return new Outcome(200, $this->jsonRpc->result($id, $result));
    }

    /**
     * @param string|int|null $id
     * @param int $code
     * @param string $message
     * @return Outcome
     */
    private function fail(string|int|null $id, int $code, string $message): Outcome
    {
        return new Outcome(200, $this->jsonRpc->error($id, $code, $message));
    }
}
