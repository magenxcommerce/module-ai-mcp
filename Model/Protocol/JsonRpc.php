<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Protocol;

/**
 * JSON-RPC 2.0 envelope construction and the reserved error codes.
 *
 * Kept free of Magento dependencies so it stays trivially readable: this is the
 * wire format, nothing else.
 */
class JsonRpc
{
    public const VERSION = '2.0';

    public const PARSE_ERROR = -32700;
    public const INVALID_REQUEST = -32600;
    public const METHOD_NOT_FOUND = -32601;
    public const INVALID_PARAMS = -32602;
    public const INTERNAL_ERROR = -32603;

    /**
     * Build a success envelope.
     *
     * @param string|int|null $id
     * @param array<string, mixed>|object $result
     * @return array<string, mixed>
     */
    public function result(string|int|null $id, array|object $result): array
    {
        return ['jsonrpc' => self::VERSION, 'id' => $id, 'result' => $result];
    }

    /**
     * Build an error envelope.
     *
     * A JSON-RPC error still rides HTTP 200: the transport succeeded, the call
     * did not. HTTP status codes are reserved for transport and authentication
     * failures, which the controller emits directly.
     *
     * @param string|int|null $id
     * @param int $code
     * @param string $message
     * @return array<string, mixed>
     */
    public function error(string|int|null $id, int $code, string $message): array
    {
        return [
            'jsonrpc' => self::VERSION,
            'id' => $id,
            'error' => ['code' => $code, 'message' => $message],
        ];
    }

    /**
     * Normalise an incoming `id`, which JSON-RPC allows to be a string, a
     * number or null. Anything else is echoed back as null.
     *
     * @param mixed $raw
     * @return string|int|null
     */
    public function normalizeId(mixed $raw): string|int|null
    {
        if (is_string($raw) || is_int($raw)) {
            return $raw;
        }

        return null;
    }
}
