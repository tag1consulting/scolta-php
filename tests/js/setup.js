// Mock fetch for AI endpoints.
global.fetch = jest.fn();

// Mock dynamic import for Pagefind.
// scolta.js uses `import(pagefindPath)` which JSDOM doesn't support.
// We provide a mock Pagefind module.
global.mockPagefind = {
    init: jest.fn().mockResolvedValue(undefined),
    search: jest.fn().mockResolvedValue({ results: [] }),
};
