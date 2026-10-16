<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\OAuth;

use Magento\Framework\Controller\Result\Raw;
use Magento\Framework\Controller\Result\RawFactory;
use Magento\Framework\Serialize\Serializer\Json;

/**
 * Puts an OAuth JSON body on the wire with the headers RFC 6749 §5.1 requires.
 */
class JsonResponder
{
    /**
     * @param RawFactory $rawFactory
     * @param Json $serializer
     */
    public function __construct(
        private readonly RawFactory $rawFactory,
        private readonly Json $serializer
    ) {
    }

    /**
     * @param array<string, mixed> $body
     * @param int $status
     * @param array<string, string> $headers
     * @return Raw
     */
    public function respond(array $body, int $status = 200, array $headers = []): Raw
    {
        $result = $this->rawFactory->create();
        $result->setHttpResponseCode($status);
        $result->setHeader('Content-Type', 'application/json', true);
        $result->setHeader('Cache-Control', 'no-store', true);
        $result->setHeader('Pragma', 'no-cache', true);
        foreach ($headers as $name => $value) {
            $result->setHeader($name, $value, true);
        }

        return $result->setContents($this->serializer->serialize($body));
    }

    /**
     * A bare status with no body.
     *
     * @param int $status
     * @return Raw
     */
    public function empty(int $status): Raw
    {
        $result = $this->rawFactory->create();
        $result->setHttpResponseCode($status);
        $result->setHeader('Cache-Control', 'no-store', true);

        return $result->setContents('');
    }
}
