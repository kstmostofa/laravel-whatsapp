/**
 * Fallback chat listing, read straight from WhatsApp Web's in-page store.
 *
 * `client.getChats()` walks a large surface of minified WhatsApp Web internals
 * and throws opaque errors — `Evaluation failed: r` — whenever WhatsApp
 * reshuffles them (wwebjs/whatsapp-web.js#201845). That takes down the whole
 * endpoint even though we only serialize a handful of fields.
 *
 * `readChatsFromStore()` reads those fields itself, guarding every access, so a
 * chat we can't fully describe degrades to partial data instead of a 500.
 */

/**
 * Runs inside the browser page — must be entirely self-contained (no closure
 * over anything in this module) because Puppeteer stringifies it.
 *
 * Returns null when the store isn't reachable, so the caller can report that
 * distinctly from "this account has no chats".
 *
 * @returns {?Array<object>}
 */
function collectChats() {
  const safe = (fn, fallback = null) => {
    try {
      const value = fn();

      return value === undefined ? fallback : value;
    } catch (_) {
      return fallback;
    }
  };

  // whatsapp-web.js before 1.30-ish exposed collections as `window.Store`.
  // Newer releases stopped doing that and read WhatsApp Web's own module
  // registry instead — `window.require('WAWebCollections')`. Try both.
  const hasChats = (s) => !!(s && s.Chat && typeof s.Chat.getModelsArray === 'function');
  const store = [
    () => window.Store,
    () => (typeof window.require === 'function' ? window.require('WAWebCollections') : null),
  ].map((get) => safe(get)).find(hasChats);

  if (!store) return null;

  // Mirrors serializeWid() in wa-id.js — duplicated because this function is
  // evaluated in the page and can't require() anything.
  const wid = (value) => {
    if (value == null) return null;
    if (typeof value === 'string') return value === '' ? null : value;
    if (typeof value !== 'object') return null;
    if (typeof value._serialized === 'string' && value._serialized) return value._serialized;
    if (value.user && value.server) return `${value.user}@${value.server}`;

    return null;
  };

  // Mirrors serializeMessageId() in wa-id.js.
  const messageId = (key) => {
    if (key == null) return null;
    if (typeof key === 'string') return key === '' ? null : key;
    if (typeof key !== 'object') return null;
    if (typeof key._serialized === 'string' && key._serialized) return key._serialized;

    // The real MsgKey serializes itself (with its `_out`/`_in` suffix).
    const asString = safe(() => String(key));
    if (/^(true|false)_[^@\s_]+@[^@\s_]+_.+/i.test(asString)) return asString;

    const remote = wid(key.remote);
    if (!remote || !key.id) return null;

    const parts = [key.fromMe ? 'true' : 'false', remote, key.id];
    const participant = wid(key.participant);
    if (participant) parts.push(participant);
    if (typeof key.self === 'string' && key.self) parts.push(key.self);

    return parts.join('_');
  };

  const models = (collection) => safe(
    () => (collection && typeof collection.getModelsArray === 'function' ? collection.getModelsArray() : []),
    [],
  );

  // Same shape as serializeMessage() in index.js so both code paths look
  // identical to PHP. `deviceType` has no store-level equivalent.
  const lastMessageOf = (chat) => safe(() => {
    const msgs = models(chat.msgs);
    const m = msgs.length ? msgs[msgs.length - 1] : null;
    if (!m) return null;

    return {
      id: messageId(m.id),
      from: wid(m.from),
      to: wid(m.to),
      body: m.body ?? m.caption ?? null,
      type: m.type ?? null,
      timestamp: m.t ?? null,
      hasMedia: !!(m.mediaData || m.mediaKey),
      isForwarded: !!m.isForwarded,
      isStatus: !!m.isStatusV3,
      isStarred: !!m.star,
      fromMe: !!(m.id && m.id.fromMe),
      author: wid(m.author),
      notifyName: m.notifyName ?? null,
      deviceType: null,
      mimetype: m.mimetype ?? null,
      filename: m.filename ?? null,
      hasQuotedMsg: !!m.quotedMsg,
      ack: typeof m.ack === 'number' ? m.ack : null,
    };
  });

  return models(store.Chat)
    .map((chat) => {
      const id = safe(() => wid(chat.id));
      if (!id) return null;

      const metadata = safe(() => chat.groupMetadata);

      return {
        id,
        name: safe(() => chat.formattedTitle) ?? safe(() => chat.name) ?? null,
        isGroup: safe(() => !!chat.isGroup, false) || id.endsWith('@g.us'),
        unreadCount: safe(() => chat.unreadCount, 0) ?? 0,
        timestamp: safe(() => chat.t),
        description: safe(() => (metadata ? metadata.desc : null)),
        participants: models(metadata && metadata.participants)
          .map((p) => ({
            id: safe(() => wid(p.id)),
            isAdmin: safe(() => !!p.isAdmin, false),
            isSuperAdmin: safe(() => !!p.isSuperAdmin, false),
          }))
          .filter((p) => p.id),
        lastMessage: lastMessageOf(chat),
      };
    })
    .filter(Boolean);
}

