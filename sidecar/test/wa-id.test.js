const assert = require('node:assert/strict');
const test = require('node:test');
const { serializeWid, serializeMessageId } = require('../wa-id');

test('uses _serialized when whatsapp-web.js exposes it', () => {
  assert.equal(serializeWid({ _serialized: '9665XXXXXXXX@c.us' }), '9665XXXXXXXX@c.us');
  assert.equal(
    serializeMessageId({ _serialized: 'false_9665XXXXXXXX@c.us_3EB0ABCD' }),
    'false_9665XXXXXXXX@c.us_3EB0ABCD',
  );
});

test('passes through ids that are already strings', () => {
  assert.equal(serializeWid('9665XXXXXXXX@c.us'), '9665XXXXXXXX@c.us');
  assert.equal(serializeMessageId('false_9665XXXXXXXX@c.us_3EB0ABCD'), 'false_9665XXXXXXXX@c.us_3EB0ABCD');
});

test('rebuilds a wid from user + server when _serialized is minified away', () => {
  assert.equal(serializeWid({ user: '9665XXXXXXXX', server: 'c.us', $1: 'noise' }), '9665XXXXXXXX@c.us');
});

test('rebuilds a message id from its parts when _serialized is minified away', () => {
  // The shape reported for minified WhatsApp Web builds — no `_serialized`.
  assert.equal(
    serializeMessageId({ fromMe: false, remote: '1234567890@lid', id: '3EB0ABCD' }),
    'false_1234567890@lid_3EB0ABCD',
  );
  assert.equal(
    serializeMessageId({ fromMe: true, remote: { user: '9665XXXXXXXX', server: 'c.us' }, id: '3EB0ABCD' }),
    'true_9665XXXXXXXX@c.us_3EB0ABCD',
  );
});

test('appends the participant for group message ids', () => {
  assert.equal(
    serializeMessageId({
      fromMe: false,
      remote: '120363XXXXXXXX@g.us',
      id: '3EB0ABCD',
      participant: { _serialized: '9665XXXXXXXX@c.us' },
    }),
    'false_120363XXXXXXXX@g.us_3EB0ABCD_9665XXXXXXXX@c.us',
  );
});

test('falls back to a serialized-looking value stored under a minified key', () => {
  assert.equal(serializeWid({ $1: '9665XXXXXXXX@c.us' }), '9665XXXXXXXX@c.us');
  assert.equal(
    serializeMessageId({ fromMe: false, $1: 'false_9665XXXXXXXX@c.us_3EB0ABCD' }),
    'false_9665XXXXXXXX@c.us_3EB0ABCD',
  );
});

test('returns null when there is nothing usable', () => {
  assert.equal(serializeWid(null), null);
  assert.equal(serializeWid(undefined), null);
  assert.equal(serializeWid(''), null);
  assert.equal(serializeWid({}), null);
  assert.equal(serializeMessageId(null), null);
  assert.equal(serializeMessageId({}), null);
  assert.equal(serializeMessageId({ fromMe: false, remote: '9665XXXXXXXX@c.us' }), null);
});
