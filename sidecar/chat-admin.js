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

// Invite codes are base62; WhatsApp issues 22 characters but we don't rely on the length.
const INVITE_CODE = /^[A-Za-z0-9]+$/;
const INVITE_LINK = /^(?:https?:\/\/)?chat\.whatsapp\.com\/(?:invite\/)?([A-Za-z0-9]+)\/?(?:[?#].*)?$/i;

/**
 * Accept a bare invite code or a chat.whatsapp.com link and return the code.
 *
 * @param {unknown} invite
 * @returns {string}
 */
function parseInviteCode(invite) {
  const value = typeof invite === 'string' ? invite.trim() : '';
  if (INVITE_CODE.test(value)) return value;

  const match = value.match(INVITE_LINK);
  if (match) return match[1];

  throw badRequest('`invite` must be an invite code or a https://chat.whatsapp.com/ link');
}

module.exports = { unmuteDateFrom, parseInviteCode };
