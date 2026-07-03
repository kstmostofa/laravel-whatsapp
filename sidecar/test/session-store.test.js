const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const test = require('node:test');
const { discoverPersistedSessions } = require('../session-store');

function makeSessionDir() {
  return fs.mkdtempSync(path.join(os.tmpdir(), 'laravel-wa-sessions-'));
}

test('discovers persisted session directories', () => {
  const dir = makeSessionDir();
  fs.mkdirSync(path.join(dir, 'session-main'));
  fs.mkdirSync(path.join(dir, 'session-support'));

  assert.deepEqual(discoverPersistedSessions(dir), ['main', 'support']);
});

test('supports session ids containing hyphens', () => {
  const dir = makeSessionDir();
  fs.mkdirSync(path.join(dir, 'session-sales-team'));

  assert.deepEqual(discoverPersistedSessions(dir), ['sales-team']);
});

test('ignores files and unrelated directories', () => {
  const dir = makeSessionDir();
  fs.mkdirSync(path.join(dir, 'session-main'));
  fs.mkdirSync(path.join(dir, 'cache'));
  fs.writeFileSync(path.join(dir, 'session-file'), '');

  assert.deepEqual(discoverPersistedSessions(dir), ['main']);
});

test('returns an empty list when the session directory is missing', () => {
  assert.deepEqual(discoverPersistedSessions(path.join(os.tmpdir(), 'missing-laravel-wa-sessions')), []);
});
