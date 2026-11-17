const assert = require('node:assert/strict');
const test = require('node:test');
const { unmuteDateFrom } = require('../chat-admin');

const NOW = Date.UTC(2026, 0, 1);

test('no until mutes forever', () => {
  assert.equal(unmuteDateFrom(undefined, NOW), undefined);
  assert.equal(unmuteDateFrom(null, NOW), undefined);
});

test('until in unix seconds becomes a date', () => {
  const until = NOW / 1000 + 3600;

  assert.deepEqual(unmuteDateFrom(until, NOW), new Date(NOW + 3600 * 1000));
});

test('until in the past or not a whole number is a bad request', () => {
  for (const until of [NOW / 1000, NOW / 1000 - 1, '1767229200', 1.5]) {
    assert.throws(() => unmuteDateFrom(until, NOW), (e) => e.http === 400);
  }
});
