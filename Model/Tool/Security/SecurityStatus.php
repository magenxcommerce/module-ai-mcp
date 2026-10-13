<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\Security;

use Magenx\AiMcp\Model\Tool\AbstractTool;

/**
 * Which security bulletins are still open against this store, and what it
 * records about the isolated patches that close them.
 *
 * Two halves, deliberately kept apart in the result. The bulletins come from
 * the advisory API and are facts about the *release*: 2.4.8-p5 is affected by
 * APSB26-73 whoever runs it. The patch evidence comes from this installation's
 * own composer and hotfix records and is a fact about *this store*. Joining
 * them is the point of the tool, but an agent has to be able to see which
 * half a conclusion rests on — so every bulletin carries the evidence that
 * moved it, and "no_evidence" is never written as "missing".
 *
 * With the lookup switched off or unreachable the tool still answers: the
 * installed release and every patch record, with the bulletins marked
 * unavailable and the reason, rather than failing the call.
 */
class SecurityStatus extends AbstractTool
{
    private const MAX_RECORDS = 100;

    /** Bulletin-level outcome of joining the API's bulletin with local evidence. */
    private const BULLETIN_STATUS = [
        PatchEvidence::APPLIED => 'patched',
        PatchEvidence::DECLARED => 'declared_not_verified',
        PatchEvidence::PRESENT => 'hotfix_file_present',
        PatchEvidence::NO_EVIDENCE => 'no_evidence',
    ];

    /**
     * @param InstalledRelease $installedRelease
     * @param AdvisoryClient $advisories
     * @param PatchEvidence $patchEvidence
     */
    public function __construct(
        private readonly InstalledRelease $installedRelease,
        private readonly AdvisoryClient $advisories,
        private readonly PatchEvidence $patchEvidence
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'security_status';
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return 'Report the installed Magento release, the Adobe security bulletins open against it (from '
            . 'magento.watch) and whether this store records the isolated patch for each, from composer-patches '
            . 'records, composer.json and m2-hotfixes/. "no_evidence" means no record, not proof of absence: a '
            . 'patch applied by hand leaves none.';
    }

    /**
     * @inheritDoc
     */
    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'list_patch_records' => [
                    'type' => 'boolean',
                    'description' => 'Also return every patch record found, not only the ones matched to a '
                        . 'bulletin (at most ' . self::MAX_RECORDS . '). Defaults to false.',
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
     * It asks a service outside this store, which is exactly what this hint is
     * for — the one kind of read here that is not about this store alone.
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
        $listRecords = (bool) $this->optionalBool($arguments, 'list_patch_records', false);

        $records = $this->patchEvidence->collect();
        $result = [
            'installed' => ['distribution' => $distribution] + $release,
            'advisories' => ['available' => false, 'source' => 'magento.watch'],
            'status' => 'unknown',
            'open_bulletins' => [],
            'apply_order' => [],
            'patch_records' => [
                'sources' => $records['sources'],
                'total' => count($records['entries']),
            ],
        ];
        if ($listRecords) {
            $result['patch_records']['entries'] = array_slice($records['entries'], 0, self::MAX_RECORDS);
        }

        $lookup = $this->advisories->getVersion($distribution, $release['version']);
        if ($lookup === null) {
            $result['advisories']['reason'] = $this->advisories->getLastError();
            $result['summary'] = sprintf(
                '%s %s. Bulletins unavailable (%s); %d local patch record(s) found.',
                $distribution,
                $release['version'],
                $this->advisories->getLastError(),
                count($records['entries'])
            );

            return $result;
        }

        $data = $lookup['data'];
        $security = is_array($data['security'] ?? null) ? $data['security'] : [];
        $result['advisories'] = [
            'available' => true,
            'source' => 'magento.watch',
            'fetched_at' => $lookup['fetched_at'],
            'cached' => $lookup['cached'],
            'release_status' => $security['status'] ?? null,
        ];
        $result['lifecycle'] = [
            'release_date' => $data['releaseDate'] ?? null,
            'eol_date' => $data['eolDate'] ?? null,
            'status_label' => $data['statusLabel'] ?? null,
            'is_eol' => (bool) ($data['isEOLVersion'] ?? false),
        ];

        $bulletins = [];
        foreach ((array) ($security['vulnerableTo'] ?? []) as $bulletin) {
            if (is_array($bulletin)) {
                $bulletins[] = $this->joinBulletin($bulletin);
            }
        }

        $result['open_bulletins'] = $bulletins;
        $result['apply_order'] = $this->applyOrder($bulletins);
        $result['recommended_version'] = $security['recommendedVersion'] ?? null;
        $result['fixed_by_installed_release'] = array_values(array_filter(array_map(
            static fn ($fixed): ?string => is_array($fixed) && is_string($fixed['id'] ?? null) ? $fixed['id'] : null,
            (array) ($security['fixedBy'] ?? [])
        )));
        $result['status'] = $this->overallStatus($bulletins, $result['lifecycle']['is_eol']);
        $result['summary'] = $this->summarize($release['version'], $result['status'], $bulletins);

        return $result;
    }

