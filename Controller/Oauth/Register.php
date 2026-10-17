<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Controller\Oauth;

use Magenx\AiMcp\Model\Config;
use Magenx\AiMcp\Model\OAuth\ClientRegistry;
use Magenx\AiMcp\Model\OAuth\JsonResponder;
use Magenx\AiMcp\Model\OAuth\OAuthConfig;
use Magenx\AiMcp\Model\OAuth\OAuthException;
use Magenx\AiMcp\Model\OAuth\Throttle;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\Controller\Result\Raw;
use Magento\Framework\HTTP\PhpEnvironment\RemoteAddress;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Framework\Stdlib\DateTime\DateTime as CoreDate;

/**
 * POST /magenx-mcp/oauth/register — RFC 7591 dynamic client registration.
 *
 * Answers 404 unless the operator opened registration: by default only a
 * client an administrator created can connect.
 */
class Register extends AbstractEndpoint implements HttpPostActionInterface
{
    /** A registration is a few hundred bytes; anything far larger is not one. */
    private const MAX_BODY_BYTES = 16384;

    /** A client registers once per connector; twenty an hour from one address is already generous. */
    private const LIMIT_PER_HOUR = 20;

    /**
     * @param HttpRequest $request
     * @param JsonResponder $responder
     * @param Json $serializer
     * @param Config $config
     * @param OAuthConfig $oauthConfig
     * @param ClientRegistry $clientRegistry
     * @param RemoteAddress $remoteAddress
     * @param Throttle $throttle
     * @param CoreDate $date
     */
    public function __construct(
        HttpRequest $request,
        JsonResponder $responder,
        Json $serializer,
        Config $config,
        OAuthConfig $oauthConfig,
        private readonly ClientRegistry $clientRegistry,
        private readonly RemoteAddress $remoteAddress,
        private readonly Throttle $throttle,
        private readonly CoreDate $date
    ) {
        parent::__construct($request, $responder, $serializer, $config, $oauthConfig);
    }

    /**
     * @return Raw
     */
    public function execute(): Raw
    {
        if (!$this->isActive() || !$this->oauthConfig->isDynamicRegistrationAllowed()) {
            return $this->responder->empty(404);
        }

        $remote = (string) $this->remoteAddress->getRemoteAddress();
        $now = (int) $this->date->gmtTimestamp();
        if (!$this->throttle->allow('register', $remote, self::LIMIT_PER_HOUR, 3600, $now)) {
            return $this->responder->respond(
                ['error' => 'invalid_request', 'error_description' => 'Too many registrations; try again later.'],
                429,
                ['Retry-After' => '3600']
            );
        }

        $body = (string) $this->request->getContent();
        if (strlen($body) > self::MAX_BODY_BYTES) {
            return $this->responder->respond(
                ['error' => 'invalid_client_metadata', 'error_description' => 'Request body too large.'],
                400
            );
        }

        try {
            $metadata = $this->serializer->unserialize($body);
        } catch (\InvalidArgumentException) {
            $metadata = null;
        }
        if (!is_array($metadata)) {
            return $this->responder->respond(
                [
                    'error' => 'invalid_client_metadata',
                    'error_description' => 'Send the client metadata as a JSON object.',
                ],
                400
            );
        }

        try {
            return $this->responder->respond(
                $this->clientRegistry->register($metadata, $remote),
                201
            );
        } catch (OAuthException $e) {
            return $this->responder->respond($e->toArray(), $e->getHttpStatus());
        }
    }
}
