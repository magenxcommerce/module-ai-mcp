<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\Downloadable;

use Magenx\AiMcp\Model\Tool\Media\Base64Payload;
use Magento\Downloadable\Api\Data\File\ContentInterface;
use Magento\Downloadable\Api\Data\File\ContentInterfaceFactory;
use Magento\Framework\Exception\LocalizedException;

/**
 * The "url or file, not both" half of saving a downloadable link or sample.
 *
 * Magento stores a type alongside either a url or an uploaded file, and reads
 * whichever the type names. A link whose type says "file" with only a url
 * supplied saves cleanly and then fails at download time, for the customer,
 * after they have paid — so the pair is checked together here, for the link,
 * its sample, and a product-level sample alike.
 */
class DownloadableFileArguments
{
    public const TYPE_URL = 'url';
    public const TYPE_FILE = 'file';

    /**
     * @param ContentInterfaceFactory $contentFactory
     * @param Base64Payload $payload
     */
    public function __construct(
        private readonly ContentInterfaceFactory $contentFactory,
        private readonly Base64Payload $payload
    ) {
    }

    /**
     * Schema for the three arguments that make up one file, named by prefix so
     * a link can carry its own sample alongside itself.
     *
     * @param string $prefix "link" or "sample".
     * @param string $what What this file is, for the descriptions.
     * @return array<string, mixed>
     */
    public function schema(string $prefix, string $what): array
    {
        return [
            $prefix . '_type' => [
                'type' => 'string',
                'enum' => [self::TYPE_URL, self::TYPE_FILE],
                'description' => sprintf(
                    'Where %s comes from: "url" for a link to somewhere else, "file" for bytes '
                        . 'stored by Magento.',
                    $what
                ),
            ],
            $prefix . '_url' => [
                'type' => 'string',
                'description' => sprintf('The address, when %s_type is "url".', $prefix),
            ],
            $prefix . '_file_content' => [
                'type' => 'object',
                'properties' => [
                    'base64_encoded_data' => [
                        'type' => 'string',
                        'description' => 'The file, base64 encoded. Not a URL and not a path.',
                    ],
                    'name' => [
                        'type' => 'string',
                        'description' => 'File name to store it under, e.g. "manual.pdf".',
                    ],
                ],
                'required' => ['base64_encoded_data', 'name'],
                'additionalProperties' => false,
                'description' => sprintf(
                    'The bytes, when %s_type is "file". At most 4 MB decoded.',
                    $prefix
                ),
            ],
        ];
    }

    /**
     * Validate the type against what was supplied with it.
     *
     * @param array<string, mixed> $arguments
     * @param string $prefix
     * @param string|null $currentType The stored type, when updating.
     * @return string|null The type to save, or null when nothing was supplied.
     * @throws LocalizedException
     */
    public function resolveType(array $arguments, string $prefix, ?string $currentType = null): ?string
    {
        $type = $arguments[$prefix . '_type'] ?? null;
        if ($type === null) {
            return $currentType;
        }

        if ($type !== self::TYPE_URL && $type !== self::TYPE_FILE) {
            throw new LocalizedException(__(
                'The "%1" argument must be "%2" or "%3".',
                $prefix . '_type',
                self::TYPE_URL,
                self::TYPE_FILE
            ));
        }

        return $type;
    }

    /**
     * The url for a "url" type, refusing the combination that saves and then
     * fails at download time.
     *
     * @param array<string, mixed> $arguments
     * @param string $prefix
     * @param string $type
     * @param bool $isNew Whether nothing is stored yet, so something must be supplied.
     * @return string|null
     * @throws LocalizedException
     */
    public function url(array $arguments, string $prefix, string $type, bool $isNew): ?string
    {
        $url = $arguments[$prefix . '_url'] ?? null;
        if ($url !== null && (!is_string($url) || trim($url) === '')) {
            throw new LocalizedException(__('The "%1" argument must be a url.', $prefix . '_url'));
        }

        if ($type !== self::TYPE_URL) {
            return null;
        }

        if ($url === null && $isNew) {
            throw new LocalizedException(__(
                'The "%1" argument is required when %2 is "url".',
                $prefix . '_url',
                $prefix . '_type'
            ));
        }

        return $url === null ? null : trim((string) $url);
    }

    /**
     * The uploaded bytes for a "file" type, checked before Magento is asked.
     *
     * @param array<string, mixed> $arguments
     * @param string $prefix
     * @param string $type
     * @param bool $isNew Whether nothing is stored yet, so something must be supplied.
     * @return ContentInterface|null
     * @throws LocalizedException
     */
    public function content(array $arguments, string $prefix, string $type, bool $isNew): ?ContentInterface
    {
        $key = $prefix . '_file_content';
        $supplied = $arguments[$key] ?? null;

        if ($type !== self::TYPE_FILE) {
            return null;
        }

        if ($supplied === null) {
            if ($isNew) {
                throw new LocalizedException(__(
                    'The "%1" argument is required when %2 is "file".',
                    $key,
                    $prefix . '_type'
                ));
            }

            return null;
        }

        if (!is_array($supplied)) {
            throw new LocalizedException(__('The "%1" argument must be an object.', $key));
        }

        $encoded = $supplied['base64_encoded_data'] ?? null;
        $name = $supplied['name'] ?? null;
        if (!is_string($encoded) || $encoded === '' || !is_string($name) || trim($name) === '') {
            throw new LocalizedException(__(
                'The "%1" argument needs both base64_encoded_data and name.',
                $key
            ));
        }

        $this->payload->decode($encoded, $key . '.base64_encoded_data');

        $content = $this->contentFactory->create();
        $content->setFileData($encoded);
        $content->setName(trim($name));

        return $content;
    }
}
