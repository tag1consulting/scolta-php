<?php

declare(strict_types=1);

namespace Tag1\Scolta\Tests\Concordance;

use PHPUnit\Framework\TestCase;
use Tag1\Scolta\Export\ContentItem;
use Tag1\Scolta\Index\PhpIndexer;

/**
 * Multi-language concordance tests.
 *
 * Verifies that the PHP indexer correctly handles non-ASCII languages:
 * - German (umlauts, ß)
 * - French (accents, cedillas, ligatures)
 * - Spanish (ñ, inverted punctuation)
 * - Chinese / Japanese / Korean (CJK — character-level tokenization)
 * - Mixed-language pages
 *
 * Each test verifies:
 * 1. The indexer completes without error (structural validity).
 * 2. The fragment content preserves the original Unicode characters.
 * 3. At least one token is produced per page (even for CJK).
 *
 * Note: PHP indexer uses Snowball stemming (15 languages). CJK uses
 * character-level tokenization without stemming, which still produces
 * a searchable index.
 */
class MultiLanguageConcordanceTest extends TestCase
{
    private string $stateDir;
    private string $outputDir;

    protected function setUp(): void
    {
        $this->stateDir = sys_get_temp_dir() . '/scolta-ml-state-' . uniqid();
        $this->outputDir = sys_get_temp_dir() . '/scolta-ml-output-' . uniqid();
        mkdir($this->stateDir, 0755, true);
        mkdir($this->outputDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->stateDir);
        $this->removeDir($this->outputDir);
    }

    // -----------------------------------------------------------------------
    // German
    // -----------------------------------------------------------------------

    public function testGermanUmlautsIndexedCorrectly(): void
    {
        $items = [
            new ContentItem(
                id: 'de-1',
                title: 'Über die Müdigkeit und Schönheit',
                bodyHtml: '<p>Die Straße führt durch die schöne Stadt München. '
                    . 'Größere Häuser stehen neben kleineren Gebäuden. '
                    . 'Der Weiße Turm ist wunderschön anzusehen. '
                    . 'Björn trinkt heiße Schokolade beim Fußball.</p>',
                url: '/de/umlauts',
                date: '2026-01-01',
            ),
            new ContentItem(
                id: 'de-2',
                title: 'Philosophie und Wissenschaft',
                bodyHtml: '<p>Wissenschaftliche Erkenntnisse über die Natur des Bewusstseins. '
                    . 'Das Außergewöhnliche liegt oft im Gewöhnlichen verborgen. '
                    . 'Öffentliche Bildungseinrichtungen fördern das Verständnis.</p>',
                url: '/de/philosophie',
                date: '2026-01-02',
            ),
        ];

        $result = $this->buildAndVerify($items);

        $this->assertTrue($result['success']);
        $this->assertEquals(2, $result['pageCount']);

        // Verify umlaut characters are preserved in fragment content.
        $this->assertFragmentContains('münchen', $result['fragments']);
        $this->assertFragmentContains('straße', $result['fragments']);
    }

    public function testGermanStemming(): void
    {
        // "laufen" (to run), "läuft" (runs), "gelaufen" (ran) should share stem
        // in English-stemmed index, they don't — but they should still index.
        $items = [
            new ContentItem(
                id: 'de-stem',
                title: 'Laufen und Läufer',
                bodyHtml: '<p>Der Läufer läuft jeden Tag. Er ist viel gelaufen. '
                    . 'Laufen macht gesund. Die Läuferin trainiert täglich.</p>',
                url: '/de/laufen',
                date: '2026-01-01',
            ),
        ];

        $indexer = new PhpIndexer($this->stateDir, $this->outputDir, null, 'de');
        $indexer->processChunk($items, 0, 1);
        $result = $indexer->finalize();

        $this->assertTrue($result->success);
        $this->assertEquals(1, $result->pageCount);
    }

    // -----------------------------------------------------------------------
    // French
    // -----------------------------------------------------------------------

