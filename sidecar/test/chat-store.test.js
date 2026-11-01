const assert = require('node:assert/strict');
const test = require('node:test');
const { readChatsFromStore, readMessagesFromStore } = require('../chat-store');

function collection(models) {
  return { getModelsArray: () => models };
}

/** A Puppeteer page stand-in: runs the page function against a fake `window`. */
function fakePage(store, { viaRequire = false } = {}) {
  return {
    evaluate(fn) {
      // Older whatsapp-web.js exposed `window.Store`; 1.30+ only has
      // WhatsApp Web's module registry, reached through `window.require`.
      global.window = viaRequire
        ? { require: (name) => (name === 'WAWebCollections' ? store : undefined) }
        : { Store: store };
      try {
        return Promise.resolve(fn());
      } finally {
        delete global.window;
      }
    },
  };
}

function directChat() {
  return {
    id: { user: '9665XXXXXXXX', server: 'c.us' },
    formattedTitle: 'Munir',
    isGroup: false,
    unreadCount: 2,
    t: 1750000000,
    msgs: collection([
      {
        id: { fromMe: false, remote: '9665XXXXXXXX@c.us', id: '3EB0ABCD' },
        from: '9665XXXXXXXX@c.us',
        to: '9665YYYYYYYY@c.us',
        body: 'hello',
        type: 'chat',
        t: 1750000000,
      },
    ]),
  };
}

function groupChat() {
  return {
    id: { _serialized: '120363XXXXXXXX@g.us' },
    formattedTitle: 'Project X',
    isGroup: true,
    unreadCount: 0,
    t: 1750000100,
    groupMetadata: {
      desc: 'Ship it',
      participants: collection([
        { id: { _serialized: '9665XXXXXXXX@c.us' }, isAdmin: true, isSuperAdmin: false },
        { id: { user: '9665YYYYYYYY', server: 'c.us' }, isAdmin: false, isSuperAdmin: false },
      ]),
    },
    msgs: collection([]),
  };
}

test('reads chats straight from the in-page store', async () => {
  const chats = await readChatsFromStore(fakePage({ Chat: collection([directChat()]) }));

  assert.deepEqual(chats, [{
    id: '9665XXXXXXXX@c.us',
    name: 'Munir',
    isGroup: false,
    unreadCount: 2,
    timestamp: 1750000000,
    description: null,
    participants: [],
    lastMessage: {
      id: 'false_9665XXXXXXXX@c.us_3EB0ABCD',
      from: '9665XXXXXXXX@c.us',
      to: '9665YYYYYYYY@c.us',
      body: 'hello',
      type: 'chat',
      timestamp: 1750000000,
      hasMedia: false,
      isForwarded: false,
      isStatus: false,
      isStarred: false,
      fromMe: false,
      author: null,
      notifyName: null,
      deviceType: null,
      mimetype: null,
      filename: null,
      hasQuotedMsg: false,
      ack: null,
    },
  }]);
});

test('reads group description and participants from the group metadata', async () => {
  const [group] = await readChatsFromStore(fakePage({ Chat: collection([groupChat()]) }));

  assert.equal(group.id, '120363XXXXXXXX@g.us');
  assert.equal(group.isGroup, true);
  assert.equal(group.description, 'Ship it');
  assert.deepEqual(group.participants, [
    { id: '9665XXXXXXXX@c.us', isAdmin: true, isSuperAdmin: false },
    { id: '9665YYYYYYYY@c.us', isAdmin: false, isSuperAdmin: false },
  ]);
  assert.equal(group.lastMessage, null);
});

test('a chat with throwing internals degrades instead of failing the listing', async () => {
  const broken = {
    id: { _serialized: '9665ZZZZZZZZ@c.us' },
    get formattedTitle() { throw new Error('r'); },
    get isGroup() { throw new Error('r'); },
    get unreadCount() { throw new Error('r'); },
    get groupMetadata() { throw new Error('r'); },
    get msgs() { throw new Error('r'); },
  };

  const chats = await readChatsFromStore(fakePage({ Chat: collection([broken, directChat()]) }));

  assert.equal(chats.length, 2);
  assert.deepEqual(chats[0], {
    id: '9665ZZZZZZZZ@c.us',
    name: null,
    isGroup: false,
    unreadCount: 0,
    timestamp: null,
    description: null,
    participants: [],
    lastMessage: null,
  });
});

test('skips chats whose id cannot be resolved', async () => {
  const chats = await readChatsFromStore(fakePage({ Chat: collection([{ id: {}, msgs: collection([]) }]) }));

  assert.deepEqual(chats, []);
});

