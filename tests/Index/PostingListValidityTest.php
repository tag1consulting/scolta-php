<?php

declare(strict_types=1);

namespace Tag1\Scolta\Tests\Index;

use PHPUnit\Framework\TestCase;
use Tag1\Scolta\Export\ContentItem;
use Tag1\Scolta\Index\PhpIndexer;
use Tag1\Scolta\Index\Stemmer;
use Tag1\Scolta\Index\Tokenizer;
use Tag1\Scolta\Tests\Support\CborDecoder;

/**
 * Semantic correctness of posting lists: every word entry in pf_index must
 * actually appear (in stemmed form) in the fragment it references, and every
 * significant word in a fragment must resolve back to the posting list.
 *
 * Separate from ByteParityTest — this tests meaning, not just decodability.
 *
 * @since 0.3.0
 * @stability experimental
 */
class PostingListValidityTest extends TestCase
{
    private string $stateDir;
    private string $outputDir;
    private string $pagefindDir;

    /** Decoded pf_meta, cached after first load. */
    private ?array $pfMeta = null;

    /** Map of stemmed_word => [page_num, ...] built from all pf_index files. */
    private ?array $postingList = null;

    private Stemmer $stemmer;
    private Tokenizer $tokenizer;

    protected function setUp(): void
    {
        $this->stateDir = sys_get_temp_dir() . '/scolta-plv-state-' . uniqid();
        $this->outputDir = sys_get_temp_dir() . '/scolta-plv-output-' . uniqid();
        mkdir($this->stateDir, 0755, true);
        mkdir($this->outputDir, 0755, true);

        $this->buildWithPhpIndexer();
        $this->pagefindDir = $this->outputDir . '/pagefind';
        $this->stemmer = new Stemmer('en');
        $this->tokenizer = new Tokenizer();
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->stateDir);
        $this->removeDir($this->outputDir);
    }

    // ---------------------------------------------------------------
    // Tests
    // ---------------------------------------------------------------

    /**
     * Every stemmed word in a pf_index entry must appear (after stemming the
     * fragment content) in the fragment that the entry references.
     */
    public function testEveryIndexedWordAppearsInItsReferencedFragment(): void
    {
        $meta = $this->getPfMeta();
        $pagesArray = $meta[1] ?? [];
        $failures = [];

        foreach ($this->getIndexFiles() as $indexFile) {
            $decoded = CborDecoder::decodePfFile($indexFile);
            // Outer wrapper: [[entries...]]
            $entries = $decoded[0] ?? [];

            foreach ($entries as $entry) {
                // entry = [word_string, [pages...], [variants...]]
                [$word, $pageRefs] = [$entry[0], $entry[1]];

                // Reconstruct absolute page numbers from delta-encoded refs.
                $absPageNums = $this->deltaDecodePageNums($pageRefs);

                foreach ($absPageNums as $pageNum) {
                    if (!isset($pagesArray[$pageNum])) {
                        $failures[] = "(word={$word}, page={$pageNum}): page not in pf_meta";
                        continue;
                    }

                    $fragmentHash = $pagesArray[$pageNum][0];
                    $fragPath = $this->pagefindDir . "/fragment/{$fragmentHash}.pf_fragment";

                    $fragData = $this->loadFragmentJson($fragPath);
                    if ($fragData === null) {
                        $failures[] = "(word={$word}, page={$pageNum}, hash={$fragmentHash}): fragment file missing";
                        continue;
                    }

                    // Use the same tokenize+stem pipeline as the indexer so that
                    // camelCase splitting and numeric tokens are handled identically.
                    $content = $fragData['content'] ?? '';
                    $url = $fragData['url'] ?? "hash:{$fragmentHash}";
                    $title = $fragData['meta']['title'] ?? '';

                    // Collect all stems from body content, title, and URL path.
                    // The indexer indexes words from all three sources.
                    $stemmedTokens = $this->tokenizeAndStem($content);
                    $stemmedTokens = array_merge($stemmedTokens, $this->tokenizeAndStem($title));

                    // Also include URL-path stems (the indexer indexes these too).
                    $urlPath = preg_replace('/\.\w+$/', '', parse_url($url, PHP_URL_PATH) ?? '');
                    $urlSegments = array_filter(explode('/', $urlPath), fn ($s) => strlen($s) > 0);
                    $stemmedTokens = array_merge($stemmedTokens, $this->tokenizeAndStem(implode(' ', $urlSegments)));

                    if (!in_array($word, array_unique($stemmedTokens), true)) {
                        $failures[] = "(word={$word}, page={$pageNum}, url={$url}): stemmed word not in fragment, title, or url";
                    }
                }
            }
        }

        $this->assertEmpty(
            $failures,
            "Posting list semantic failures:\n" . implode("\n", array_slice($failures, 0, 20))
            . (count($failures) > 20 ? "\n... and " . (count($failures) - 20) . ' more' : '')
        );
    }

    /**
     * For each fragment, significant words (> 3 chars) stemmed must appear in
     * the posting list and reference back to that fragment's page number.
     */
    public function testFragmentWordsResolveToPageInIndex(): void
    {
        $meta = $this->getPfMeta();
        $pagesArray = $meta[1] ?? [];
        $posting = $this->getPostingList();
        $failures = [];

        foreach ($pagesArray as $pageNum => $pageEntry) {
            $fragmentHash = $pageEntry[0];
            $fragPath = $this->pagefindDir . "/fragment/{$fragmentHash}.pf_fragment";
            $fragData = $this->loadFragmentJson($fragPath);
            if ($fragData === null) {
                continue;
            }

            $content = $fragData['content'] ?? '';
            $url = $fragData['url'] ?? "hash:{$fragmentHash}";

            // Use the same tokenize+stem pipeline as the indexer.
            // Pick up to 5 tokens whose stemmed form is > 3 chars (skip trivial tokens).
            $allTokens = $this->tokenizer->tokenize($content);
            $picked = [];
            foreach ($allTokens as $token) {
                $stemmed = $this->stemmer->stem($token['stem']);
                if ($stemmed !== '' && strlen($stemmed) > 3 && ctype_alpha($stemmed)) {
                    $picked[$stemmed] = $token['stem'];
                    if (count($picked) >= 5) {
                        break;
                    }
                }
            }

            foreach ($picked as $stemmed => $rawToken) {
                if (!isset($posting[$stemmed])) {
                    $failures[] = "(word={$rawToken}, stemmed={$stemmed}, page={$pageNum}, url={$url}): stemmed form not in posting list";
                    continue;
                }

                if (!in_array($pageNum, $posting[$stemmed], true)) {
                    $failures[] = "(word={$rawToken}, stemmed={$stemmed}, page={$pageNum}, url={$url}): page not in posting list entry";
                }
            }
        }

        $this->assertEmpty(
            $failures,
            "Fragment→posting list failures:\n" . implode("\n", array_slice($failures, 0, 20))
            . (count($failures) > 20 ? "\n... and " . (count($failures) - 20) . ' more' : '')
        );
    }

    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

    private function buildWithPhpIndexer(): void
    {
        $items = $this->loadCorpus();
        $indexer = new PhpIndexer($this->stateDir, $this->outputDir);
        $indexer->processChunk($items, 0);
        $result = $indexer->finalize();
        $this->assertTrue($result->success, 'PHP index build must succeed: ' . ($result->error ?? ''));
    }

    /** @return ContentItem[] */
    private function loadCorpus(): array
    {
        $corpusDir = __DIR__ . '/../fixtures/concordance/corpus';
        $items = [];

        foreach (glob($corpusDir . '/*.html') as $file) {
            $filename = pathinfo($file, PATHINFO_FILENAME);
            $html = file_get_contents($file);

            preg_match('/<title>(.*?)<\/title>/s', $html, $titleMatch);
            $title = html_entity_decode($titleMatch[1] ?? $filename);

            preg_match('/<body[^>]*>(.*?)<\/body>/s', $html, $bodyMatch);
            $body = $bodyMatch[1] ?? '';

            preg_match('/data-pagefind-meta="date:([^"]*)"/', $html, $dateMatch);
            $date = $dateMatch[1] ?? '';

            preg_match('/data-pagefind-filter="category:([^"]*)"/', $html, $catMatch);
            $siteName = $catMatch[1] ?? '';

            $items[] = new ContentItem($filename, $title, $body, '/' . $filename . '.html', $date, $siteName);
        }

        return $items;
    }

    private function getPfMeta(): array
    {
        if ($this->pfMeta !== null) {
            return $this->pfMeta;
        }

        $metaFiles = glob($this->pagefindDir . '/pagefind.*.pf_meta') ?: glob($this->pagefindDir . '/*.pf_meta');
        $this->assertNotEmpty($metaFiles, 'No pf_meta file found');

        $this->pfMeta = CborDecoder::decodePfFile($metaFiles[0]);

        return $this->pfMeta;
    }

    /** @return string[] */
    private function getIndexFiles(): array
    {
        return glob($this->pagefindDir . '/index/*.pf_index') ?: [];
    }

    /**
     * Build complete posting list: stemmed_word => [abs_page_num, ...].
     * @return array<string, int[]>
     */
    private function getPostingList(): array
    {
        if ($this->postingList !== null) {
            return $this->postingList;
        }

        $this->postingList = [];

        foreach ($this->getIndexFiles() as $indexFile) {
            $decoded = CborDecoder::decodePfFile($indexFile);
            $entries = $decoded[0] ?? [];

            foreach ($entries as $entry) {
                $word = $entry[0];
                $pageRefs = $entry[1];
                $absPageNums = $this->deltaDecodePageNums($pageRefs);

                if (!isset($this->postingList[$word])) {
                    $this->postingList[$word] = [];
                }
                foreach ($absPageNums as $pn) {
                    $this->postingList[$word][] = $pn;
                }
            }
        }

        return $this->postingList;
    }

    /**
     * Delta-decode page numbers from pf_index page refs.
     *
     * Each page ref is [delta_page_num, locs, meta_locs].
     * First is absolute; subsequent are differences.
     *
     * @return int[]
     */
    private function deltaDecodePageNums(array $pageRefs): array
    {
        $abs = [];
        $running = 0;
        foreach ($pageRefs as $pageRef) {
            // pageRef = [delta_int, locs_array, meta_locs_array]
            $delta = $pageRef[0];
            $running += $delta;
            $abs[] = $running;
        }

        return $abs;
    }

    private function loadFragmentContent(string $fragPath): ?string
    {
        $data = $this->loadFragmentJson($fragPath);

        return $data !== null ? ($data['content'] ?? '') : null;
    }

    private function loadFragmentJson(string $fragPath): ?array
    {
        if (!file_exists($fragPath)) {
            return null;
        }
        $decompressed = gzdecode(file_get_contents($fragPath));
        if ($decompressed === false) {
            return null;
        }
        if (str_starts_with($decompressed, 'pagefind_dcd')) {
            $decompressed = substr($decompressed, 12);
        }

        return json_decode($decompressed, true);
    }

    /**
     * Tokenize text and stem each token using the same pipeline as the indexer.
     *
     * Returns unique stemmed forms (what the indexer would store as keys).
     *
     * @return string[]
     */
    private function tokenizeAndStem(string $text): array
    {
        $tokens = $this->tokenizer->tokenize($text);
        $stemmed = [];
        foreach ($tokens as $token) {
            $s = $this->stemmer->stem($token['stem']);
            if ($s !== '') {
                $stemmed[] = $s;
            }
        }

        return array_unique($stemmed);
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
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
