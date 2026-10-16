<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Controller\Oauth;

use Magenx\AiMcp\Model\Config;
use Magenx\AiMcp\Model\OAuth\AuthorizationService;
use Magenx\AiMcp\Model\OAuth\Endpoints;
use Magenx\AiMcp\Model\OAuth\JsonResponder;
use Magenx\AiMcp\Model\OAuth\OAuthConfig;
use Magenx\AiMcp\Model\OAuth\OAuthException;
use Magenx\AiMcp\Model\OAuth\PendingConsent;
use Magenx\AiMcp\Model\OAuth\RedirectableOAuthException;
use Magenx\AiMcp\Model\OAuth\Throttle;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\Controller\Result\Raw;
use Magento\Framework\Controller\Result\RawFactory;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\Escaper;
use Magento\Framework\HTTP\PhpEnvironment\RemoteAddress;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Framework\Stdlib\DateTime\DateTime as CoreDate;

/**
 * GET /magenx-mcp/oauth/authorize — where the AI service sends the user's browser.
 *
 * Grants nothing. It checks the request, parks it, and sends the browser to
 * the admin consent page, where a signed-in admin — past the admin login and
 * its two-factor check — decides. Claude's servers never reach the admin:
 * only the person's own browser does, so the admin can stay behind whatever
 * IP restriction it already has.
 */
class Authorize extends AbstractEndpoint implements HttpGetActionInterface
{
    /** Each attempt parks a row; a person connecting does this a handful of times at most. */
    private const LIMIT_PER_TEN_MINUTES = 30;

    /**
     * @param HttpRequest $request
     * @param JsonResponder $responder
     * @param Json $serializer
     * @param Config $config
     * @param OAuthConfig $oauthConfig
     * @param AuthorizationService $authorizationService
     * @param Endpoints $endpoints
     * @param PendingConsent $pendingConsent
     * @param RedirectFactory $redirectFactory
     * @param RawFactory $rawFactory
     * @param Escaper $escaper
     * @param Throttle $throttle
     * @param RemoteAddress $remoteAddress
     * @param CoreDate $date
     */
    public function __construct(
        HttpRequest $request,
        JsonResponder $responder,
        Json $serializer,
        Config $config,
        OAuthConfig $oauthConfig,
        private readonly AuthorizationService $authorizationService,
        private readonly Endpoints $endpoints,
        private readonly PendingConsent $pendingConsent,
        private readonly RedirectFactory $redirectFactory,
        private readonly RawFactory $rawFactory,
        private readonly Escaper $escaper,
        private readonly Throttle $throttle,
        private readonly RemoteAddress $remoteAddress,
        private readonly CoreDate $date
    ) {
        parent::__construct($request, $responder, $serializer, $config, $oauthConfig);
    }

    /**
     * @return Raw|Redirect
     */
    public function execute(): Raw|Redirect
    {
        if (!$this->isActive()) {
            return $this->responder->empty(404);
        }

        if (!$this->throttle->allow(
            'authorize',
            (string) $this->remoteAddress->getRemoteAddress(),
            self::LIMIT_PER_TEN_MINUTES,
            600,
            (int) $this->date->gmtTimestamp()
        )) {
            return $this->errorPage(
                'Too many connection attempts from this address. Wait a few minutes and try again.',
                429
            );
        }

        try {
            $requestId = $this->authorizationService->createRequest($this->request->getQuery()->toArray());
        } catch (RedirectableOAuthException $e) {
            return $this->redirectFactory->create()->setUrl($e->getRedirectUrl());
        } catch (OAuthException $e) {
            return $this->errorPage($e->getMessage());
        }

        $this->pendingConsent->remember($requestId);

        return $this->redirectFactory->create()->setUrl($this->endpoints->getConsentUrl($requestId));
    }

    /**
     * A plain page for errors that must not be redirected anywhere.
     *
     * @param string $message
     * @param int $status
     * @return Raw
     */
    private function errorPage(string $message, int $status = 400): Raw
    {
        $result = $this->rawFactory->create();
        $result->setHttpResponseCode($status);
        $result->setHeader('Content-Type', 'text/html; charset=utf-8', true);
        $result->setHeader('Cache-Control', 'no-store', true);

        return $result->setContents(
            '<!doctype html><meta charset="utf-8"><title>Connection request refused</title>'
            . '<h1>Connection request refused</h1><p>' . $this->escaper->escapeHtml($message) . '</p>'
        );
    }
}
