import { defineConfig } from 'vitest/config';

export default defineConfig({
  test: {
    include: ['tests/**/*.test.js'],
    environment: 'node',
    globalSetup: ['tests/helpers/globalSetup.js'],
    setupFiles: ['tests/helpers/setupEnv.js'],
    // API, database and migration tests share one test database.
    fileParallelism: false,
    testTimeout: 30_000,
    hookTimeout: 120_000,
  },
});
