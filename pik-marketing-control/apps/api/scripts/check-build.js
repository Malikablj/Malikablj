/**
 * "Build" check for the API. The API is plain JavaScript run directly by Node, so there is
 * nothing to compile; this verifies every module (routes, controllers, services,
 * repositories, validators) loads and the app can be constructed.
 */
process.env.NODE_ENV ??= 'production';
const { createApp } = await import('../src/app.js');
createApp();
console.log('API build check passed: all modules load and the app constructs.');
