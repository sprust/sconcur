<?php

declare(strict_types=1);

namespace SConcur\Tests\Feature\Connection;

use SConcur\Connection\Extension;
use SConcur\Tests\Feature\BaseTestCase;

/**
 * The places a version lives must stay in lockstep: the PHP package's required
 * version (Extension::REQUIRED_EXTENSION_VERSION), the loaded extension's own
 * reported version (Extension::version()), the version the module entry hands
 * to phpinfo() and phpversion(), and the composer.json "version" field. The
 * release CI tags from the extension version, so any drift between them would
 * ship a mislabeled release.
 *
 * The loaded one is whichever core the run was pointed at — the Rust one
 * (ext/src/lib.rs) unless SCONCUR_EXT says otherwise. The full list of sources
 * is in .ai/README.md.
 */
class VersionConsistencyTest extends BaseTestCase
{
    public function testRequiredVersionMatchesLoadedExtensionVersion(): void
    {
        self::assertSame(
            Extension::REQUIRED_EXTENSION_VERSION,
            $this->extension->version(),
            'the loaded "sconcur" extension version must equal REQUIRED_EXTENSION_VERSION',
        );
    }

    public function testComposerVersionMatchesRequiredVersion(): void
    {
        $composerPath = __DIR__ . '/../../../composer.json';

        $contents = file_get_contents($composerPath);

        if ($contents === false) {
            self::fail(sprintf('could not read %s', $composerPath));
        }

        /** @var array<string, mixed> $composer */
        $composer = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(
            Extension::REQUIRED_EXTENSION_VERSION,
            $composer['version'] ?? null,
            'composer.json "version" must equal REQUIRED_EXTENSION_VERSION',
        );
    }

    /**
     * What `php -m`, `php --ri sconcur` and phpinfo() print. It comes off the
     * module entry (ext/sconcur.c), which fills it from the core rather than
     * carrying a literal of its own, so this asserts that plumbing works — a
     * hardcoded copy reintroduced there would fail here.
     */
    public function testModuleEntryVersionMatchesRequiredVersion(): void
    {
        self::assertSame(
            Extension::REQUIRED_EXTENSION_VERSION,
            phpversion('sconcur'),
            'the "sconcur" module entry version must equal REQUIRED_EXTENSION_VERSION',
        );
    }
}
