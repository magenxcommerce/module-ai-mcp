<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\Security;

use Magenx\AiMcp\Model\Tool\AbstractTool;
use Magento\Framework\Exception\LocalizedException;

/**
 * Whether the services this store runs on fit the release it would upgrade to.
 *
 * The candidates are the releases an upgrade is usually weighed between: the
 * one the advisory API recommends for this release's security, the newest
 * patch on the same line, and the newest release overall — or exactly one,
 * when the caller names it. Each is compared, component by component, with
 * what this store actually measures: PHP, the database, the search engine,
 * Redis and RabbitMQ.
 *
 * Adobe's requirements are the versions a release was tested with, not a
 * range, so most mismatches are reported as warnings to look at. PHP is the
 * exception and is reported as a blocker: the release's own composer
 * constraint refuses an unlisted PHP, so the upgrade cannot even be installed.
 *
 * The installed release is evaluated the same way, because a store already
 * running outside its own release's tested versions is worth knowing about
 * before anything is upgraded.
 */
class UpgradeCompatibility extends AbstractTool
{
    /** Components that are one role filled by alternative products. */
    private const FAMILIES = [
        'database' => ['mariadb', 'mysql'],
        'search' => ['opensearch', 'elasticsearch'],
        'cache' => ['redis', 'valkey'],
        'queue' => ['rabbitmq', 'activemq'],
    ];

