<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Model\Tool\Security;

use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Filesystem;
use Magento\Framework\Filesystem\Directory\ReadInterface;
use Magento\Framework\Serialize\Serializer\Json;

/**
 * What this installation records about the patches applied to it.
 *
 * Adobe's isolated security patches change files without changing a single
 * package version, so `list_modules` cannot tell a patched 2.4.8-p5 from an
 * unpatched one. What *can* tell them apart is whatever applied the patch, and
 * on a composer-built store that leaves a record in one of four places:
 *
 * - `vendor/composer/installed.json` — cweagans/composer-patches 1.x writes
 *   `extra.patches_applied` onto every package it patched. Applied.
 * - `patches.lock.json` — composer-patches 2.x resolves every patch into this
 *   file and applies exactly that set on install. Applied.
 * - `composer.json` `extra.patches` and the file `extra.patches-file` names.
 *   Declared: applied on the next install, which may or may not have run.
 * - `m2-hotfixes/*.patch` — the directory Adobe Commerce Cloud applies on every
 *   build, and a common convention elsewhere. Present.
 *
 * A patch applied by hand with `patch -p1` leaves none of these, which is why
 * the absence of evidence is reported as exactly that and never as "missing".
 *
 * Everything is read through Magento's filesystem rather than by shelling out
 * to `magento-patches status`: a hardened store disables process execution for
 * the PHP user, and should.
 */
class PatchEvidence
{
    public const APPLIED = 'applied';
    public const DECLARED = 'declared';
    public const PRESENT = 'present';
    public const NO_EVIDENCE = 'no_evidence';

    private const HOTFIX_DIR = 'm2-hotfixes';
    private const VENDOR_PATH_FILE = 'app/etc/vendor_path.php';

    /** installed.json runs to a few megabytes; anything far past that is not one. */
    private const MAX_FILE_BYTES = 33554432;

    /** @var array{sources: array<int, array<string, mixed>>, entries: array<int, array<string, mixed>>}|null */
    private ?array $collected = null;

    /**
     * @param Filesystem $filesystem
     * @param Json $json
     */
    public function __construct(
        private readonly Filesystem $filesystem,
        private readonly Json $json
    ) {
    }

    /**
     * Every patch record found, and what was looked at to find them.
     *
     * Each entry is `{source, status, package, description, reference}`;
     * each source is `{source, path, state, entries}`, where `state` is
     * `read`, `absent` or `unreadable` — the last being a hardened store
     * keeping the PHP user out, which is worth knowing rather than reading as
     * "no patches".
     *
     * @return array{sources: array<int, array<string, mixed>>, entries: array<int, array<string, mixed>>}
     */
    public function collect(): array
    {
        if ($this->collected !== null) {
            return $this->collected;
        }

        $root = $this->filesystem->getDirectoryRead(DirectoryList::ROOT);
        $sources = [];
        $entries = [];

        $installed = $this->vendorPath($root) . '/composer/installed.json';
        $this->readSource($root, $sources, $entries, 'composer_patches_v1', $installed, [
            $this,
            'fromInstalledJson',
        ]);
        $this->readSource($root, $sources, $entries, 'composer_patches_v2', 'patches.lock.json', [
            $this,
            'fromPatchesLock',
        ]);
        $composer = $this->readSource($root, $sources, $entries, 'composer_json', 'composer.json', [
            $this,
            'fromComposerJson',
        ]);

        $patchesFile = $composer['extra']['patches-file'] ?? null;
        if (is_string($patchesFile) && $patchesFile !== '' && !str_contains($patchesFile, '..')) {
            $this->readSource($root, $sources, $entries, 'composer_patches_file', ltrim($patchesFile, './'), [
                $this,
                'fromPatchesFile',
            ]);
        }

        $this->readHotfixes($root, $sources, $entries);

        return $this->collected = ['sources' => $sources, 'entries' => $entries];
    }

