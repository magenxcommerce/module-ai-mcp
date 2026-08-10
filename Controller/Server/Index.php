<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Controller\Server;

use Magenx\AiMcp\Model\Auth\Authenticator;
use Magenx\AiMcp\Model\Config;
use Magenx\AiMcp\Model\Protocol\JsonRpc;
use Magenx\AiMcp\Model\Protocol\Outcome;
use Magenx\AiMcp\Model\Protocol\Server;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Raw;
use Magento\Framework\Controller\Result\RawFactory;
use Magento\Framework\Exception\AuthenticationException;
use Magento\Framework\Serialize\Serializer\Json;

/**
 * POST /magenx-mcp/server — the MCP endpoint.
 *
 * Speaks MCP's streamable HTTP transport in its single-response form: one
 * JSON-RPC request per POST, answered with one JSON body (or a bare 202 for a
 * notification). No SSE stream is opened, which the transport explicitly
 * permits and which is all a request/response tool surface needs.
 *
 * Why a controller rather than a webapi route: MCP fixes the wire format, and
 * the webapi framework's typed (de)serialization cannot reproduce the JSON-RPC
 * envelope — in particular it cannot echo an `id` back with its original JSON
 * type, nor answer a notification with an empty 202. The authorization the
 * webapi framework would have provided is reimplemented faithfully in
 * {@see Authenticator}, against the same integration tokens and the same ACL.
 *
 * Batching is rejected: MCP dropped JSON-RPC batch support in 2025-06-18.
 */
class Index implements HttpPostActionInterface, HttpGetActionInterface, CsrfAwareActionInterface
{
    /**
     * @param HttpRequest $request
     * @param RawFactory $rawFactory
     * @param Json $serializer
     * @param Config $config
     * @param Authenticator $authenticator
     * @param Server $server
     * @param JsonRpc $jsonRpc
     */
    public function __construct(
        private readonly HttpRequest $request,
        private readonly RawFactory $rawFactory,
        private readonly Json $serializer,
        private readonly Config $config,
        private readonly Authenticator $authenticator,
        private readonly Server $server,
        private readonly JsonRpc $jsonRpc
    ) {
    }

    /**
     * @return Raw
     */
    public function execute(): Raw
    {
        // A disabled endpoint is indistinguishable from one that was never
        // installed: no version, no capabilities, nothing to probe.
        if (!$this->config->isEnabled()) {
            return $this->emit(new Outcome(404, null));
        }

        if ($this->request->getMethod() !== 'POST') {
            return $this->emit(new Outcome(405, null));
        }

        $decoded = $this->decodeBody((string) $this->request->getContent());
        if ($decoded === null) {
            return $this->emit(
                new Outcome(200, $this->jsonRpc->error(null, JsonRpc::PARSE_ERROR, 'Request body is not valid JSON.'))
            );
        }
        if (!is_array($decoded) || array_is_list($decoded)) {
            return $this->emit(new Outcome(200, $this->jsonRpc->error(
                null,
                JsonRpc::INVALID_REQUEST,
                'Send one JSON-RPC object; batched requests are not supported.'
            )));
        }

        $id = $this->jsonRpc->normalizeId($decoded['id'] ?? null);

        try {
            $identity = $this->authenticator->authenticate($this->request);
        } catch (AuthenticationException $e) {
            // Transport-level refusal keeps its HTTP status: the call never
            // reached the RPC layer.
            return $this->emit(
                new Outcome(401, $this->jsonRpc->error($id, JsonRpc::INVALID_REQUEST, $e->getMessage())),
                ['WWW-Authenticate' => 'Bearer realm="magenx-mcp"']
            );
        }

        $method = $decoded['method'] ?? null;
        if (!is_string($method) || $method === '') {
            return $this->emit(
                new Outcome(200, $this->jsonRpc->error($id, JsonRpc::INVALID_REQUEST, 'Missing "method".'))
            );
        }

        $params = $decoded['params'] ?? [];

        return $this->emit($this->server->dispatch($method, is_array($params) ? $params : [], $id, $identity));
    }

    /**
     * Decode the request body, returning null when it is not valid JSON.
     *
     * @param string $body
     * @return mixed
     */
    private function decodeBody(string $body): mixed
    {
        if (trim($body) === '') {
            return null;
        }

        try {
            return $this->serializer->unserialize($body);
        } catch (\InvalidArgumentException) {
            return null;
        }
    }

    /**
     * Put an outcome on the wire.
     *
     * @param Outcome $outcome
     * @param array<string, string> $headers
     * @return Raw
     */
    private function emit(Outcome $outcome, array $headers = []): Raw
    {
        $result = $this->rawFactory->create();
        $result->setHttpResponseCode($outcome->getHttpStatus());
        $result->setHeader('Cache-Control', 'no-store', true);
        $result->setHeader('MCP-Protocol-Version', Server::PROTOCOL_VERSION, true);
        foreach ($headers as $name => $value) {
            $result->setHeader($name, $value, true);
        }

        $body = $outcome->getBody();
        if ($body === null) {
            return $result->setContents('');
        }

        $result->setHeader('Content-Type', 'application/json', true);

        return $result->setContents($this->serializer->serialize($body));
    }

    /**
     * @param RequestInterface $request
     * @return InvalidRequestException|null
     */
    public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException
    {
        return null;
    }

    /**
     * The endpoint is authenticated by bearer token, not by a session, so there
     * is no form key to validate and no session-riding risk to protect against.
     *
     * @param RequestInterface $request
     * @return bool|null
     */
    public function validateForCsrf(RequestInterface $request): ?bool
    {
        return true;
    }
}
