<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AiMcp\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * The version a client is told is the version that is installed.
 *
 * `serverInfo.version` comes from `etc/di.xml`, and release-please moves it
 * only because the config lists that file and the line carries its marker.
 * Either one going missing leaves the advertised version frozen at whatever it
 * last was — which is how it sat at 1.0.0 through ten releases.
 */
class ReleaseVersionTest extends TestCase
{
    /**
     * @return void
     */
    public function testTheAdvertisedVersionMatchesTheReleaseManifest(): void
    {
        $manifest = json_decode((string) file_get_contents(__DIR__ . '/../../.release-please-manifest.json'), true);
        self::assertIsArray($manifest);

        $this->assertSame($manifest['.'] ?? null, $this->advertisedVersion());
    }

    /**
     * @return void
     */
    public function testReleasePleaseRewritesTheAdvertisedVersion(): void
    {
        $config = json_decode((string) file_get_contents(__DIR__ . '/../../release-please-config.json'), true);
        self::assertIsArray($config);

        $this->assertContains(
            ['type' => 'generic', 'path' => 'etc/di.xml'],
            $config['packages']['.']['extra-files'] ?? []
        );
    }

    /**
     * The generic updater rewrites only lines carrying the marker.
     *
     * @return string|null
     */
    private function advertisedVersion(): ?string
    {
        $di = (string) file_get_contents(__DIR__ . '/../../etc/di.xml');
        $pattern = '#<argument name="serverVersion" xsi:type="string">([^<]+)</argument>'
            . '<!-- x-release-please-version -->#';

        return preg_match($pattern, $di, $match) === 1 ? $match[1] : null;
    }
}
