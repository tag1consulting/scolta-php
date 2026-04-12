// @ts-check
const { defineConfig } = require('@playwright/test');

module.exports = defineConfig({
    testDir: './tests/E2E',
    timeout: 30000,
    use: {
        headless: true,
    },
    projects: [
        { name: 'chromium', use: { browserName: 'chromium' } },
    ],
});
