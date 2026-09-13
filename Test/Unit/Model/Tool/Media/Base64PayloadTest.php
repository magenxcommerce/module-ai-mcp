<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Test\Unit\Model\Tool\Media;

use Magenx\AiMcp\Model\Tool\Media\Base64Payload;
use Magento\Framework\Exception\LocalizedException;
use PHPUnit\Framework\TestCase;

/**
 * The file-payload check shared by every tool that accepts real bytes.
 *
 * add_product_media covers the image half; what is pinned here is the part the
 * downloadable tools rely on, where the payload is a PDF or an archive and
 * there is no image header to fall back on.
 *
 * @see Base64Payload
 */
class Base64PayloadTest extends TestCase
{
    private Base64Payload $payload;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        $this->payload = new Base64Payload();
    }

    /**
     * @return void
     */
    public function testAnyBytesAreAcceptedWhenNoImageIsRequired(): void
    {
        $bytes = '%PDF-1.7 not really a pdf, but bytes';

        $this->assertSame(
            $bytes,
            $this->payload->decode(base64_encode($bytes), 'link_file_content')
        );
    }

    /**
     * @return void
     */
    public function testTruncatedBase64IsNamedAsSuch(): void
    {
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage(
            'The "link_file_content" argument is not valid base64. A truncated or re-wrapped '
            . 'payload is the usual cause.'
        );
        $this->payload->decode('!!!! not base64 !!!!', 'link_file_content');
    }

    /**
     * @return void
     */
    public function testAnEmptyPayloadIsRefused(): void
    {
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('The "sample_file_content" argument decoded to nothing.');
        $this->payload->decode('', 'sample_file_content');
    }

    /**
     * The cap is on the decoded size, not the base64, because that is what
     * reaches disk.
     *
     * @return void
     */
    public function testThePayloadIsCappedAtItsDecodedSize(): void
    {
        $encoded = base64_encode(str_repeat('x', 33));

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('The payload is 33 bytes decoded, over the 32 byte limit.');
        $this->payload->decode($encoded, 'link_file_content', 32);
    }

    /**
     * @return void
     */
    public function testBytesThatAreNotAnImageAreRefusedWhenOneIsRequired(): void
    {
        $decoded = $this->payload->decode(base64_encode('plain text'), 'base64_encoded_data');

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('does not decode to an image Magento can read');
        $this->payload->assertImageMatches($decoded, 'base64_encoded_data', 'image/png');
    }
}
