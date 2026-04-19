// Polyfill TextEncoder/TextDecoder for JSDOM in Node.
const { TextEncoder, TextDecoder } = require('util');
global.TextEncoder = TextEncoder;
global.TextDecoder = TextDecoder;

// Mock fetch for AI endpoints.
global.fetch = jest.fn();

// Mock dynamic import for Pagefind.
global.mockPagefind = {
    init: jest.fn().mockResolvedValue(undefined),
    search: jest.fn().mockResolvedValue({ results: [] }),
};