/**
 * Runs inside the browser page. Recent messages of one chat, oldest first,
 * in the same shape as serializeMessage() in index.js — or null when the
 * store is unreachable, or `{ notFound: true }` when the chat isn't known.
 *
 * Self-contained for the same reason as collectChats(): Puppeteer
 * stringifies it, so the helpers below are deliberately repeated.
 *
 * @param {string} chatId
 * @param {number} limit
 */
async function collectMessages(chatId, limit) {
  const safe = (fn, fallback = null) => {
    try {
      const value = fn();

      return value === undefined ? fallback : value;
    } catch (_) {
      return fallback;
    }
  };
  const hasChats = (s) => !!(s && s.Chat && typeof s.Chat.getModelsArray === 'function');
  const store = [
    () => window.Store,
    () => (typeof window.require === 'function' ? window.require('WAWebCollections') : null),
  ].map((get) => safe(get)).find(hasChats);

  if (!store) return null;

  const wid = (value) => {
    if (value == null) return null;
    if (typeof value === 'string') return value === '' ? null : value;
    if (typeof value !== 'object') return null;
    if (typeof value._serialized === 'string' && value._serialized) return value._serialized;
    if (value.user && value.server) return `${value.user}@${value.server}`;

    return null;
  };
  const messageId = (key) => {
    if (key == null) return null;
    if (typeof key === 'string') return key === '' ? null : key;
    if (typeof key !== 'object') return null;
    if (typeof key._serialized === 'string' && key._serialized) return key._serialized;
    const asString = safe(() => String(key));
    if (/^(true|false)_[^@\s_]+@[^@\s_]+_.+/i.test(asString)) return asString;
    const remote = wid(key.remote);
    if (!remote || !key.id) return null;
    const parts = [key.fromMe ? 'true' : 'false', remote, key.id];
    const participant = wid(key.participant);
    if (participant) parts.push(participant);
    if (typeof key.self === 'string' && key.self) parts.push(key.self);

    return parts.join('_');
  };
  const models = (collection) => safe(
    () => (collection && typeof collection.getModelsArray === 'function' ? collection.getModelsArray() : []),
    [],
  );

  // Most chats are keyed by WhatsApp's privacy-preserving `@lid` IDs now, so
  // a phone-number ID (`…@c.us`) often isn't a key at all. Resolve it to the
  // LID WhatsApp maps it to — and for your own number, to your own LID, which
  // is where "Message yourself" lives. Lookup only: never create a chat.
  const req = (name) => safe(() => (typeof window.require === 'function' ? window.require(name) : null));
  const candidates = [chatId];
  if (/@c\.us$/.test(chatId)) {
    const widFactory = req('WAWebWidFactory');
    const contacts = req('WAWebApiContact');
    const me = req('WAWebUserPrefsMeUser');
    const lid = safe(() => wid(contacts.getCurrentLid(widFactory.createWid(chatId))));
    if (lid) candidates.push(lid);
    if (safe(() => wid(me.getMaybeMePnUser())) === chatId) {
      const myLid = safe(() => wid(me.getMaybeMeLidUser()));
      if (myLid) candidates.push(myLid);
    }
  }

  const chat = candidates.map((id) => safe(() => store.Chat.get(id))).find(Boolean)
    || models(store.Chat).find((c) => candidates.includes(safe(() => wid(c.id))));

  if (!chat) return { notFound: true };

  const keep = (m) => !safe(() => m.isNotification, false);
  let msgs = models(chat.msgs).filter(keep);

  // Pull older messages from WhatsApp until we have enough, as WhatsApp Web
  // does when you scroll up. Best-effort: stop on the first failure.
  const loader = safe(() => (typeof window.require === 'function' ? window.require('WAWebChatLoadMessages') : null));
  for (let i = 0; msgs.length < limit && loader && typeof loader.loadEarlierMsgs === 'function' && i < 20; i++) {
    let earlier = null;
    try {
      earlier = await loader.loadEarlierMsgs({ chat }) || await loader.loadEarlierMsgs(chat);
    } catch (_) {
      break;
    }
    if (!earlier || !earlier.length) break;
    msgs = [...earlier.filter(keep), ...msgs];
  }

  msgs.sort((a, b) => (safe(() => a.t, 0) || 0) - (safe(() => b.t, 0) || 0));
  if (msgs.length > limit) msgs = msgs.slice(msgs.length - limit);

  return msgs.map((m) => safe(() => {
    const key = m.id;
    const quoted = safe(() => m.quotedMsg);

    return {
      id: messageId(key),
      from: wid(m.from),
      to: wid(m.to),
      body: m.body ?? m.caption ?? null,
      type: m.type ?? null,
      timestamp: m.t ?? null,
      hasMedia: !!(m.mediaData || m.mediaKey || m.directPath),
      isForwarded: !!m.isForwarded,
      isStatus: !!m.isStatusV3,
      isStarred: !!m.star,
      fromMe: !!(key && key.fromMe),
      author: wid(m.author),
      notifyName: m.notifyName ?? null,
      deviceType: null,
      mimetype: m.mimetype ?? null,
      filename: m.filename ?? null,
      hasQuotedMsg: !!quoted,
      quoted: quoted ? {
        stanzaId: safe(() => m.quotedStanzaID),
        body: safe(() => quoted.body ?? quoted.caption),
        type: safe(() => quoted.type),
        from: wid(safe(() => m.quotedParticipant)),
      } : null,
      ack: typeof m.ack === 'number' ? m.ack : null,
    };
  })).filter((m) => m && m.id);
}

