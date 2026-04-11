<?php

declare(strict_types=1);

namespace Tag1\Scolta\Tests\Index;

use PHPUnit\Framework\TestCase;
use Tag1\Scolta\Index\Stemmer;

class StemmerTest extends TestCase
{
    public function testEnglishStemRunning(): void
    {
        $stemmer = new Stemmer('en');
        $this->assertSame('run', $stemmer->stem('running'));
    }

    public function testEnglishStemWalks(): void
    {
        $stemmer = new Stemmer('en');
        $this->assertSame('walk', $stemmer->stem('walks'));
    }

    public function testEnglishStemCats(): void
    {
        $stemmer = new Stemmer('en');
        $this->assertSame('cat', $stemmer->stem('cats'));
    }

    public function testEnglishStemComputing(): void
    {
        $stemmer = new Stemmer('en');
        $this->assertSame('comput', $stemmer->stem('computing'));
    }

    public function testUnsupportedLanguageFallback(): void
    {
        $stemmer = new Stemmer('xx');
        $this->assertSame('hello', $stemmer->stem('hello'));
    }

    public function testFrenchStemmer(): void
    {
        $stemmer = new Stemmer('fr');
        // French stemmer should handle basic French words.
        $result = $stemmer->stem('maisons');
        $this->assertIsString($result);
        $this->assertNotEmpty($result);
    }

    public function testGermanStemmer(): void
    {
        $stemmer = new Stemmer('de');
        $result = $stemmer->stem('Häuser');
        $this->assertIsString($result);
    }

    public function testStemIdempotent(): void
    {
        $stemmer = new Stemmer('en');
        $stemmed = $stemmer->stem('running');
        $this->assertSame($stemmed, $stemmer->stem($stemmed));
    }
}
