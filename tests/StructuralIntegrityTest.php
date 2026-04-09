<?php

declare(strict_types=1);

namespace Tag1\Scolta\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Validates package structure, namespaces, and rename integrity.
 */
class StructuralIntegrityTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__);
    }

    // -------------------------------------------------------------------
    // Composer package name
    // -------------------------------------------------------------------

    public function testComposerPackageName(): void
    {
        $composer = json_decode(file_get_contents($this->root . '/composer.json'), true);
        $this->assertEquals('tag1/scolta-php', $composer['name']);
    }

    public function testComposerAutoload(): void
    {
        $composer = json_decode(file_get_contents($this->root . '/composer.json'), true);
        $this->assertArrayHasKey('Tag1\\Scolta\\', $composer['autoload']['psr-4']);
        $this->assertEquals('src/', $composer['autoload']['psr-4']['Tag1\\Scolta\\']);
    }

    // -------------------------------------------------------------------
    // Rename integrity — no stale references
    // -------------------------------------------------------------------

    public function testNoScoltaCoreWasmReferences(): void
    {
        $stale = $this->grepSourceFiles('/scolta[-_]core[-_]wasm/i');
        $this->assertEmpty(
            $stale,
            "Files still reference scolta-core-wasm:\n" . implode("\n", $stale)
        );
    }

    public function testNoOldPackageName(): void
    {
        $stale = $this->grepSourceFiles('/"tag1\/scolta"/');
        $this->assertEmpty(
            $stale,
            "Files still reference old package name:\n" . implode("\n", $stale)
        );
    }

    public function testWasmBinaryPathUsesUnderscores(): void
    {
        $wasm = file_get_contents($this->root . '/src/Wasm/ScoltaWasm.php');
        $this->assertStringContainsString('scolta_core.wasm', $wasm);
        $this->assertStringNotContainsString('scolta-core.wasm', $wasm);
    }

    // -------------------------------------------------------------------
    // Interface and class checks
    // -------------------------------------------------------------------

    public function testContentSourceInterfaceExists(): void
    {
        $this->assertTrue(interface_exists(\Tag1\Scolta\Content\ContentSourceInterface::class));
    }

    public function testDefaultScorerIsConcreteClass(): void
    {
        $ref = new \ReflectionClass(\Tag1\Scolta\Scorer\DefaultScorer::class);
        $this->assertFalse($ref->isAbstract());
        $this->assertFalse($ref->isInterface());
    }

    public function testDeadInterfacesRemoved(): void
    {
        $this->assertFalse(
            file_exists(dirname(__DIR__) . '/src/Provider/AiProviderInterface.php'),
            'AiProviderInterface should be removed (dead code)'
        );
        $this->assertFalse(
            file_exists(dirname(__DIR__) . '/src/Scorer/ScorerInterface.php'),
            'ScorerInterface should be removed (dead code)'
        );
    }

    // -------------------------------------------------------------------
    // Required files exist
    // -------------------------------------------------------------------

    #[\PHPUnit\Framework\Attributes\DataProvider('requiredFileProvider')]
    public function testRequiredFileExists(string $relativePath): void
    {
        $this->assertFileExists($this->root . '/' . $relativePath);
    }

    public static function requiredFileProvider(): array
    {
        return [
            'composer.json' => ['composer.json'],
            'ScoltaConfig' => ['src/Config/ScoltaConfig.php'],
            'AiClient' => ['src/AiClient.php'],
            'AiServiceAdapter' => ['src/Service/AiServiceAdapter.php'],
            'ContentExporter' => ['src/Export/ContentExporter.php'],
            'ContentItem' => ['src/Export/ContentItem.php'],
            'DefaultPrompts' => ['src/Prompt/DefaultPrompts.php'],
            'DefaultScorer' => ['src/Scorer/DefaultScorer.php'],
            'ScoltaWasm' => ['src/Wasm/ScoltaWasm.php'],
            'AiResponse' => ['src/Provider/AiResponse.php'],
            'ContentSourceInterface' => ['src/Content/ContentSourceInterface.php'],
            'TrackerRecord' => ['src/Content/TrackerRecord.php'],
            'scolta.js' => ['assets/js/scolta.js'],
            'scolta.css' => ['assets/css/scolta.css'],
        ];
    }

    // -------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------

    private function grepSourceFiles(string $pattern): array
    {
        $hits = [];
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS)
        );
        $exclude = ['vendor', '.git', 'node_modules', '.phpunit.cache', 'tests', 'wasm'];

        foreach ($it as $file) {
            $path = $file->getPathname();
            foreach ($exclude as $dir) {
                if (str_contains($path, '/' . $dir . '/')) {
                    continue 2;
                }
            }
            if (!in_array($file->getExtension(), ['php', 'json', 'yml', 'js', 'md'], true)) {
                continue;
            }

            if (preg_match($pattern, file_get_contents($path))) {
                $hits[] = str_replace($this->root . '/', '', $path);
            }
        }
        return $hits;
    }
}