    /**
     * The strongest evidence that any of the given ids was applied.
     *
     * An id matches an entry when it appears, case-insensitively, in the
     * entry's description or reference — Adobe names both the patch files and
     * their composer descriptions after the patch (`VULN-39341_248-p3.patch`)
     * or the bulletin. Underscores are read as hyphens so `2026_jul` and
     * `2026-jul` are the same id.
     *
     * @param string[] $ids Patch and bulletin ids, e.g. ["VULN-39341", "APSB26-146"].
     * @return array{status: string, matches: array<int, array<string, mixed>>}
     */
    public function match(array $ids): array
    {
        $needles = array_values(array_filter(array_map(
            static fn ($id): string => is_string($id) ? str_replace('_', '-', strtolower(trim($id))) : '',
            $ids
        )));

        $matches = [];
        foreach ($this->collect()['entries'] as $entry) {
            $haystack = str_replace('_', '-', strtolower($entry['description'] . ' ' . $entry['reference']));
            foreach ($needles as $needle) {
                if (str_contains($haystack, $needle)) {
                    $matches[] = $entry;
                    break;
                }
            }
        }

        $status = self::NO_EVIDENCE;
        foreach ([self::APPLIED, self::DECLARED, self::PRESENT] as $candidate) {
            foreach ($matches as $entry) {
                if ($entry['status'] === $candidate) {
                    $status = $candidate;
                    break 2;
                }
            }
        }

        return ['status' => $status, 'matches' => $matches];
    }

    /**
     * Read one JSON source and append what it yields.
     *
     * @param ReadInterface $root
     * @param array<int, array<string, mixed>> $sources
     * @param array<int, array<string, mixed>> $entries
     * @param string $source
     * @param string $path
     * @param callable(array<mixed>): array<int, array<string, mixed>> $extract
     * @return array<mixed>|null The decoded file, for a caller that needs more of it.
     */
    private function readSource(
        ReadInterface $root,
        array &$sources,
        array &$entries,
        string $source,
        string $path,
        callable $extract
    ): ?array {
        $data = $this->readJson($root, $path, $state);
        $found = $data === null ? [] : $extract($data);

        $sources[] = ['source' => $source, 'path' => $path, 'state' => $state, 'entries' => count($found)];
        array_push($entries, ...$found);

        return $data;
    }

