<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Diagnostics;

use Magenx\AiMcp\Model\Auth\AclResources;
use Magenx\AiMcp\Model\Auth\Identity;
use Magenx\AiMcp\Model\Config;
use Magenx\AiMcp\Model\OAuth\Endpoints;
use Magenx\AiMcp\Model\OAuth\OAuthConfig;
use Magenx\AiMcp\Model\Protocol\Server;
use Magento\Authorization\Model\UserContextInterface;
use Magento\Integration\Api\IntegrationServiceInterface;
use Magento\User\Model\UserFactory;

/**
 * What a given admin or integration would get from the MCP endpoint, computed in-process.
 *
 * Runs the same code a real request does - the ACL lookup, the endpoint
 * grant, `initialize` and `tools/list` through the Server - against the live
 * store's roles and configuration, without a token and without HTTP. That is
 * the part a unit test cannot cover: whether Magento's actual role data grants
 * what the module expects. The admin-role bug that refused every OAuth
 * connection would have shown here as "0 tools".
 */
class ConnectionCheck
{
    private const INTEGRATION_STATUS_ACTIVE = 1;

    /**
     * @param AclResources $aclResources
     * @param Server $server
     * @param Config $config
     * @param OAuthConfig $oauthConfig
     * @param Endpoints $endpoints
     * @param UserFactory $userFactory
     * @param IntegrationServiceInterface $integrationService
     */
    public function __construct(
        private readonly AclResources $aclResources,
        private readonly Server $server,
        private readonly Config $config,
        private readonly OAuthConfig $oauthConfig,
        private readonly Endpoints $endpoints,
        private readonly UserFactory $userFactory,
        private readonly IntegrationServiceInterface $integrationService
    ) {
    }

    /**
     * Check an admin by username, as an OAuth connection or admin token would act.
     *
     * @param string $username
     * @return array{ok: bool, facts: array<string, string>, problems: string[]}
     */
    public function forAdmin(string $username): array
    {
        $user = $this->userFactory->create()->loadByUsername($username);
        if (!$user->getId()) {
            return $this->report([], [sprintf('No admin user named "%s".', $username)]);
        }

        $problems = [];
        if ((int) $user->getIsActive() !== 1) {
            $problems[] = 'The admin account is inactive, so every token it holds is refused.';
        }

        return $this->check(
            new Identity(
                UserContextInterface::USER_TYPE_ADMIN,
                (int) $user->getId(),
                'admin:' . $username,
                $this->aclResources->forUser(UserContextInterface::USER_TYPE_ADMIN, (int) $user->getId())
            ),
            ['caller' => 'admin "' . $username . '"'],
            $problems
        );
    }

    /**
     * Check an integration by name, as its access token would act.
     *
     * @param string $name
     * @return array{ok: bool, facts: array<string, string>, problems: string[]}
     */
    public function forIntegration(string $name): array
    {
        $integration = $this->integrationService->findByName($name);
        if (!$integration->getId()) {
            return $this->report([], [sprintf('No integration named "%s".', $name)]);
        }

        $problems = [];
        if ((int) $integration->getStatus() !== self::INTEGRATION_STATUS_ACTIVE) {
            $problems[] = 'The integration is not active, so its token is refused. Activate it in '
                . 'System > Extensions > Integrations.';
        }

        $id = (int) $integration->getId();

        return $this->check(
            new Identity(
                UserContextInterface::USER_TYPE_INTEGRATION,
                $id,
                $name,
                $this->aclResources->forUser(UserContextInterface::USER_TYPE_INTEGRATION, $id),
                true,
                $name
            ),
            ['caller' => 'integration "' . $name . '"'],
            $problems
        );
    }

    /**
     * @param Identity $identity
     * @param array<string, string> $facts
     * @param string[] $problems
     * @return array{ok: bool, facts: array<string, string>, problems: string[]}
     */
    private function check(Identity $identity, array $facts, array $problems): array
    {
        $facts['endpoint enabled'] = $this->config->isEnabled() ? 'yes' : 'no';
        if (!$this->config->isEnabled()) {
            $problems[] = 'The MCP endpoint is disabled (Stores > Configuration > Magenx > AI MCP Server).';
        }

        $facts['endpoint grant'] = $identity->isAllowed(Server::ENDPOINT_RESOURCE) ? 'yes' : 'no';
        if (!$identity->isAllowed(Server::ENDPOINT_RESOURCE)) {
            $problems[] = 'The role does not include "AI MCP Server" (Magenx_AiMcp::server); the endpoint '
                . 'answers 403 to every call.';
        }

        $initialize = $this->server->dispatch('initialize', [], 1, $identity)->getBody();
        $facts['initialize'] = isset($initialize['result']) ? 'ok' : 'refused';

        $list = $this->server->dispatch('tools/list', [], 2, $identity)->getBody();
        $tools = $list['result']['tools'] ?? [];
        // The server adds the confirm gate to every write tool it lists, and
        // to nothing else.
        $writes = array_filter(
            $tools,
            static fn (array $tool): bool => isset(((array) ($tool['inputSchema']['properties'] ?? []))['confirm'])
        );
        $facts['tools offered'] = (string) count($tools);
        $facts['write tools offered'] = (string) count($writes);
        if ($tools === [] && $identity->isAllowed(Server::ENDPOINT_RESOURCE)) {
            $problems[] = 'No tools are offered: the role grants none of the resources the tools require, or '
                . 'the toolset settings withhold them all.';
        }

        $facts['writes switch'] = $this->config->isWriteAllowed() ? 'on' : 'off';
        $facts['rate limit'] = $this->limit($this->config->getRateLimit())
            . ' calls, ' . $this->limit($this->config->getWriteRateLimit()) . ' writes per minute';
        $facts['oauth'] = $this->oauthConfig->isEnabled() ? 'on' : 'off';
        if ($this->oauthConfig->isEnabled()) {
            $facts['oauth issuer'] = $this->endpoints->getIssuer();
            $facts['oauth resource'] = $this->endpoints->getResource();
            $facts['oauth resource metadata'] = $this->endpoints->getProtectedResourceMetadataUrl();
        }

        return $this->report($facts, $problems);
    }

    /**
     * @param int $limit
     * @return string
     */
    private function limit(int $limit): string
    {
        return $limit > 0 ? (string) $limit : 'unlimited';
    }

    /**
     * @param array<string, string> $facts
     * @param string[] $problems
     * @return array{ok: bool, facts: array<string, string>, problems: string[]}
     */
    private function report(array $facts, array $problems): array
    {
        return ['ok' => $problems === [], 'facts' => $facts, 'problems' => $problems];
    }
}
