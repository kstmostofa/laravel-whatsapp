/**
 * WhatsApp ID serialization helpers.
 *
 * whatsapp-web.js hands us IDs as objects carrying a `_serialized` string —
 * `9665XXXXXXXX@c.us` for chats/contacts, `false_9665XXXXXXXX@c.us_3EB0…` for
 * messages. Recent WhatsApp Web builds ship those classes minified, so
 * `_serialized` is sometimes absent and the value lands under a mangled key
 * such as `$1`. Reading `id._serialized` blindly then yields `null` for every
 * message.
 *
 * These helpers rebuild the canonical string from the ID's own parts instead
 * of trusting a single property name, and only fall back to sniffing the
 * object's string values when the parts aren't there either.
 */

// `user@server` — e.g. `9665XXXXXXXX@c.us`, `1203…@g.us`, `1234…@lid`.
const WID_PATTERN = /^[^@\s_]+@[^@\s_]+$/;

// `fromMe_remote_id` with an optional `_participant` tail.
const MESSAGE_ID_PATTERN = /^(true|false)_[^@\s_]+@[^@\s_]+_.+/i;

function isNonEmptyString(value) {
  return typeof value === 'string' && value !== '';
}

/**
 * Last resort: the object kept its serialized string but under a minified key.
 * Pick the first own value that looks like the ID shape we're after.
 */
function findSerialized(id, pattern) {
  for (const value of Object.values(id)) {
    if (isNonEmptyString(value) && pattern.test(value)) return value;
  }

  return null;
}

/**
 * Serialize a chat / contact / participant ID to `user@server`.
 *
 * @param {unknown} id
 * @returns {?string}
 */
function serializeWid(id) {
  if (id == null) return null;
  if (typeof id === 'string') return id === '' ? null : id;
  if (typeof id !== 'object') return null;

  if (isNonEmptyString(id._serialized)) return id._serialized;
  if (isNonEmptyString(id.user) && isNonEmptyString(id.server)) return `${id.user}@${id.server}`;

  return findSerialized(id, WID_PATTERN);
}

/**
 * Serialize a message key to `fromMe_remote_id[_participant]` — the form
 * `client.getMessageById()` expects back.
 *
 * @param {unknown} id
 * @returns {?string}
 */
function serializeMessageId(id) {
  if (id == null) return null;
  if (typeof id === 'string') return id === '' ? null : id;
  if (typeof id !== 'object') return null;

  if (isNonEmptyString(id._serialized)) return id._serialized;

  const remote = serializeWid(id.remote);
  if (isNonEmptyString(remote) && isNonEmptyString(id.id)) {
    const parts = [id.fromMe ? 'true' : 'false', remote, id.id];
    const participant = serializeWid(id.participant);
    if (participant) parts.push(participant);

    return parts.join('_');
  }

  return findSerialized(id, MESSAGE_ID_PATTERN);
}

module.exports = { serializeWid, serializeMessageId };