    /**
     * @param InstalledRelease $installedRelease
     * @param AdvisoryClient $advisories
     * @param EnvironmentVersions $environment
     * @param VersionRequirements $requirements
     */
    public function __construct(
        private readonly InstalledRelease $installedRelease,
        private readonly AdvisoryClient $advisories,
        private readonly EnvironmentVersions $environment,
        private readonly VersionRequirements $requirements
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'upgrade_compatibility';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Check whether this store can move to a newer Magento release: compares its PHP, database, '
            . 'search, Redis and RabbitMQ versions with the system requirements (from magento.watch) of the '
            . 'recommended release, the newest patch on its line and the newest release, or of target_version. '
            . 'PHP outside the list is a blocker; other mismatches are warnings.';
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'target_version' => [
                    'type' => 'string',
                    'description' => 'Check only this release, e.g. "2.4.9". Omit to check the recommended '
                        . 'release, the newest patch on this line and the newest release.',
                ],
                'distribution' => [
                    'type' => 'string',
                    'enum' => AdvisoryClient::DISTRIBUTIONS,
                    'description' => 'Override the distribution detected from the edition.',
                ],
            ],
            'additionalProperties' => false,
        ];
    }

    /**
     * @inheritDoc
     */
    public function getAclResource(): string
    {
        return 'Magenx_AiMcp::ops';
    }

    /**
     * See {@see SecurityStatus::getAnnotations()}: this asks a service outside
     * the store.
     *
     * @inheritDoc
     */
    public function getAnnotations(): array
    {
        return ['openWorldHint' => true] + parent::getAnnotations();
    }

    /**
     * @inheritDoc
     */
    public function execute(array $arguments): array
    {
        $release = $this->installedRelease->get();
        $distribution = $this->optionalString($arguments, 'distribution') ?? $release['distribution'];
        $target = $this->optionalString($arguments, 'target_version');
        $environment = $this->environment->get();

        $result = [
            'installed' => ['distribution' => $distribution] + $release,
            'environment' => $environment,
            'advisories' => ['available' => false, 'source' => 'magento.watch'],
            'candidates' => [],
        ];

        $current = $this->advisories->getVersion($distribution, $release['version']);
        if ($current === null) {
            $result['advisories']['reason'] = $this->advisories->getLastError();
            $result['summary'] = 'Release requirements unavailable: ' . $this->advisories->getLastError();

            return $result;
        }

        $result['advisories'] = [
            'available' => true,
            'source' => 'magento.watch',
            'fetched_at' => $current['fetched_at'],
            'cached' => $current['cached'],
        ];
        $result['current'] = $this->evaluate($release['version'], $current['data'], ['installed'], $environment);

        $supported = $target === null ? ($this->advisories->getSupportedVersions($distribution)['data'] ?? []) : [];
        $candidates = $target !== null
            ? [$target => ['requested']]
            : $this->candidates($release['version'], $current['data'], $supported);

        foreach ($candidates as $version => $reasons) {
            $data = is_array($supported[$version] ?? null) ? $supported[$version] : null;
            if ($data === null) {
                $lookup = $this->advisories->getVersion($distribution, (string) $version);
                if ($lookup === null) {
                    if ($target !== null) {
                        throw new LocalizedException(__(
                            'No release data for %1: %2',
                            $target,
                            $this->advisories->getLastError()
                        ));
                    }
                    continue;
                }
                $data = $lookup['data'];
            }
            $result['candidates'][] = $this->evaluate((string) $version, $data, $reasons, $environment);
        }

        $result['summary'] = $this->summarize($release['version'], $result['candidates']);

        return $result;
    }

    /**
     * The releases worth weighing, as version => why it was picked.
     *
     * @param string $installed
     * @param array<string, mixed> $current
     * @param array<string, mixed> $supported
     * @return array<string, string[]>
     */
    private function candidates(string $installed, array $current, array $supported): array
    {
        $candidates = [];

        $recommended = $current['security']['recommendedVersion'] ?? null;
        if (is_string($recommended) && $recommended !== '' && $recommended !== $installed) {
            $candidates[$recommended][] = 'recommended_for_security';
        }

        $installedDate = (string) ($current['releaseDate'] ?? '');
        $line = $this->line($installed);
        $newestOnLine = null;

        foreach ($supported as $version => $data) {
            $version = (string) $version;
            if (!is_array($data) || $version === $installed) {
                continue;
            }
            if (($data['isLatestVersion'] ?? false) === true) {
                $candidates[$version][] = 'latest_release';
            }
            $date = (string) ($data['releaseDate'] ?? '');
            if ($line !== null && $this->line($version) === $line && $date > $installedDate
                && ($newestOnLine === null || $date > $newestOnLine[1])
            ) {
                $newestOnLine = [$version, $date];
            }
        }

        if ($newestOnLine !== null) {
            $candidates[$newestOnLine[0]][] = 'latest_patch_on_line';
        }

        return $candidates;
    }

    /**
     * One release's requirements against what this store measures.
     *
     * @param string $version
     * @param array<string, mixed> $data
     * @param string[] $reasons
     * @param array<string, array<int, array<string, string>>> $environment {@see EnvironmentVersions::get()}
     * @return array<string, mixed>
     */
    private function evaluate(string $version, array $data, array $reasons, array $environment): array
    {
        $required = is_array($data['systemRequirements'] ?? null) ? $data['systemRequirements'] : [];
        $components = [];
        $covered = [];

        foreach ($environment['measured'] as $measured) {
            $row = $this->compareComponent($measured, $required);
            $components[] = $row;
            $covered = array_merge($covered, self::FAMILIES[$measured['component']] ?? [$measured['product']]);
        }

        $notChecked = array_values(array_filter(
            array_keys($required),
            static fn ($key): bool => !in_array($key, $covered, true) && !str_starts_with((string) $key, 'aws-')
        ));

        $blockers = $this->componentsWith($components, 'blocker');
        $warnings = $this->componentsWith($components, 'warning');

        return [
            'version' => $version,
            'reasons' => $reasons,
            'release_date' => $data['releaseDate'] ?? null,
            'eol_date' => $data['eolDate'] ?? null,
            'status_label' => $data['statusLabel'] ?? null,
            'security' => $this->securityOf($data),
            'compatible' => $blockers === [],
            'blockers' => $blockers,
            'warnings' => $warnings,
            'components' => $components,
            'not_checked' => $notChecked,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $components
     * @param string $severity
     * @return string[]
     */
    private function componentsWith(array $components, string $severity): array
    {
        $names = [];
        foreach ($components as $component) {
            if ($component['severity'] === $severity) {
                $names[] = $component['component'];
            }
        }

        return $names;
    }

    /**
     * @param array<string, string> $measured
     * @param array<string, mixed> $required
     * @return array<string, mixed>
     */
    private function compareComponent(array $measured, array $required): array
    {
        $product = $measured['product'];
        $row = [
            'component' => $measured['component'],
            'product' => $product,
            'installed' => $measured['version'],
        ];

        if (is_array($required[$product] ?? null)) {
            $listed = array_values($required[$product]);
            $verdict = $this->requirements->compare($measured['version'], $listed);

            return $row + [
                'required' => $listed,
                'verdict' => $verdict,
                'severity' => $this->severity($measured['component'], $verdict),
            ];
        }

        $alternatives = [];
        foreach (self::FAMILIES[$measured['component']] ?? [] as $other) {
            if ($other !== $product && is_array($required[$other] ?? null)) {
                $alternatives[$other] = array_values($required[$other]);
            }
        }

        if ($alternatives === []) {
            return $row + ['required' => [], 'verdict' => 'not_declared', 'severity' => 'info'];
        }

        return $row + [
            'required' => $alternatives,
            'verdict' => 'product_not_listed',
            'severity' => 'warning',
            'note' => sprintf(
                'The release lists %s, not %s; their version numbers are not comparable.',
                implode(' or ', array_keys($alternatives)),
                $product
            ),
        ];
    }

    /**
     * @param string $component
     * @param string $verdict
     * @return string
     */
    private function severity(string $component, string $verdict): string
    {
        if ($verdict === VersionRequirements::SUPPORTED) {
            return 'ok';
        }
        if ($verdict === VersionRequirements::NOT_COMPARABLE) {
            return 'info';
        }

        return $component === 'php' ? 'blocker' : 'warning';
    }

    /**
     * The release's own security standing, from whichever shape the API gave:
     * list endpoints carry a `securitySummary`, single-release ones a full
     * `security` block.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function securityOf(array $data): array
    {
        if (is_array($data['securitySummary'] ?? null)) {
            return [
                'status' => $data['securitySummary']['status'] ?? null,
                'isolated_patches_needed' => $data['securitySummary']['requiredPatches'] ?? null,
                'upgrade_required' => $data['securitySummary']['upgradeRequired'] ?? null,
            ];
        }

        $open = (array) ($data['security']['vulnerableTo'] ?? []);
        $patches = 0;
        $needsUpgrade = false;
        foreach ($open as $bulletin) {
            $count = count((array) ($bulletin['isolatedPatches'] ?? []));
            $patches += $count;
            $needsUpgrade = $needsUpgrade || ($count === 0 && ($bulletin['fixedIn'] ?? null) !== null);
        }

        return [
            'status' => $data['security']['status'] ?? null,
            'isolated_patches_needed' => $patches,
            'upgrade_required' => $needsUpgrade,
        ];
    }

    /**
     * "2.4.8-p5" belongs to the 2.4.8 line.
     *
     * @param string $version
     * @return string|null
     */
    private function line(string $version): ?string
    {
        return preg_match('/^(\d+\.\d+\.\d+)/', $version, $match) === 1 ? $match[1] : null;
    }

    /**
     * @param string $installed
     * @param array<int, array<string, mixed>> $candidates
     * @return string
     */
    private function summarize(string $installed, array $candidates): string
    {
        if ($candidates === []) {
            return sprintf('No newer supported release than %s was found.', $installed);
        }

        $parts = [];
        foreach ($candidates as $candidate) {
            $parts[] = $candidate['compatible']
                ? sprintf('%s: no blockers, %d warning(s)', $candidate['version'], count($candidate['warnings']))
                : sprintf('%s: blocked by %s', $candidate['version'], implode(', ', $candidate['blockers']));
        }

        return sprintf('From %s — %s.', $installed, implode('; ', $parts));
    }
}
