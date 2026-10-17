<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Block\Adminhtml\Oauth;

use Magenx\AiMcp\Model\Config;
use Magenx\AiMcp\Model\OAuth\AuthorizationService;
use Magenx\AiMcp\Model\OAuth\RedirectUriPolicy;
use Magenx\AiMcp\Model\OAuth\Scope;
use Magenx\AiMcp\Model\Protocol\Server;
use Magenx\AiMcp\Model\Tool\ToolCatalog;
use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Magento\Backend\Model\Auth\Session;

/**
 * What the consent template shows: the client, where its code goes, what it asked for, and what may be ticked.
 */
class Consent extends Template
{
    /** @var array<string, mixed>|null */
    private ?array $authorization = null;

    /**
     * @param Context $context
     * @param AuthorizationService $authorizationService
     * @param RedirectUriPolicy $redirectUriPolicy
     * @param Config $config
     * @param Session $authSession
     * @param ToolCatalog $catalog
     * @param array<string, mixed> $data
     */
    public function __construct(
        Context $context,
        private readonly AuthorizationService $authorizationService,
        private readonly RedirectUriPolicy $redirectUriPolicy,
        private readonly Config $config,
        private readonly Session $authSession,
        private readonly ToolCatalog $catalog,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    /**
     * @param array<string, mixed>|null $authorization
     * @return $this
     */
    public function setAuthorization(?array $authorization): self
    {
        $this->authorization = $authorization;

        return $this;
    }

    /**
     * Whether there is a live request to decide.
     *
     * @return bool
     */
    public function hasRequest(): bool
    {
        return $this->authorization !== null;
    }

    /**
     * @return string
     */
    public function getRequestId(): string
    {
        return (string) ($this->authorization['request_id'] ?? '');
    }

    /**
     * The name the client registered with. Self-declared, so shown beside the callback host.
     *
     * @return string
     */
    public function getClientName(): string
    {
        return (string) ($this->authorization['client_name'] ?? '');
    }

    /**
     * Where the authorization code will be delivered. This, not the name, is what identifies the client.
     *
     * @return string
     */
    public function getRedirectHost(): string
    {
        return $this->redirectUriPolicy->describe((string) ($this->authorization['redirect_uri'] ?? ''));
    }

    /**
     * Whether the client asked for write access.
     *
     * @return bool
     */
    public function isWriteRequested(): bool
    {
        return $this->authorization !== null
            && in_array(Scope::WRITE, $this->authorizationService->getRequestedScopes($this->authorization), true);
    }

    /**
     * The tool areas the admin may tick, code to label: every area, or those the client is capped to.
     *
     * @return array<string, string>
     */
    public function getOfferedDomains(): array
    {
        $labels = $this->catalog->getDomains();
        if ($this->authorization === null) {
            return [];
        }

        $offered = $this->authorizationService->getOfferedDomains($this->authorization);

        return $offered === null ? $labels : array_intersect_key($labels, array_flip($offered));
    }

    /**
     * Why the signed-in admin may not approve this request, or null when they may.
     *
     * @return string|null
     */
    public function getRefusal(): ?string
    {
        if ($this->authorization === null) {
            return null;
        }

        return $this->authorizationService->getRefusal(
            $this->authorization,
            (int) $this->authSession->getUser()?->getId()
        );
    }

    /**
     * Whether the store currently allows write tools at all.
     *
     * @return bool
     */
    public function isStoreWritable(): bool
    {
        return $this->config->isWriteAllowed();
    }

    /**
     * Whether the endpoint is switched on, so an approval would be usable.
     *
     * @return bool
     */
    public function isEndpointEnabled(): bool
    {
        return $this->config->isEnabled();
    }

    /**
     * Whether this admin's role can reach the MCP endpoint.
     *
     * @return bool
     */
    public function hasEndpointAccess(): bool
    {
        return $this->_authorization->isAllowed(Server::ENDPOINT_RESOURCE);
    }

    /**
     * @return string
     */
    public function getAdminUsername(): string
    {
        return (string) $this->authSession->getUser()?->getUserName();
    }

    /**
     * @return string
     */
    public function getDecideUrl(): string
    {
        return $this->getUrl('magenx_aimcp/oauth/decide');
    }
}
