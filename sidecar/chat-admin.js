/**
 * Request parsing for chat and group admin routes, kept out of index.js so it
 * can be tested without a browser.
 */

function badRequest(message) {
  return Object.assign(new Error(message), { http: 400 });
}

/**
 * Turn the optional `until` body field (unix seconds) into the Date that
 * whatsapp-web.js's muteChat() expects. No value mutes forever.
 *
 * @param {unknown} until
 * @param {number} [now] current time in ms, for tests
 * @returns {Date|undefined}
 */
function unmuteDateFrom(until, now = Date.now()) {
  if (until === undefined || until === null) return undefined;

  if (!Number.isInteger(until) || until * 1000 <= now) {
    throw badRequest('`until` must be a future unix timestamp in seconds');
  }

  return new Date(until * 1000);
}

module.exports = { unmuteDateFrom };