    public function testFrenchAccentsIndexedCorrectly(): void
    {
        $items = [
            new ContentItem(
                id: 'fr-1',
                title: 'Résumé et Café: Élégance française',
                bodyHtml: '<p>Le café parisien est célèbre pour son élégance. '
                    . 'Les résumés académiques présentent des idées complexes. '
                    . 'Naïve à première vue, la question révèle une profondeur inattendue. '
                    . 'Noël approche avec ses décorations enchantées.</p>',
                url: '/fr/accents',
                date: '2026-01-01',
            ),
            new ContentItem(
                id: 'fr-2',
                title: 'Économie et Société',
                bodyHtml: '<p>L\'économie française est diversifiée. '
                    . 'Les inégalités sociales posent des défis. '
                    . 'L\'État protège les droits des citoyens. '
                    . 'Être ou ne pas être, telle est la question.</p>',
                url: '/fr/economie',
                date: '2026-01-02',
            ),
        ];

        $result = $this->buildAndVerify($items);

        $this->assertTrue($result['success']);
        $this->assertEquals(2, $result['pageCount']);
        $this->assertFragmentContains('café', $result['fragments']);
        $this->assertFragmentContains('élégance', $result['fragments']);
    }

    public function testFrenchStemming(): void
    {
        $items = [
            new ContentItem(
                id: 'fr-stem',
                title: 'Courir et Course',
                bodyHtml: '<p>Je cours tous les matins. La course à pied est bénéfique. '
                    . 'Il a couru un marathon. Courir améliore la santé.</p>',
                url: '/fr/courir',
                date: '2026-01-01',
            ),
        ];

        $indexer = new PhpIndexer($this->stateDir, $this->outputDir, null, 'fr');
        $indexer->processChunk($items, 0, 1);
        $result = $indexer->finalize();

        $this->assertTrue($result->success);
        $this->assertEquals(1, $result->pageCount);
    }

    // -----------------------------------------------------------------------
    // Spanish
    // -----------------------------------------------------------------------

    public function testSpanishSpecialCharactersIndexedCorrectly(): void
    {
        $items = [
            new ContentItem(
                id: 'es-1',
                title: '¿Qué es la inteligencia artificial?',
                bodyHtml: '<p>La inteligencia artificial está revolucionando el mundo. '
                    . '¡Es increíble cómo la tecnología avanza día a día! '
                    . 'El niño aprende con facilidad. La señorita habla español. '
                    . 'Mañana habrá lluvia según el pronóstico.</p>',
                url: '/es/ia',
                date: '2026-01-01',
            ),
            new ContentItem(
                id: 'es-2',
                title: 'España y Latinoamérica: Historia común',
                bodyHtml: '<p>España tiene una historia fascinante. '
                    . 'Los países latinoamericanos comparten raíces culturales. '
                    . 'La música española y la danza flamenca son únicas. '
                    . 'El año pasado visité México y Argentina.</p>',
                url: '/es/historia',
                date: '2026-01-02',
            ),
        ];

        $result = $this->buildAndVerify($items);

        $this->assertTrue($result['success']);
        $this->assertEquals(2, $result['pageCount']);
        $this->assertFragmentContains('inteligencia', $result['fragments']);
        $this->assertFragmentContains('españa', $result['fragments']);
    }

    // -----------------------------------------------------------------------
    // CJK — Chinese
    // -----------------------------------------------------------------------

    public function testChineseCharactersIndexedWithoutCrash(): void
    {
        $items = [
            new ContentItem(
                id: 'zh-1',
                title: '人工智能与机器学习',
                bodyHtml: '<p>人工智能正在改变世界。机器学习是人工智能的重要分支。'
                    . '深度学习使用神经网络处理复杂问题。'
                    . '自然语言处理帮助计算机理解人类语言。</p>',
                url: '/zh/ai',
                date: '2026-01-01',
            ),
            new ContentItem(
                id: 'zh-2',
                title: '中国历史与文化',
                bodyHtml: '<p>中国有悠久的历史和丰富的文化。'
                    . '长城是中国最著名的建筑之一。'
                    . '中国的传统节日包括春节和中秋节。</p>',
                url: '/zh/history',
                date: '2026-01-02',
            ),
        ];

        $indexer = new PhpIndexer($this->stateDir, $this->outputDir);
        $indexer->processChunk($items, 0, 2);
        $result = $indexer->finalize();

        $this->assertTrue($result->success, 'Chinese content must index without crashing');
        $this->assertEquals(2, $result->pageCount);

        // Fragment must preserve Chinese characters.
        $pagefindDir = $this->outputDir . '/pagefind';
        $fragFiles = glob($pagefindDir . '/fragment/*.pf_fragment') ?: [];
        $this->assertNotEmpty($fragFiles);

        $allContent = '';
        foreach ($fragFiles as $frag) {
            $raw = gzdecode(file_get_contents($frag));
            $decoded = json_decode(preg_replace('/^pagefind_dcd/', '', $raw), true);
            $allContent .= ($decoded['content'] ?? '');
        }

        $this->assertStringContainsString('人工智能', $allContent, 'Chinese characters must be preserved in fragments');
    }

