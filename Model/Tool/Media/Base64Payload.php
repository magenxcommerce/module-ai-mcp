<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\Media;

use Magento\Framework\Exception\LocalizedException;

/**
 * The file-payload check in front of every tool that accepts real bytes.
 *
 * MCP carries a file as base64 in the JSON-RPC body, so a tool that stores one
 * is handed a string that may be truncated, re-wrapped, enormous, or simply not
 * what it says it is. Magento checks some of this itself, but only after
 * attempting the write and only as a generic save failure — which reads like a
 * storage problem rather than a bad argument. Refusing here names the actual
 * problem while nothing has been written.
 */
class Base64Payload
{
    /** Decoded size cap. Base64 in a JSON-RPC body is already costly at this size. */
    public const MAX_BYTES = 4194304;

    /**
     * Decode a base64 argument, or fail naming why it could not be used.
     *
     * @param string $encoded
     * @param string $argumentName The schema argument it came from, for the message.
     * @param int $maxBytes Decoded size limit.
     * @return string The decoded bytes.
     * @throws LocalizedException
     */
    public function decode(string $encoded, string $argumentName, int $maxBytes = self::MAX_BYTES): string
    {
        // phpcs:ignore Magento2.Functions.DiscouragedFunction.Discouraged -- the MCP payload is base64 by protocol, and strict mode is what turns a truncated one into a named error.
        $decoded = base64_decode($encoded, true);
        if ($decoded === false) {
            throw new LocalizedException(__(
                'The "%1" argument is not valid base64. A truncated or re-wrapped payload is the '
                . 'usual cause.',
                $argumentName
            ));
        }

        if ($decoded === '') {
            throw new LocalizedException(__('The "%1" argument decoded to nothing.', $argumentName));
        }

        if (strlen($decoded) > $maxBytes) {
            throw new LocalizedException(__(
                'The payload is %1 bytes decoded, over the %2 byte limit.',
                strlen($decoded),
                $maxBytes
            ));
        }

        return $decoded;
    }

    /**
     * Additionally require the bytes to be an image of the type they claim.
     *
     * The commonest real mistake is the right picture with the wrong declared
     * type, which Magento accepts into storage and then serves as something no
     * browser will render.
     *
     * @param string $decoded
     * @param string $argumentName
     * @param string $mimeType
     * @return void
     * @throws LocalizedException
     */
    public function assertImageMatches(string $decoded, string $argumentName, string $mimeType): void
    {
        $this->imageInfo($decoded, $argumentName, $mimeType);
    }

    /**
     * The same check, reporting the dimensions the caller needs to record.
     *
     * @param string $decoded
     * @param string $argumentName
     * @param string $mimeType
     * @return array{mime: string, width: int, height: int}
     * @throws LocalizedException
     */
    public function imageInfo(string $decoded, string $argumentName, string $mimeType): array
    {
        $info = $this->readImageHeader($decoded);
        if ($info === false) {
            throw new LocalizedException(__(
                'The "%1" argument does not decode to an image Magento can read.',
                $argumentName
            ));
        }

        $actual = (string) ($info['mime'] ?? '');
        if ($actual !== $mimeType) {
            throw new LocalizedException(__(
                'The bytes are %1 but mime_type says %2. Pass the type that matches the image.',
                $actual,
                $mimeType
            ));
        }

        return [
            'mime' => $actual,
            'width' => (int) ($info[0] ?? 0),
            'height' => (int) ($info[1] ?? 0),
        ];
    }

    /**
     * Read an image header without letting the failure reach the caller as a warning.
     *
     * `getimagesizefromstring()` raises a PHP warning on bytes it cannot parse,
     * which is the ordinary case here — an agent passing something that is not
     * an image. The handler swallows only that call's diagnostics; the return
     * value is what this method reports on, and it is restored either way.
     *
     * @param string $bytes
     * @return array<int|string, mixed>|false
     */
    private function readImageHeader(string $bytes): array|false
    {
        set_error_handler(static fn (): bool => true);

        try {
            return getimagesizefromstring($bytes);
        } finally {
            restore_error_handler();
        }
    }
}