    /**
     * One open bulletin, with the evidence for each of its isolated patches.
     *
     * @param array<string, mixed> $bulletin
     * @return array<string, mixed>
     */
    private function joinBulletin(array $bulletin): array
    {
        $id = (string) ($bulletin['id'] ?? '');
        $patches = [];
        $weakest = null;

        foreach ((array) ($bulletin['isolatedPatches'] ?? []) as $patch) {
            if (!is_array($patch) || !is_string($patch['id'] ?? null)) {
                continue;
            }
            // The bulletin id is matched as well as the patch id: a patch file
            // saved as "APSB26-73_2.4.8-p5.patch" is evidence for that bulletin
            // even though it never names "2026-jul".
            $match = $this->patchEvidence->match([$patch['id'], $id]);
            $patches[] = [
                'id' => $patch['id'],
                'url' => $patch['url'] ?? null,
                'evidence' => $match['status'],
                'matched_records' => array_map(
                    static fn (array $entry): array => [
                        'source' => $entry['source'],
                        'package' => $entry['package'],
                        'description' => $entry['description'],
                    ],
                    $match['matches']
                ),
            ];
            $weakest = $this->weaker($weakest, $match['status']);
        }

        if ($patches === []) {
            $status = ($bulletin['fixedIn'] ?? null) !== null ? 'upgrade_required' : 'no_fix_published';
        } else {
            $status = self::BULLETIN_STATUS[$weakest ?? PatchEvidence::NO_EVIDENCE];
        }

        return [
            'id' => $id,
            'severity' => $bulletin['severity'] ?? null,
            'published_date' => $bulletin['publishedDate'] ?? null,
            'cves' => array_values((array) ($bulletin['cves'] ?? [])),
            'fixed_in' => $bulletin['fixedIn'] ?? null,
            'isolated_patches' => $patches,
            'local_status' => $status,
        ];
    }

    /**
     * The isolated patches not yet recorded as applied, oldest bulletin first.
     *
     * The API lists bulletins newest first. Adobe's isolated patches are not
     * cumulative and are meant to go on in publication order, so the order is
     * reversed here rather than left for an agent to work out.
     *
     * @param array<int, array<string, mixed>> $bulletins
     * @return string[]
     */
    private function applyOrder(array $bulletins): array
    {
        $order = [];
        foreach (array_reverse($bulletins) as $bulletin) {
            foreach ($bulletin['isolated_patches'] as $patch) {
                if ($patch['evidence'] !== PatchEvidence::APPLIED && !in_array($patch['id'], $order, true)) {
                    $order[] = $patch['id'];
                }
            }
        }

        return $order;
    }

    /**
     * @param array<int, array<string, mixed>> $bulletins
     * @param bool $isEol
     * @return string
     */
    private function overallStatus(array $bulletins, bool $isEol): string
    {
        if ($isEol) {
            return 'eol';
        }
        if ($bulletins === []) {
            return 'secure';
        }

        $statuses = array_column($bulletins, 'local_status');
        if (array_diff($statuses, ['patched']) === []) {
            return 'patched_per_local_records';
        }
        if (array_diff($statuses, ['patched', 'declared_not_verified', 'hotfix_file_present']) === []) {
            return 'possibly_patched';
        }

        return 'vulnerable';
    }

    /**
     * @param string $version
     * @param string $status
     * @param array<int, array<string, mixed>> $bulletins
     * @return string
     */
    private function summarize(string $version, string $status, array $bulletins): string
    {
        $open = array_filter(
            $bulletins,
            static fn (array $bulletin): bool => $bulletin['local_status'] !== 'patched'
        );

        return match ($status) {
            'secure' => sprintf('%s has no open security bulletins.', $version),
            'patched_per_local_records' => sprintf(
                '%s is affected by %d bulletin(s); local records show every isolated patch applied.',
                $version,
                count($bulletins)
            ),
            'eol' => sprintf('%s is end of life and receives no further security fixes.', $version),
            default => sprintf(
                '%s is affected by %d bulletin(s); %d without an applied-patch record: %s.',
                $version,
                count($bulletins),
                count($open),
                implode(', ', array_column($open, 'id'))
            ),
        };
    }

    /**
     * The weaker of two evidence statuses.
     *
     * A bulletin is only as patched as its least-evidenced isolated patch:
     * Adobe's isolated patches are not cumulative, so where a bulletin lists
     * more than one for a release, one applied and one not is still exposed.
     *
     * @param string|null $current
     * @param string $candidate
     * @return string
     */
    private function weaker(?string $current, string $candidate): string
    {
        $rank = array_flip(array_keys(self::BULLETIN_STATUS));

        return $current === null || $rank[$candidate] > $rank[$current] ? $candidate : $current;
    }
}
