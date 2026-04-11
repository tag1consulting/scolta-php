<?php

declare(strict_types=1);

namespace Tag1\Scolta\Tests\Index;

use PHPUnit\Framework\TestCase;
use Tag1\Scolta\Index\IndexMerger;

class IndexMergerTest extends TestCase
{
    private IndexMerger $merger;

    protected function setUp(): void
    {
        $this->merger = new IndexMerger();
    }

    public function testMergeTwoPartials(): void
    {
        $partial1 = [
            'index' => [
                'apple' => [
                    1 => ['positions' => [25 => [5, 20]]],
                ],
            ],
            'pages' => [
                1 => ['url' => '/a', 'wordCount' => 50, 'hash' => 'aaa'],
            ],
        ];

        $partial2 = [
            'index' => [
                'banana' => [
                    2 => ['positions' => [25 => [10]]],
                ],
            ],
            'pages' => [
                2 => ['url' => '/b', 'wordCount' => 30, 'hash' => 'bbb'],
            ],
        ];

        $result = $this->merger->merge([$partial1, $partial2]);

        $this->assertArrayHasKey('apple', $result['index']);
        $this->assertArrayHasKey('banana', $result['index']);
        $this->assertCount(2, $result['pages']);
    }

    public function testMergeOverlappingWords(): void
    {
        $partial1 = [
            'index' => [
                'apple' => [
                    1 => ['positions' => [25 => [5]]],
                ],
            ],
            'pages' => [1 => ['url' => '/a', 'wordCount' => 10, 'hash' => 'a']],
        ];

        $partial2 = [
            'index' => [
                'apple' => [
                    2 => ['positions' => [25 => [10]]],
                ],
            ],
            'pages' => [2 => ['url' => '/b', 'wordCount' => 10, 'hash' => 'b']],
        ];

        $result = $this->merger->merge([$partial1, $partial2]);

        // Apple should have both pages.
        $applePages = array_filter($result['index']['apple'], fn ($k) => is_int($k), ARRAY_FILTER_USE_KEY);
        $this->assertCount(2, $applePages);
    }

    public function testMergeSortsByPageNumber(): void
    {
        $partial1 = [
            'index' => [
                'test' => [
                    5 => ['positions' => [25 => [1]]],
                ],
            ],
            'pages' => [5 => ['url' => '/e', 'wordCount' => 10, 'hash' => 'e']],
        ];

        $partial2 = [
            'index' => [
                'test' => [
                    2 => ['positions' => [25 => [1]]],
                ],
            ],
            'pages' => [2 => ['url' => '/b', 'wordCount' => 10, 'hash' => 'b']],
        ];

        $result = $this->merger->merge([$partial1, $partial2]);
        $pageNums = array_keys(
            array_filter($result['index']['test'], fn ($k) => is_int($k), ARRAY_FILTER_USE_KEY)
        );
        $this->assertSame([2, 5], $pageNums);
    }

    public function testMergeEmptyPartials(): void
    {
        $result = $this->merger->merge([]);
        $this->assertEmpty($result['index']);
        $this->assertEmpty($result['pages']);
    }

    public function testMergeSkipsInvalidPartials(): void
    {
        $valid = [
            'index' => ['word' => [1 => ['positions' => [25 => [1]]]]],
            'pages' => [1 => ['url' => '/a', 'wordCount' => 10, 'hash' => 'a']],
        ];

        $result = $this->merger->merge([$valid, ['bad' => 'data']]);
        $this->assertArrayHasKey('word', $result['index']);
    }

    public function testMergeVariants(): void
    {
        $partial1 = [
            'index' => [
                'cafe' => [
                    1 => ['positions' => [25 => [5]]],
                    '_variants' => ['café' => [1]],
                ],
            ],
            'pages' => [1 => ['url' => '/a', 'wordCount' => 10, 'hash' => 'a']],
        ];

        $partial2 = [
            'index' => [
                'cafe' => [
                    2 => ['positions' => [25 => [10]]],
                    '_variants' => ['café' => [2]],
                ],
            ],
            'pages' => [2 => ['url' => '/b', 'wordCount' => 10, 'hash' => 'b']],
        ];

        $result = $this->merger->merge([$partial1, $partial2]);
        $this->assertSame([1, 2], $result['index']['cafe']['_variants']['café']);
    }

    public function testMergeTenPartials(): void
    {
        $partials = [];
        for ($i = 0; $i < 10; $i++) {
            $partials[] = [
                'index' => [
                    'common' => [$i => ['positions' => [25 => [$i * 10]]]],
                    "word{$i}" => [$i => ['positions' => [25 => [0]]]],
                ],
                'pages' => [$i => ['url' => "/page-{$i}", 'wordCount' => 10, 'hash' => "h{$i}"]],
            ];
        }

        $result = $this->merger->merge($partials);

        // 'common' should have all 10 pages.
        $commonPages = array_filter($result['index']['common'], fn ($k) => is_int($k), ARRAY_FILTER_USE_KEY);
        $this->assertCount(10, $commonPages);
        $this->assertCount(10, $result['pages']);
    }

    public function testMergeDuplicatePositionsDeduped(): void
    {
        $partial1 = [
            'index' => [
                'word' => [1 => ['positions' => [25 => [5, 10]]]],
            ],
            'pages' => [1 => ['url' => '/a', 'wordCount' => 10, 'hash' => 'a']],
        ];

        $partial2 = [
            'index' => [
                'word' => [1 => ['positions' => [25 => [5, 15]]]],
            ],
            'pages' => [1 => ['url' => '/a', 'wordCount' => 10, 'hash' => 'a']],
        ];

        $result = $this->merger->merge([$partial1, $partial2]);
        $positions = $result['index']['word'][1]['positions'][25];
        $this->assertSame([5, 10, 15], $positions); // Deduped and sorted.
    }
}
