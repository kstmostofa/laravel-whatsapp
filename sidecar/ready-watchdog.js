/**
 * Recovers sessions that whatsapp-web.js leaves stuck at `authenticated`.
 *
 * After authenticating, whatsapp-web.js injects its helpers, reads the
 * account info, attaches its page event listeners and only then emits
 * `ready` — all inside a page callback whose errors are dropped. When one of
 * those steps races or throws (seen on whatsapp-web.js 1.34 after a
 * reconnect), the page is fully connected but `ready` never comes, and every
 * endpoint that needs a ready session refuses.
 *
 * checkStuckSession() finishes the job: once the page reports a connected
 * socket and injected helpers, it attaches the listeners, fills in
 * `client.info`, and emits `ready` on the client.
 *
 * Stuck at `authenticated` means attachEventListeners() never completed: it
 * registers the in-page handlers (`Msg.on('add', …)`) as its last step and
 * `ready` is emitted right after it. So the listeners are attached here
 * unconditionally — but only once: a page marker stops a second attempt,
 * which would deliver every message twice.
 */

const MARKER = '__laravelWaListenersAttached';

/** Runs in the page. */
function pageReadiness() {
  const socketState = (() => {
    try {
      return window.require('WAWebSocketModel').Socket.state;
    } catch (_) {
      return null;
    }
  })();

  return {
    injected: typeof window.WWebJS !== 'undefined',
    connected: socketState === 'CONNECTED',
    attachedByWatchdog: window.__laravelWaListenersAttached === true,
  };
}

/** Runs in the page. */
function markAttached() {
  window.__laravelWaListenersAttached = true;
}

/** Runs in the page: the same payload whatsapp-web.js builds ClientInfo from. */
function pageClientInfo() {
  const me = window.require('WAWebUserPrefsMeUser');

  return {
    ...window.require('WAWebConnModel').Conn.serialize(),
    wid: me.getMaybeMePnUser() || me.getMaybeMeLidUser(),
  };
}

/**
 * @param {{ status: string, client: object }} session
 * @param {{ createInfo?: (client: object, payload: object) => object, log?: (msg: string) => void }} [deps]
 * @returns {Promise<'not-stuck'|'not-connected'|'recovered'>}
 */
async function checkStuckSession(session, deps = {}) {
  if (session.status !== 'authenticated') return 'not-stuck';

  const { client } = session;
  const page = client.pupPage;
  if (!page) return 'not-connected';

  const state = await page.evaluate(pageReadiness);
  if (!state.injected || !state.connected) return 'not-connected';

  if (!state.attachedByWatchdog) {
    await client.attachEventListeners();
    await page.evaluate(markAttached);
  }

  if (!client.info && deps.createInfo) {
    try {
      client.info = deps.createInfo(client, await page.evaluate(pageClientInfo));
    } catch (_) {
      // ready without info beats not ready at all
    }
  }

  // Something else may have finished the job while we were checking.
  if (session.status !== 'authenticated') return 'not-stuck';

  if (deps.log) {
    deps.log('whatsapp-web.js never emitted ready, but the page is connected; marking the session ready');
  }
  client.emit('ready');

  return 'recovered';
}

/** whatsapp-web.js's ClientInfo, when the installed version still ships it at this path. */
function defaultCreateInfo() {
  try {
    const ClientInfo = require('whatsapp-web.js/src/structures/ClientInfo');

    return (client, payload) => new ClientInfo(client, payload);
  } catch (_) {
    return undefined;
  }
}

module.exports = { checkStuckSession, pageReadiness, defaultCreateInfo, MARKER };
