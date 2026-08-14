<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Test\Unit\Model\Protocol;

use Magenx\AiMcp\Model\Protocol\JsonRpc;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @see JsonRpc
 */
class JsonRpcTest extends TestCase
{
    private JsonRpc $jsonRpc;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        $this->jsonRpc = new JsonRpc();
    }

    /**
     * The id must be echoed back with its original JSON type — preserving that
     * is the reason this endpoint is a controller and not a webapi route.
     *
     * @param mixed $raw
     * @param string|int|null $expected
     * @return void
     */
    #[DataProvider('idProvider')]
    public function testNormalizeIdPreservesTypeOrFallsBackToNull(mixed $raw, string|int|null $expected): void
    {
        $this->assertSame($expected, $this->jsonRpc->normalizeId($raw));
    }

    /**
     * @return array<string, array{0: mixed, 1: string|int|null}>
     */
    public static function idProvider(): array
    {
        return [
            'string id' => ['abc', 'abc'],
            'numeric string stays a string' => ['1', '1'],
            'empty string' => ['', ''],
            'integer id' => [42, 42],
            'zero' => [0, 0],
            'negative' => [-1, -1],
            'null' => [null, null],
            'float is not a valid id' => [1.5, null],
            'bool is not a valid id' => [true, null],
            'array is not a valid id' => [['a'], null],
            'object is not a valid id' => [new \stdClass(), null],
        ];
    }

    /**
     * @return void
     */
    public function testResultEnvelope(): void
    {
        $this->assertSame(
            ['jsonrpc' => '2.0', 'id' => 1, 'result' => ['tools' => []]],
            $this->jsonRpc->result(1, ['tools' => []])
        );
    }

    /**
     * @return void
     */
    public function testErrorEnvelope(): void
    {
        $this->assertSame(
            [
                'jsonrpc' => '2.0',
                'id' => null,
                'error' => ['code' => JsonRpc::PARSE_ERROR, 'message' => 'nope'],
            ],
            $this->jsonRpc->error(null, JsonRpc::PARSE_ERROR, 'nope')
        );
    }

    /**
     * The reserved codes are part of this class's contract; pinning them stops
     * a typo from silently changing the wire format.
     *
     * @return void
     */
    public function testReservedCodes(): void
    {
        $this->assertSame(-32700, JsonRpc::PARSE_ERROR);
        $this->assertSame(-32600, JsonRpc::INVALID_REQUEST);
        $this->assertSame(-32601, JsonRpc::METHOD_NOT_FOUND);
        $this->assertSame(-32602, JsonRpc::INVALID_PARAMS);
        $this->assertSame(-32603, JsonRpc::INTERNAL_ERROR);
    }
}
