/**
 * Makes `message_edit` events fire on current WhatsApp Web.
 *
 * whatsapp-web.js listens with `Msg.on('change:body change:caption', …)`.
 * That space-separated form is Backbone syntax; WhatsApp Web's own event
 * emitter doesn't split it, so the handler waits for an event literally
 * named "change:body change:caption" and edits are never reported — even
 * though `change:body` itself does fire.
 *
 * installEditHook() registers the two events separately and feeds them into
 * whatsapp-web.js's existing `onEditMessageEvent` pipeline, so the client
 * emits `message_edit` as designed. Once per page load.
 */

/** Runs in the page. */
function installEditHook() {
  if (window.__laravelWaEditHook) return 'already';

  const req = (name) => {
    try {
      return window.require(name);
    } catch (_) {
      return undefined;
    }
  };
  const collections = req('WAWebCollections');
  if (!collections || !collections.Msg || typeof window.onEditMessageEvent !== 'function' || !window.WWebJS) {
    return 'unavailable';
  }

  const report = (attribute) => (msg, newValue) => {
    try {
      if (!msg || msg.type === 'revoked') return;
      const previous = typeof msg.previous === 'function' ? msg.previous(attribute) : null;
      window.onEditMessageEvent(window.WWebJS.getMessageModel(msg), newValue, previous);
    } catch (_) {
      // never break WhatsApp Web's own change handling
    }
  };

  collections.Msg.on('change:body', report('body'));
  collections.Msg.on('change:caption', report('caption'));
  window.__laravelWaEditHook = true;

  return 'installed';
}

/**
 * @param {{ evaluate: Function }|null} page
 * @returns {Promise<string>}
 */
async function ensureEditHook(page) {
  if (!page || typeof page.evaluate !== 'function') return 'unavailable';

  return page.evaluate(installEditHook);
}

/**
 * Drops a repeat of the same edit seen within `windowMs` — so a future
 * whatsapp-web.js that fixes its own listener doesn't report every edit twice.
 *
 * @returns {(messageId: ?string, body: ?string) => boolean}  true when the edit is new
 */
function editDeduper(windowMs = 5000, now = () => Date.now()) {
  const seen = new Map();

  return (messageId, body) => {
    const key = `${messageId}\u0000${body}`;
    const t = now();
    for (const [k, at] of seen) if (t - at > windowMs) seen.delete(k);
    if (seen.has(key)) return false;
    seen.set(key, t);

    return true;
  };
}

module.exports = { ensureEditHook, installEditHook, editDeduper };
