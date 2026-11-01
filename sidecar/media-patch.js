/**
 * Makes whatsapp-web.js 1.34's media sends work on current WhatsApp Web.
 *
 * whatsapp-web.js builds the outgoing message as
 * `{ ...mediaData, ...mediaData.toJSON() }`, where `mediaData` is a live
 * WhatsApp Web model. Spreading the model copies its internal fields too, and
 * current builds then fail inside addAndSendMsgToChat with "Data passed to
 * getter must include an id property (it's how we memoize)". The same send
 * built from `toJSON()` alone goes through.
 *
 * ensureMediaPatch() wraps window.WWebJS.processMediaData so it returns the
 * plain `toJSON()` data. Status and Channel media are left alone: those paths
 * hand the result to WhatsApp's media-update helper, which may need the model.
 * Idempotent per page load (whatsapp-web.js re-injects WWebJS on reload).
 */

/** Runs in the page. */
function installMediaPatch() {
  const wwebjs = window.WWebJS;
  if (!wwebjs || typeof wwebjs.processMediaData !== 'function') return 'unavailable';
  if (wwebjs.processMediaData.__laravelWaPatched) return 'already';

  const original = wwebjs.processMediaData;
  const patched = async function (mediaInfo, options = {}) {
    const mediaData = await original.call(this, mediaInfo, options);

    if (options.sendToStatus || options.sendToChannel) return mediaData;
    if (!mediaData || typeof mediaData.toJSON !== 'function') return mediaData;

    return Object.assign({}, mediaData.toJSON());
  };
  patched.__laravelWaPatched = true;
  wwebjs.processMediaData = patched;

  return 'installed';
}

/**
 * @param {{ evaluate: Function }|null} page
 * @returns {Promise<string>}  'installed' | 'already' | 'unavailable'
 */
async function ensureMediaPatch(page) {
  if (!page || typeof page.evaluate !== 'function') return 'unavailable';

  return page.evaluate(installMediaPatch);
}

module.exports = { ensureMediaPatch, installMediaPatch };
