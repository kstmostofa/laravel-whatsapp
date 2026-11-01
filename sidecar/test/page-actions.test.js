const assert = require('node:assert/strict');
const test = require('node:test');
const { runMessageAction } = require('../page-actions');

/**
 * A fake WhatsApp Web page. `modules` stands in for window.require's registry;
 * `calls` records which WhatsApp actions ran.
 */
function fakePage({ messages = {}, chats = {}, capability = {}, lazyForward = false } = {}) {
  const calls = [];
  let forwardLoaded = !lazyForward;
  const modules = {
    WAWebCollections: {
      Msg: { get: (id) => messages[id] || null, getMessagesById: async () => ({ messages: [] }) },
      Chat: { get: (id) => chats[id] || null, find: async (id) => chats[id] || null },
    },
    WAWebMsgActionCapability: capability,
    WAWebSendReactionMsgAction: { sendReactionToMsg: async (msg, emoji) => calls.push(['react', msg.id.id, emoji]) },
    WAWebSendMessageEditAction: { sendMessageEdit: async (msg, body) => calls.push(['edit', msg.id.id, body]) },
    WAWebForwardMessageFlowLoadable: { requireBundle: async () => { forwardLoaded = true; calls.push(['load-forward-bundle']); } },
    WAWebCmd: { Cmd: {
      sendRevokeMsgs: async (chat, { list }) => calls.push(['revoke', list[0].id.id]),
      sendDeleteMsgs: async (chat, { list }) => calls.push(['delete', list[0].id.id]),
    } },
    WAWebDownloadManager: { downloadManager: { downloadAndMaybeDecrypt: async (params) => {
      // Mirrors current WhatsApp Web, which rejects downloads without a matching mimetype.
      if (!params.mimetype) throw new Error(`Unexpected mimetype application/octet-stream for media type ${params.type}`);
      calls.push(['decrypt', params.type, params.mimetype]);
      return new TextEncoder().encode('JPEG').buffer;
    } } },
  };

  const page = {
    calls,
    evaluate(fn, ...args) {
      global.window = {
        require: (name) => {
          if (name === 'WAWebChatForwardMessage') {
            return forwardLoaded ? { forwardMessages: async ({ chat, msgs }) => calls.push(['forward', msgs[0].id.id, chat.id]) } : undefined;
          }
          return modules[name];
        },
      };
      global.btoa = (s) => Buffer.from(s, 'binary').toString('base64');
      // Keep the fake window until the async page function finishes — it
      // calls window.require() again after awaiting (e.g. after loading a bundle).
      return Promise.resolve(fn(...args)).finally(() => {
        delete global.window;
      });
    },
  };

  return page;
}

const mine = (id, extra = {}) => ({ id: { id, fromMe: true, remote: 'me@lid' }, ...extra });

test('finds a message by its full ID and reacts to it', async () => {
  const page = fakePage({ messages: { 'true_me@lid_A_out': mine('A') } });

  await runMessageAction(page, 'react', 'true_me@lid_A_out', { emoji: '👍' });

  assert.deepEqual(page.calls, [['react', 'A', '👍']]);
});

test('IDs stored before the _out/_in suffix still resolve', async () => {
  const page = fakePage({ messages: { 'true_me@lid_A_out': mine('A') } });

  await runMessageAction(page, 'react', 'true_me@lid_A', { emoji: '' });

  assert.deepEqual(page.calls, [['react', 'A', '']]);
});

test('unknown messages are a 404', async () => {
  await assert.rejects(runMessageAction(fakePage(), 'react', 'true_x@lid_Z', { emoji: '👍' }), (e) => e.http === 404);
});

test('edit runs for your own message inside the edit window', async () => {
  const page = fakePage({ messages: { M: mine('M') }, capability: { canEditText: () => true, canEditCaption: () => false } });

  await runMessageAction(page, 'edit', 'M', { body: 'fixed typo' });

  assert.deepEqual(page.calls, [['edit', 'M', 'fixed typo']]);
});

test('edit is refused with 409 once WhatsApp no longer allows it', async () => {
  const page = fakePage({ messages: { M: mine('M') }, capability: { canEditText: () => false, canEditCaption: () => false } });

  await assert.rejects(runMessageAction(page, 'edit', 'M', { body: 'x' }), (e) => e.http === 409 && /can no longer be edited/.test(e.message));
  assert.deepEqual(page.calls, []);
});

test('edit is refused for messages you did not send', async () => {
  const page = fakePage({ messages: { M: { id: { id: 'M', fromMe: false, remote: 'x@lid' } } } });

  await assert.rejects(runMessageAction(page, 'edit', 'M', { body: 'x' }), (e) => e.http === 409);
});

test('forward loads the on-demand forward bundle first when it is not loaded', async () => {
  const page = fakePage({ messages: { M: mine('M') }, chats: { '966511111111@c.us': { id: '966511111111@c.us' } }, lazyForward: true });

  await runMessageAction(page, 'forward', 'M', { to: '966511111111@c.us' });

  assert.deepEqual(page.calls, [['load-forward-bundle'], ['forward', 'M', '966511111111@c.us']]);
});

test('forward to an unknown chat is a 404', async () => {
  const page = fakePage({ messages: { M: mine('M') } });

  await assert.rejects(runMessageAction(page, 'forward', 'M', { to: '966500000000' }), (e) => e.http === 404);
});

test('delete for everyone revokes, and refuses once WhatsApp no longer allows it', async () => {
  const chats = { 'me@lid': { id: 'me@lid' } };
  const allowed = fakePage({ messages: { M: mine('M') }, chats, capability: { canSenderRevokeMsg: () => true } });
  await runMessageAction(allowed, 'delete', 'M', { everyone: true });
  assert.deepEqual(allowed.calls, [['revoke', 'M']]);

  const tooLate = fakePage({ messages: { M: mine('M') }, chats, capability: { canSenderRevokeMsg: () => false, canAdminRevokeMsg: () => false } });
  await assert.rejects(runMessageAction(tooLate, 'delete', 'M', { everyone: true }), (e) => e.http === 409);
  assert.deepEqual(tooLate.calls, []);

  const forMe = fakePage({ messages: { M: mine('M') }, chats });
  await runMessageAction(forMe, 'delete', 'M', { everyone: false });
  assert.deepEqual(forMe.calls, [['delete', 'M']]);
});

test('download returns base64 bytes with the mimetype and filename', async () => {
  const page = fakePage({ messages: { M: mine('M', { type: 'image', mimetype: 'image/jpeg', filename: 'a.jpg', mediaData: { mediaStage: 'RESOLVED' } }) } });

  const media = await runMessageAction(page, 'download', 'M');

  assert.equal(Buffer.from(media.data, 'base64').toString(), 'JPEG');
  assert.equal(media.mimetype, 'image/jpeg');
  assert.equal(media.filename, 'a.jpg');
});

test('download of a message without media is a 404', async () => {
  await assert.rejects(runMessageAction(fakePage({ messages: { M: mine('M') } }), 'download', 'M'), (e) => e.http === 404);
});
