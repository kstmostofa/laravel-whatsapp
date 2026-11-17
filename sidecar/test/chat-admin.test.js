const assert = require('node:assert/strict');
const test = require('node:test');
const { unmuteDateFrom, parseInviteCode } = require('../chat-admin');

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

test('invite code is taken from a bare code or any chat.whatsapp.com link', () => {
  for (const invite of [
    'AbC123xyz',
    '  AbC123xyz\n',
    'https://chat.whatsapp.com/AbC123xyz',
    'http://chat.whatsapp.com/AbC123xyz/',
    'chat.whatsapp.com/AbC123xyz',
    'HTTPS://CHAT.WHATSAPP.COM/AbC123xyz?utm_source=share',
    'https://chat.whatsapp.com/invite/AbC123xyz',
  ]) {
    assert.equal(parseInviteCode(invite), 'AbC123xyz', invite);
  }
});

test('anything else is a bad request', () => {
  for (const invite of [undefined, '', 'https://evil.example/AbC123xyz', 'https://chat.whatsapp.com.evil.example/AbC123xyz', 'https://chat.whatsapp.com/', 'Ab C1', 42]) {
    assert.throws(() => parseInviteCode(invite), (e) => e.http === 400, String(invite));
  }
});
