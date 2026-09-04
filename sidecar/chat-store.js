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
  const store = window.Store;
  if (!store || !store.Chat || typeof store.Chat.getModelsArray !== 'function') return null;

  const safe = (fn, fallback = null) => {
    try {
      const value = fn();

      return value === undefined ? fallback : value;
    } catch (_) {
      return fallback;
    }
  };

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

    const remote = wid(key.remote);
    if (!remote || !key.id) return null;

    const parts = [key.fromMe ? 'true' : 'false', remote, key.id];
    const participant = wid(key.participant);
    if (participant) parts.push(participant);

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
      deviceType: null,
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

module.exports = { readChatsFromStore, collectChats };
