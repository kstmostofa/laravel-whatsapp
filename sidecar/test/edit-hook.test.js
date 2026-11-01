const assert = require('node:assert/strict');
const { EventEmitter } = require('node:events');
const test = require('node:test');
const { ensureEditHook, editDeduper } = require('../edit-hook');

test.afterEach(() => {
  delete global.window;
});

function fakePage() {
  const Msg = new EventEmitter();
  const reported = [];
  const win = {
    require: (name) => (name === 'WAWebCollections' ? { Msg } : undefined),
    WWebJS: { getMessageModel: (m) => ({ id: m.id }) },
    onEditMessageEvent: (model, newBody, previous) => reported.push([model.id, newBody, previous]),
  };
  return {
    Msg,
    reported,
    // A real page's window outlives evaluate(): the hook's handlers run later.
    evaluate(fn, ...args) {
      global.window = win;
      return Promise.resolve(fn(...args));
    },
  };
}

test('reports body and caption changes to whatsapp-web.js separately', async () => {
  const page = fakePage();
  assert.equal(await ensureEditHook(page), 'installed');

  const msg = { id: 'M1', type: 'chat', previous: (attr) => `old ${attr}` };
  page.Msg.emit('change:body', msg, 'new text');
  page.Msg.emit('change:caption', msg, 'new caption');
  page.Msg.emit('change:body change:caption', msg, 'never emitted like this');

  assert.deepEqual(page.reported, [['M1', 'new text', 'old body'], ['M1', 'new caption', 'old caption']]);
});

test('installs once per page and ignores revoked messages', async () => {
  const page = fakePage();
  await ensureEditHook(page);
  assert.equal(await ensureEditHook(page), 'already');

  page.Msg.emit('change:body', { id: 'M1', type: 'chat' }, 'x');
  page.Msg.emit('change:body', { id: 'M2', type: 'revoked' }, '');

  assert.equal(page.reported.length, 1);
});

test('dedupes the same edit within the window, but not a different one', () => {
  let t = 0;
  const isNew = editDeduper(5000, () => t);

  assert.equal(isNew('M1', 'a'), true);
  assert.equal(isNew('M1', 'a'), false);
  assert.equal(isNew('M1', 'b'), true);
  t = 6000;
  assert.equal(isNew('M1', 'a'), true);
});