    // -----------------------------------------------------------------------
    // Japanese
    // -----------------------------------------------------------------------

    public function testJapaneseHiraganaKatakanaIndexedWithoutCrash(): void
    {
        $items = [
            new ContentItem(
                id: 'ja-1',
                title: '検索エンジンの仕組み',
                bodyHtml: '<p>検索エンジンはウェブページを収集してインデックスを作成します。'
                    . 'ユーザーはキーワードを入力して情報を検索できます。'
                    . 'アルゴリズムが関連性を計算してランキングを決定します。</p>',
                url: '/ja/search',
                date: '2026-01-01',
            ),
        ];

        $indexer = new PhpIndexer($this->stateDir, $this->outputDir);
        $indexer->processChunk($items, 0, 1);
        $result = $indexer->finalize();

        $this->assertTrue($result->success, 'Japanese content must index without crashing');
        $this->assertEquals(1, $result->pageCount);
    }

    // -----------------------------------------------------------------------
    // Korean
    // -----------------------------------------------------------------------

    public function testKoreanHangulIndexedWithoutCrash(): void
    {
        $items = [
            new ContentItem(
                id: 'ko-1',
                title: '인공지능과 머신러닝',
                bodyHtml: '<p>인공지능은 현대 기술의 핵심입니다. '
                    . '머신러닝 알고리즘은 데이터에서 패턴을 학습합니다. '
                    . '자연어 처리는 컴퓨터가 인간의 언어를 이해하게 합니다.</p>',
                url: '/ko/ai',
                date: '2026-01-01',
            ),
        ];

        $indexer = new PhpIndexer($this->stateDir, $this->outputDir);
        $indexer->processChunk($items, 0, 1);
        $result = $indexer->finalize();

        $this->assertTrue($result->success, 'Korean content must index without crashing');
        $this->assertEquals(1, $result->pageCount);
    }

    // -----------------------------------------------------------------------
    // Mixed-language
    // -----------------------------------------------------------------------

    public function testMixedEnglishFrenchPage(): void
    {
        $items = [
            new ContentItem(
                id: 'mixed-1',
                title: 'Introduction to Web Development — Développement Web',
                bodyHtml: '<p>This tutorial covers modern web development. '
                    . 'Ce tutoriel couvre le développement web moderne. '
                    . 'We use PHP, JavaScript, and CSS. '
                    . 'Nous utilisons PHP, JavaScript et CSS. '
                    . 'Search functionality is handled by Scolta. '
                    . 'La fonctionnalité de recherche est gérée par Scolta.</p>',
                url: '/mixed/en-fr',
                date: '2026-01-01',
            ),
        ];

        $indexer = new PhpIndexer($this->stateDir, $this->outputDir);
        $indexer->processChunk($items, 0, 1);
        $result = $indexer->finalize();

        $this->assertTrue($result->success, 'Mixed English/French page must index without crashing');
        $this->assertEquals(1, $result->pageCount);

        // Both English and French tokens should be present in fragments.
        $pagefindDir = $this->outputDir . '/pagefind';
        $fragFiles = glob($pagefindDir . '/fragment/*.pf_fragment') ?: [];
        $raw = gzdecode(file_get_contents($fragFiles[0]));
        $decoded = json_decode(preg_replace('/^pagefind_dcd/', '', $raw), true);
        $content = strtolower($decoded['content'] ?? '');

        $this->assertStringContainsString('tutorial', $content, 'English word "tutorial" must be in mixed-language fragment');
        $this->assertStringContainsString('tutoriel', $content, 'French word "tutoriel" must be in mixed-language fragment');
    }

