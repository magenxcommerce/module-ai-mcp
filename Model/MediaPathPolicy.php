<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model;

use Magento\Framework\Exception\LocalizedException;

/**
 * Decides where a media-gallery upload is allowed to land.
 *
 * Everything under `pub/media` is served straight off the web server, so a file
 * written there is a public URL the moment it exists. Two things follow, and
 * neither is configurable:
 *
 *  1. The destination is confined to the CMS media gallery root. A path that
 *     climbs out of it with `..`, starts at `/`, or hides a separator in an
 *     encoded form is refused rather than normalised into something plausible.
 *  2. Only image extensions are accepted, and only ones matching the mime type
 *     the bytes actually are. A `.phtml` or `.php` written into a served
 *     directory is not a media file with the wrong name, it is code.
 *
 * This is {@see ConfigPathPolicy}'s counterpart for the filesystem: a hard-coded
 * policy, because the failure it prevents is not a trade-off a store should be
 * able to opt into through a text field.
 */
class MediaPathPolicy
{
    /** Magento's own CMS media gallery root, relative to the media directory. */
    public const ROOT = 'wysiwyg';

    /** Accepted image types, and the extension each must be stored under. */
    private const EXTENSIONS = [
        'image/jpeg' => ['jpg', 'jpeg'],
        'image/png' => ['png'],
        'image/gif' => ['gif'],
        'image/webp' => ['webp'],
    ];

    /** One path segment: letters, digits and the three separators a file name uses. */
    private const SEGMENT = '/^[A-Za-z0-9][A-Za-z0-9._-]*$/';

    /**
     * The mime types an upload may declare.
     *
     * @return array<int, string>
     */
    public function mimeTypes(): array
    {
        return array_keys(self::EXTENSIONS);
    }

    /**
     * Build the media-relative path this upload may be written to.
     *
     * @param string $fileName
     * @param string|null $directory Relative to the gallery root, e.g. "banners/2026".
     * @param string $mimeType
     * @return string Path relative to pub/media, e.g. "wysiwyg/banners/2026/hero.jpg".
     * @throws LocalizedException
     */
    public function resolve(string $fileName, ?string $directory, string $mimeType): string
    {
        $segments = [self::ROOT];

        foreach ($this->split($directory ?? '', 'directory') as $segment) {
            $segments[] = $segment;
        }

        $name = $this->split($fileName, 'file_name');
        if (count($name) !== 1) {
            throw new LocalizedException(__(
                'The "file_name" argument must be a name, not a path — put folders in "directory".'
            ));
        }

        $this->assertExtensionMatches($name[0], $mimeType);
        $segments[] = $name[0];

        return implode('/', $segments);
    }

    /**
     * Split a caller-supplied path into segments, refusing anything that could
     * reach outside the gallery root.
     *
     * @param string $value
     * @param string $argumentName
     * @return array<int, string>
     * @throws LocalizedException
     */
    private function split(string $value, string $argumentName): array
    {
        $value = trim($value);
        if ($value === '') {
            return [];
        }

        // A null byte truncates the path at the filesystem layer, so a name that
        // passed the extension check can still be written as something else.
        if (str_contains($value, "\0") || str_contains($value, '\\')) {
            throw new LocalizedException(
                __('The "%1" argument contains a character that is not allowed in a path.', $argumentName)
            );
        }

        $segments = [];
        foreach (explode('/', $value) as $segment) {
            if ($segment === '') {
                continue;
            }

            if (preg_match(self::SEGMENT, $segment) !== 1) {
                throw new LocalizedException(__(
                    'The "%1" argument may only use letters, digits, dots, dashes and underscores, '
                    . 'in folders separated by "/". "%2" is not allowed.',
                    $argumentName,
                    $segment
                ));
            }

            $segments[] = $segment;
        }

        return $segments;
    }

    /**
     * @param string $fileName
     * @param string $mimeType
     * @return void
     * @throws LocalizedException
     */
    private function assertExtensionMatches(string $fileName, string $mimeType): void
    {
        $allowed = self::EXTENSIONS[$mimeType] ?? null;
        if ($allowed === null) {
            throw new LocalizedException(__(
                'The "mime_type" argument must be one of: %1.',
                implode(', ', $this->mimeTypes())
            ));
        }

        $position = strrpos($fileName, '.');
        $extension = $position === false ? '' : strtolower(substr($fileName, $position + 1));

        if (!in_array($extension, $allowed, true)) {
            throw new LocalizedException(__(
                'A %1 file has to be named .%2 — "%3" would be served as something else.',
                $mimeType,
                implode(' or .', $allowed),
                $fileName
            ));
        }
    }
}
