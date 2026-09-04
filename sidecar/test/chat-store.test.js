const assert = require('node:assert/strict');
const test = require('node:test');
const { readChatsFromStore } = require('../chat-store');

function collection(models) {
  return { getModelsArray: () => models };
}

/** A Puppeteer page stand-in: runs the page function against a fake `window`. */
function fakePage(store) {
  return {
    evaluate(fn) {
      global.window = { Store: store };
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
      deviceType: null,
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