    /**
     * @param ReadInterface $root
     * @param string $path
     * @param string|null $state Set to read, absent or unreadable.
     * @return array<mixed>|null
     */
    private function readJson(ReadInterface $root, string $path, ?string &$state): ?array
    {
        try {
            if (!$root->isFile($path)) {
                $state = 'absent';

                return null;
            }
            $stat = $root->stat($path);
            if ((int) ($stat['size'] ?? 0) > self::MAX_FILE_BYTES) {
                $state = 'unreadable';

                return null;
            }
            $decoded = $this->json->unserialize((string) $root->readFile($path));
        } catch (\Throwable) {
            // A permission refusal and a malformed file are the same answer to
            // the question asked: nothing usable could be read from here.
            $state = 'unreadable';

            return null;
        }

        $state = 'read';

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * composer-patches 1.x: `extra.patches_applied` on each installed package.
     *
     * installed.json is `{"packages": [...]}` from Composer 2 and a bare list
     * from Composer 1; both are accepted.
     *
     * @param array<mixed> $data
     * @return array<int, array<string, mixed>>
     */
    private function fromInstalledJson(array $data): array
    {
        $packages = isset($data['packages']) && is_array($data['packages']) ? $data['packages'] : $data;

        $entries = [];
        foreach ($packages as $package) {
            if (!is_array($package) || !is_array($package['extra']['patches_applied'] ?? null)) {
                continue;
            }
            $name = is_string($package['name'] ?? null) ? $package['name'] : null;
            foreach ($package['extra']['patches_applied'] as $description => $reference) {
                $entries[] = $this->entry('composer_patches_v1', self::APPLIED, $name, $description, $reference);
            }
        }

        return $entries;
    }

    /**
     * composer-patches 2.x: `{"patches": {"vendor/package": [{description, url, ...}]}}`.
     *
     * @param array<mixed> $data
     * @return array<int, array<string, mixed>>
     */
    private function fromPatchesLock(array $data): array
    {
        $entries = [];
        foreach ((array) ($data['patches'] ?? []) as $package => $patches) {
            foreach ((array) $patches as $patch) {
                if (!is_array($patch)) {
                    continue;
                }
                $entries[] = $this->entry(
                    'composer_patches_v2',
                    self::APPLIED,
                    is_string($package) ? $package : null,
                    $patch['description'] ?? '',
                    $patch['url'] ?? ''
                );
            }
        }

        return $entries;
    }

    /**
     * @param array<mixed> $data
     * @return array<int, array<string, mixed>>
     */
    private function fromComposerJson(array $data): array
    {
        return $this->fromPatchMap($data['extra']['patches'] ?? [], 'composer_json');
    }

    /**
     * @param array<mixed> $data
     * @return array<int, array<string, mixed>>
     */
    private function fromPatchesFile(array $data): array
    {
        return $this->fromPatchMap($data['patches'] ?? [], 'composer_patches_file');
    }

    /**
     * The declaration format shared by composer.json and a patches file:
     * `{"vendor/package": {"description": "path or url"}}`, or in 2.x a list
     * of `{description, url}` objects under each package.
     *
     * @param mixed $map
     * @param string $source
     * @return array<int, array<string, mixed>>
     */
    private function fromPatchMap(mixed $map, string $source): array
    {
        $entries = [];
        foreach (is_array($map) ? $map : [] as $package => $patches) {
            foreach (is_array($patches) ? $patches : [] as $description => $patch) {
                if (is_array($patch)) {
                    $entries[] = $this->entry(
                        $source,
                        self::DECLARED,
                        (string) $package,
                        $patch['description'] ?? '',
                        $patch['url'] ?? ''
                    );
                    continue;
                }
                $entries[] = $this->entry($source, self::DECLARED, (string) $package, $description, $patch);
            }
        }

        return $entries;
    }

    /**
     * @param ReadInterface $root
     * @param array<int, array<string, mixed>> $sources
     * @param array<int, array<string, mixed>> $entries
     * @return void
     */
    private function readHotfixes(ReadInterface $root, array &$sources, array &$entries): void
    {
        $found = [];
        try {
            if (!$root->isExist(self::HOTFIX_DIR)) {
                $state = 'absent';
            } else {
                foreach ($root->read(self::HOTFIX_DIR) as $path) {
                    $path = (string) $path;
                    if (str_ends_with(strtolower($path), '.patch')) {
                        $name = substr($path, (int) strrpos('/' . $path, '/'));
                        $found[] = $this->entry('m2_hotfixes', self::PRESENT, null, $name, $path);
                    }
                }
                $state = 'read';
            }
        } catch (\Throwable) {
            $state = 'unreadable';
        }

        $sources[] = [
            'source' => 'm2_hotfixes',
            'path' => self::HOTFIX_DIR,
            'state' => $state,
            'entries' => count($found),
        ];
        array_push($entries, ...$found);
    }

    /**
     * Where Composer put vendor/, relative to the Magento root.
     *
     * Magento records it in app/etc/vendor_path.php as `return './vendor';`.
     * That file is read as text rather than included: the value is all that is
     * wanted, and there is no reason for a diagnostic to execute anything.
     *
     * @param ReadInterface $root
     * @return string
     */
    private function vendorPath(ReadInterface $root): string
    {
        try {
            $source = $root->isFile(self::VENDOR_PATH_FILE) ? (string) $root->readFile(self::VENDOR_PATH_FILE) : '';
        } catch (\Throwable) {
            $source = '';
        }

        if (preg_match('/return\s+[\'"]\.?\/?([A-Za-z0-9_.\-\/]+?)\/?[\'"]\s*;/', $source, $match) === 1
            && !str_contains($match[1], '..')
        ) {
            return $match[1];
        }

        return 'vendor';
    }

    /**
     * @param string $source
     * @param string $status
     * @param string|null $package
     * @param mixed $description
     * @param mixed $reference
     * @return array<string, mixed>
     */
    private function entry(
        string $source,
        string $status,
        ?string $package,
        mixed $description,
        mixed $reference
    ): array {
        return [
            'source' => $source,
            'status' => $status,
            'package' => $package,
            'description' => is_scalar($description) ? (string) $description : '',
            'reference' => is_scalar($reference) ? (string) $reference : '',
        ];
    }
}