test('reports an unreachable store rather than returning an empty list', async () => {
  await assert.rejects(
    () => readChatsFromStore(fakePage({})),
    (e) => e.http === 503 && /store is unavailable/.test(e.message),
  );
});

test('reports a missing browser page', async () => {
  await assert.rejects(
    () => readChatsFromStore(null),
    (e) => e.http === 503 && /browser page is unavailable/.test(e.message),
  );
});

test('reads chats through WAWebCollections when window.Store is gone (whatsapp-web.js 1.30+)', async () => {
  const chats = await readChatsFromStore(fakePage({ Chat: collection([directChat(), groupChat()]) }, { viaRequire: true }));

  assert.deepEqual(chats.map((c) => c.id), ['9665XXXXXXXX@c.us', '120363XXXXXXXX@g.us']);
  assert.equal(chats[1].participants.length, 2);
});

test('falls back to WAWebCollections when window.Store exists but has no chats', async () => {
  const page = {
    evaluate(fn) {
      global.window = {
        Store: { Chat: {} },
        require: (name) => (name === 'WAWebCollections' ? { Chat: collection([directChat()]) } : undefined),
      };
      try {
        return Promise.resolve(fn());
      } finally {
        delete global.window;
      }
    },
  };

  assert.deepEqual((await readChatsFromStore(page)).map((c) => c.id), ['9665XXXXXXXX@c.us']);
});

test('a throwing module registry still reports the store as unavailable', async () => {
  const page = {
    evaluate(fn) {
      global.window = { require: () => { throw new Error('module not found'); } };
      try {
        return Promise.resolve(fn());
      } finally {
        delete global.window;
      }
    },
  };

  await assert.rejects(readChatsFromStore(page), (e) => e.http === 503 && /store is unavailable/.test(e.message));
});

function storeWithChats(chats, extraModules = {}) {
  const byId = new Map(chats.map((c) => [c.id._serialized || `${c.id.user}@${c.id.server}`, c]));
  const Chat = { getModelsArray: () => chats, get: (id) => byId.get(id) };

  return {
    evaluate(fn, ...args) {
      global.window = { require: (name) => (name === 'WAWebCollections' ? { Chat } : extraModules[name]) };
      try {
        return Promise.resolve(fn(...args));
      } finally {
        delete global.window;
      }
    },
  };
}

function chatWithMessages(id, count) {
  return {
    id: { _serialized: id },
    msgs: collection(Array.from({ length: count }, (_, i) => ({
      id: { fromMe: i % 2 === 0, remote: id, id: `M${i}` },
      from: id,
      to: '966500000000@c.us',
      body: `message ${i}`,
      type: 'chat',
      t: 1750000000 + i,
      ack: 2,
    }))),
  };
}

test('reads a chat\'s recent messages, oldest first, capped at the limit', async () => {
  const messages = await readMessagesFromStore(storeWithChats([chatWithMessages('966511111111@c.us', 5)]), '966511111111@c.us', 3);

  assert.deepEqual(messages.map((m) => m.body), ['message 2', 'message 3', 'message 4']);
  assert.equal(messages[0].id, 'true_966511111111@c.us_M2');
  assert.equal(messages[0].fromMe, true);
  assert.equal(messages[0].ack, 2);
});

test('resolves a phone-number id to the chat\'s @lid key', async () => {
  const page = storeWithChats([chatWithMessages('111222333@lid', 2)], {
    WAWebWidFactory: { createWid: (id) => ({ _serialized: id }) },
    WAWebApiContact: { getCurrentLid: (w) => (w._serialized === '966511111111@c.us' ? { _serialized: '111222333@lid' } : null) },
  });

  assert.equal((await readMessagesFromStore(page, '966511111111@c.us', 10)).length, 2);
});

test('your own number resolves to your own @lid ("Message yourself")', async () => {
  const page = storeWithChats([chatWithMessages('999888777@lid', 1)], {
    WAWebWidFactory: { createWid: (id) => ({ _serialized: id }) },
    WAWebApiContact: { getCurrentLid: () => null },
    WAWebUserPrefsMeUser: {
      getMaybeMePnUser: () => ({ _serialized: '966500000000@c.us' }),
      getMaybeMeLidUser: () => ({ _serialized: '999888777@lid' }),
    },
  });

  assert.equal((await readMessagesFromStore(page, '966500000000@c.us', 10)).length, 1);
});

test('an unknown chat is a 404, not an empty list', async () => {
  await assert.rejects(
    readMessagesFromStore(storeWithChats([]), '966511111111@c.us', 10),
    (e) => e.http === 404 && e.message === 'chat not found',
  );
});
