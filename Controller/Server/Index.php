<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Controller\Server;

use Laminas\Http\Header\HeaderInterface;
use Magenx\AiMcp\Model\Auth\AccessDeniedException;
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

        if (!$this->isOriginAllowed()) {
            return $this->emit(new Outcome(403, $this->jsonRpc->error(
                null,
                JsonRpc::INVALID_REQUEST,
                'This origin is not permitted.'
            )));
        }

        $decoded = $this->decodeBody((string) $this->request->getContent());
        if ($decoded === null) {
            return $this->emit(
                new Outcome(200, $this->jsonRpc->error(null, JsonRpc::PARSE_ERROR, 'Request body is not valid JSON.'))
            );
        }
        // `{}` decodes to an empty PHP array, and array_is_list([]) is true, so
        // an empty JSON *object* must be excluded here or it is reported as a
        // batch. It falls through to the "Missing method" error below, which is
        // what it actually is.
        if (!is_array($decoded) || ($decoded !== [] && array_is_list($decoded))) {
            return $this->emit(new Outcome(200, $this->jsonRpc->error(
                null,
                JsonRpc::INVALID_REQUEST,
                'Send one JSON-RPC object; batched requests are not supported.'
            )));
        }

        $id = $this->jsonRpc->normalizeId($decoded['id'] ?? null);

        try {
            $identity = $this->authenticator->authenticate($this->request);
        } catch (AccessDeniedException $e) {
            // Not a credential problem — a better token would not help — so no
            // 401 and no WWW-Authenticate to invite a retry.
            return $this->emit(
                new Outcome(403, $this->jsonRpc->error($id, JsonRpc::INVALID_REQUEST, $e->getMessage()))
            );
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
     * Check the Origin header, which the MCP transport requires servers to
     * validate against DNS-rebinding attacks.
     *
     * Only *browser* callers send an Origin. Every documented client of this
     * endpoint — curl, Claude Code, an MCP client library — sends none, and is
     * waved through unchanged; the bearer token is their boundary. A request
     * that does carry one is answered only if an administrator listed that
     * origin, so the default empty setting refuses every browser rather than
     * accepting every browser.
     *
     * @return bool
     */
    private function isOriginAllowed(): bool
    {
        $raw = $this->request->getHeader('Origin');
        $origin = trim($raw instanceof HeaderInterface ? $raw->getFieldValue() : (string) $raw);
        // `1` is what the string cast yields when Magento reports the header as
        // absent; an absent header is a non-browser caller. The literal "null"
        // is *not* absent — a sandboxed iframe sends it — so it falls through to
        // the allowlist and is refused by default.
        if ($origin === '' || $origin === '1') {
            return true;
        }

        foreach ($this->config->getAllowedOrigins() as $candidate) {
            if (strcasecmp($origin, $candidate) === 0) {
                return true;
            }
        }

        return false;
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
