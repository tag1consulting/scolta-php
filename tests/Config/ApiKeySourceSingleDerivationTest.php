<?php

declare(strict_types=1);

namespace Tag1\Scolta\Tests\Config;

use PHPUnit\Framework\TestCase;

/**
 * Nothing outside the resolver decides an API key's source.
 *
 * The defect was two derivations of one fact with opposite precedence. Fixing
 * both to agree would leave them free to drift again, so the structural
 * property — one decision point — is what this asserts.
 */
class ApiKeySourceSingleDerivationTest extends TestCase
{
    /**
     * The only files allowed to name an ApiKeySource case.
     *
     * @var list<string>
     */
    private const DECIDERS = [
        'src/Config/ApiKeySource.php',
        'src/Config/ApiKeyResolver.php',
        'src/Config/ResolvedApiKey.php',
    ];

    public function testOnlyTheResolverNamesApiKeySourceCases(): void
    {
        $offenders = [];
        foreach ($this->sourceFiles() as $relative => $contents) {
            if (in_array($relative, self::DECIDERS, true)) {
                continue;
            }
            if (str_contains($contents, 'ApiKeySource::')) {
                $offenders[] = $relative;
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "These files decide an API key source themselves; take it from ApiKeyResolver::resolve() instead:\n"
            . implode("\n", $offenders),
        );
    }

    public function testOnlyAmazeeAndTheResolverReadStoredCredentials(): void
    {
        $offenders = [];
        foreach ($this->sourceFiles() as $relative => $contents) {
            if (str_starts_with($relative, 'src/AiProvider/Amazee/')
                || $relative === 'src/Config/AmazeeCredentials.php') {
                continue;
            }
            if (str_contains($contents, 'litellm_token')) {
                $offenders[] = $relative;
            }
        }

        $this->assertSame(
            [],
            $offenders,
            'Reading the credential store outside the Amazee subsystem is how a surface ends up '
            . "reporting Amazee as active when an explicit key won:\n" . implode("\n", $offenders),
        );
    }

    /**
     * @return array<string, string> Relative path => contents.
     */
    private function sourceFiles(): array
    {
        $root = dirname(__DIR__, 2);
        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root . '/src', \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }
            $relative = str_replace($root . '/', '', $file->getPathname());
            $files[$relative] = (string) file_get_contents($file->getPathname());
        }

        $this->assertNotEmpty($files, 'Found no PHP sources to scan');

        return $files;
    }
}