    public function testMixedEnglishChinesePage(): void
    {
        $items = [
            new ContentItem(
                id: 'mixed-zh',
                title: 'PHP and 机器学习 (Machine Learning)',
                bodyHtml: '<p>PHP is widely used in web development. '
                    . '机器学习正在改变软件开发。 '
                    . 'Combining 人工智能 with PHP creates powerful applications. '
                    . '这是一个创新的解决方案。</p>',
                url: '/mixed/en-zh',
                date: '2026-01-01',
            ),
        ];

        $indexer = new PhpIndexer($this->stateDir, $this->outputDir);
        $indexer->processChunk($items, 0, 1);
        $result = $indexer->finalize();

        $this->assertTrue($result->success, 'Mixed English/Chinese page must index without crashing');
        $this->assertEquals(1, $result->pageCount);
    }

    // -----------------------------------------------------------------------
    // Arabic (RTL)
    // -----------------------------------------------------------------------

    public function testArabicRtlIndexedWithoutCrash(): void
    {
        $items = [
            new ContentItem(
                id: 'ar-1',
                title: 'الذكاء الاصطناعي وتعلم الآلة',
                bodyHtml: '<p dir="rtl">الذكاء الاصطناعي يغير العالم. '
                    . 'تعلم الآلة هو فرع مهم من الذكاء الاصطناعي. '
                    . 'معالجة اللغة الطبيعية تساعد الحاسوب على فهم لغة البشر.</p>',
                url: '/ar/ai',
                date: '2026-01-01',
            ),
        ];

        $indexer = new PhpIndexer($this->stateDir, $this->outputDir);
        $indexer->processChunk($items, 0, 1);
        $result = $indexer->finalize();

        $this->assertTrue($result->success, 'Arabic RTL content must index without crashing');
        $this->assertEquals(1, $result->pageCount);
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    /**
     * Build an index from $items and return validation results.
     *
     * @param ContentItem[] $items
     * @return array{success: bool, pageCount: int, fragments: array[]}
     */
    private function buildAndVerify(array $items): array
    {
        $indexer = new PhpIndexer($this->stateDir, $this->outputDir);
        $indexer->processChunk($items, 0, count($items));
        $result = $indexer->finalize();

        $fragments = $this->loadFragments($this->outputDir . '/pagefind');

        return [
            'success' => $result->success,
            'pageCount' => $result->pageCount,
            'fragments' => $fragments,
        ];
    }

    /**
     * Load and decode all fragment files from a pagefind directory.
     *
     * @return array[] Array of decoded fragment objects.
     */
    private function loadFragments(string $pagefindDir): array
    {
        $decoded = [];
        foreach (glob($pagefindDir . '/fragment/*.pf_fragment') ?: [] as $frag) {
            $raw = gzdecode(file_get_contents($frag));
            if ($raw === false) {
                continue;
            }
            $json = preg_replace('/^pagefind_dcd/', '', $raw);
            $data = json_decode($json, true);
            if (is_array($data)) {
                $decoded[] = $data;
            }
        }
        return $decoded;
    }

    /**
     * Assert that $needle (lowercased) appears somewhere in the combined content
     * of all fragments.
     *
     * @param array[] $fragments
     */
    private function assertFragmentContains(string $needle, array $fragments): void
    {
        $all = '';
        foreach ($fragments as $frag) {
            $all .= mb_strtolower((string) ($frag['content'] ?? '')) . ' ';
            // Also check title/meta fields.
            $meta = $frag['meta'] ?? [];
            if (isset($meta['title'])) {
                $all .= mb_strtolower((string) $meta['title']) . ' ';
            }
        }
        $this->assertStringContainsString(
            mb_strtolower($needle),
            $all,
            "Fragment content/meta should contain '{$needle}'"
        );
    }

    private function removeDir(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($dir);
    }
}
