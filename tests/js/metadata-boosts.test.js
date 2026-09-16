'use strict';

const fs = require('fs');
const path = require('path');

const jsSource = fs.readFileSync(
    path.resolve(__dirname, '../../assets/js/scolta.js'),
    'utf-8'
);

// Extract the metadata-boost block from scoreResults and run it verbatim, so
// the assertion covers the shipped code rather than a re-implementation.
function applyMetadataBoosts(scored, metadataBoosts) {
    const block = jsSource.match(
        /const metadataBoosts = CONFIG\.METADATA_BOOSTS[\s\S]*?\n    \}\n(?=    \/\/ Exact title match)/
    );
    expect(block).not.toBeNull();
    const CONFIG = { METADATA_BOOSTS: metadataBoosts };
    new Function('CONFIG', 'scored', block[0])(CONFIG, scored);
    return scored;
}

describe('metadata boosts', () => {
    const boosts = { type: { 'node:tntl': 1.4 } };

    test('boosts a listed value, leaves unlisted values and meta-less results alone', () => {
        const scored = [
            { data: { meta: { type: 'node:tntl' } }, score: 1.0 },
            { data: { meta: { type: 'node:blog_post' } }, score: 1.0 },
            { data: {}, score: 1.0 },
        ];
        applyMetadataBoosts(scored, boosts);
        expect(scored[0].score).toBeCloseTo(1.4);
        expect(scored[1].score).toBe(1.0);
        expect(scored[2].score).toBe(1.0);
    });

    test('multipliers from different keys multiply', () => {
        const scored = [
            { data: { meta: { type: 'node:tntl', rating: '5' } }, score: 1.0 },
        ];
        applyMetadataBoosts(scored, { type: { 'node:tntl': 1.4 }, rating: { '5': 1.3 } });
        expect(scored[0].score).toBeCloseTo(1.82);
    });

    test('empty config leaves scores untouched', () => {
        const scored = [
            { data: { meta: { type: 'node:tntl' } }, score: 1.0 },
        ];
        applyMetadataBoosts(scored, {});
        expect(scored[0].score).toBe(1.0);
    });

    test('numeric meta value matches its string key', () => {
        const scored = [
            { data: { meta: { rating: 5 } }, score: 1.0 },
        ];
        applyMetadataBoosts(scored, { rating: { '5': 1.3 } });
        expect(scored[0].score).toBeCloseTo(1.3);
    });
});