/**
 * @param {{ evaluate: Function }} page  a Puppeteer page
 * @returns {Promise<Array<object>>}
 */
async function readMessagesFromStore(page, chatId, limit) {
  if (!page || typeof page.evaluate !== 'function') {
    throw Object.assign(new Error('cannot read messages: the browser page is unavailable'), { http: 503 });
  }

  const result = await page.evaluate(collectMessages, chatId, limit);
  if (result === null) {
    throw Object.assign(new Error('cannot read messages: the WhatsApp Web store is unavailable'), { http: 503 });
  }
  if (!Array.isArray(result) && result.notFound) {
    throw Object.assign(new Error('chat not found'), { http: 404 });
  }

  return result;
}

/**
 * @param {{ evaluate: (fn: Function) => Promise<?Array<object>> }} page  a Puppeteer page
 * @returns {Promise<Array<object>>}
 */
async function readChatsFromStore(page) {
  if (!page || typeof page.evaluate !== 'function') {
    throw Object.assign(new Error('cannot read chats: the browser page is unavailable'), { http: 503 });
  }

  const chats = await page.evaluate(collectChats);
  if (chats === null) {
    throw Object.assign(new Error('cannot read chats: the WhatsApp Web store is unavailable'), { http: 503 });
  }

  return chats;
}

module.exports = { readChatsFromStore, collectChats, readMessagesFromStore, collectMessages };
