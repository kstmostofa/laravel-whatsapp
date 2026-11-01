const assert = require('node:assert/strict');
const { EventEmitter } = require('node:events');
const test = require('node:test');
const { checkStuckSession } = require('../ready-watchdog');

/** A client stand-in whose page answers evaluate() from a fake window. */
function fakeSession({ status = 'authenticated', windowState = {}, info = null } = {}) {
  // Persists across evaluate() calls, like a real page's window.
  const pageWindow = windowState.attachedByWatchdog ? { __laravelWaListenersAttached: true } : {};
  const client = new EventEmitter();
  client.info = info;
  client.attachCalls = 0;
  client.attachEventListeners = async () => {
    client.attachCalls++;
  };
  client.pupPage = {
    evaluate(fn) {
      global.window = Object.assign(pageWindow, {
        WWebJS: windowState.injected === false ? undefined : {},
        require: (name) => {
          if (name === 'WAWebSocketModel') return { Socket: { state: windowState.socket ?? 'CONNECTED' } };
          if (name === 'WAWebConnModel') return { Conn: { serialize: () => ({ pushname: 'Me', platform: 'android' }) } };
          if (name === 'WAWebUserPrefsMeUser') return { getMaybeMePnUser: () => ({ _serialized: '966500000000@c.us' }), getMaybeMeLidUser: () => null };
          throw new Error(`no module ${name}`);
        },
      });
      try {
        return Promise.resolve(fn());
      } finally {
        delete global.window;
      }
    },
  };

  const session = { status, client };
  client.on('ready', () => {
    session.status = 'ready';
  });

  return session;
}

test('a connected session stuck at authenticated is marked ready', async () => {
  const session = fakeSession();
  const logs = [];

  const result = await checkStuckSession(session, {
    createInfo: (client, payload) => ({ wid: payload.wid._serialized, pushname: payload.pushname }),
    log: (m) => logs.push(m),
  });

  assert.equal(result, 'recovered');
  assert.equal(session.status, 'ready');
  assert.equal(session.client.attachCalls, 1);
  assert.deepEqual(session.client.info, { wid: '966500000000@c.us', pushname: 'Me' });
  assert.equal(logs.length, 1);
});

test('the watchdog never attaches listeners twice', async () => {
  const session = fakeSession({ windowState: { attachedByWatchdog: true }, info: { existing: true } });

  assert.equal(await checkStuckSession(session), 'recovered');
  assert.equal(session.client.attachCalls, 0);
  assert.deepEqual(session.client.info, { existing: true });
});

test('a second check after recovery does not attach again', async () => {
  const session = fakeSession();
  await checkStuckSession(session);

  session.status = 'authenticated'; // pretend it got stuck again
  await checkStuckSession(session);

  assert.equal(session.client.attachCalls, 1);
});

test('a page that is not connected yet is left alone', async () => {
  for (const windowState of [{ socket: 'OPENING' }, { injected: false }]) {
    const session = fakeSession({ windowState });

    assert.equal(await checkStuckSession(session), 'not-connected');
    assert.equal(session.status, 'authenticated');
    assert.equal(session.client.attachCalls, 0);
  }
});

test('sessions that are not stuck are ignored', async () => {
  for (const status of ['ready', 'qr', 'initializing', 'disconnected']) {
    const session = fakeSession({ status });

    assert.equal(await checkStuckSession(session), 'not-stuck');
    assert.equal(session.client.attachCalls, 0);
  }
});
