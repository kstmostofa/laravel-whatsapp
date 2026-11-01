const assert = require('node:assert/strict');
const { EventEmitter } = require('node:events');
const test = require('node:test');
const { sendAndCapture, bodyMatcher } = require('../send-capture');

const idOf = (m) => (m && m.id) || null;

test('returns the send result when it already carries an id', async () => {
  const client = new EventEmitter();
  const result = await sendAndCapture(client, async () => ({ id: 'true_x_1', body: 'hi' }), bodyMatcher('hi'), idOf);

  assert.equal(result.id, 'true_x_1');
  assert.equal(client.listenerCount('message_create'), 0);
});

test('recovers the id from message_create when sendMessage returns undefined', async () => {
  const client = new EventEmitter();
  const result = await sendAndCapture(client, async () => {
    client.emit('message_create', { fromMe: false, body: 'hi', id: 'false_x_9' }); // someone else's
    client.emit('message_create', { fromMe: true, body: 'other', id: 'true_x_8' }); // a different send
    client.emit('message_create', { fromMe: true, body: 'hi', id: 'true_x_2' });
    return undefined;
  }, bodyMatcher('hi'), idOf);

  assert.equal(result.id, 'true_x_2');
});

test('catches a message_create that arrives after sendMessage resolves', async () => {
  const client = new EventEmitter();
  const pending = sendAndCapture(client, async () => {
    setTimeout(() => client.emit('message_create', { fromMe: true, body: 'late', id: 'true_x_3' }), 20);
    return undefined;
  }, bodyMatcher('late'), idOf);

  assert.equal((await pending).id, 'true_x_3');
});

test('gives up after the timeout and returns what send returned', async () => {
  const client = new EventEmitter();
  const result = await sendAndCapture(client, async () => undefined, bodyMatcher('never'), idOf, 30);

  assert.equal(result, null);
  assert.equal(client.listenerCount('message_create'), 0);
});

test('send errors propagate and the listener is removed', async () => {
  const client = new EventEmitter();

  await assert.rejects(sendAndCapture(client, async () => { throw new Error('boom'); }, bodyMatcher('x'), idOf), /boom/);
  assert.equal(client.listenerCount('message_create'), 0);
});
