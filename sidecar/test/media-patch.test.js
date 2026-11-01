const assert = require('node:assert/strict');
const test = require('node:test');
const { ensureMediaPatch } = require('../media-patch');

/** A page whose window persists across evaluate() calls. */
function fakePage(wwebjs) {
  const win = { WWebJS: wwebjs };
  return {
    evaluate(fn, ...args) {
      global.window = win;
      return Promise.resolve(fn(...args)).finally(() => { delete global.window; });
    },
  };
}

/** Stand-in for a WhatsApp Web model: spreading it copies internals. */
function model(fields) {
  return { ...fields, __internalProxyState: { noId: true }, toJSON: () => ({ ...fields }) };
}

test('media sends get plain toJSON() data instead of the live model', async () => {
  const wwebjs = { processMediaData: async () => model({ type: 'image', filehash: 'abc', preview: 'thumb' }) };
  const page = fakePage(wwebjs);

  assert.equal(await ensureMediaPatch(page), 'installed');
  const result = await wwebjs.processMediaData({}, {});

  assert.deepEqual(result, { type: 'image', filehash: 'abc', preview: 'thumb' });
  assert.equal('__internalProxyState' in result, false);
});

test('status and channel media keep the model', async () => {
  const wwebjs = { processMediaData: async () => model({ type: 'image' }) };
  await ensureMediaPatch(fakePage(wwebjs));

  assert.equal(typeof (await wwebjs.processMediaData({}, { sendToStatus: true })).toJSON, 'function');
  assert.equal(typeof (await wwebjs.processMediaData({}, { sendToChannel: true })).toJSON, 'function');
});

test('installing twice wraps only once', async () => {
  let calls = 0;
  const wwebjs = { processMediaData: async () => { calls++; return model({ type: 'image' }); } };
  const page = fakePage(wwebjs);

  await ensureMediaPatch(page);
  assert.equal(await ensureMediaPatch(page), 'already');
  await wwebjs.processMediaData({}, {});

  assert.equal(calls, 1);
});

test('a page without whatsapp-web.js helpers is reported, not patched', async () => {
  assert.equal(await ensureMediaPatch(fakePage(undefined)), 'unavailable');
  assert.equal(await ensureMediaPatch(null), 'unavailable');
});
