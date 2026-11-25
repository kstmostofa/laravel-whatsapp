/**
 * whatsapp-web.js's getContacts() returns the same contact several times
 * (one entry per underlying store record), so a 650-contact account can come
 * back as 1300+ entries. Keep one per ID, filling gaps from the duplicates
 * and preferring the entry that knows the most (a saved name, "my contact").
 *
 * @param {Array<{id: ?string, name?: ?string, pushname?: ?string, number?: ?string, [key: string]: any}>} contacts
 * @returns {Array<object>}
 */
function dedupeContacts(contacts) {
  const byId = new Map();

  for (const contact of contacts) {
    if (!contact || !contact.id) continue;

    const existing = byId.get(contact.id);
    if (!existing) {
      byId.set(contact.id, { ...contact });
      continue;
    }

    for (const [key, value] of Object.entries(contact)) {
      if (value === true || ((existing[key] === undefined || existing[key] === null || existing[key] === '') && value != null && value !== '')) {
        existing[key] = value;
      }
    }
  }

  return [...byId.values()];
}

const { normalizeWaId } = require('./wa-id');

/**
 * Contact IDs for a contact card send (`type: 'contacts'`), normalized to
 * WhatsApp IDs. Phone numbers are accepted, like recipients.
 *
 * @param {{contactIds?: any}} body  the send request body
 * @returns {string[]}
 */
function contactCardIds({ contactIds }) {
  if (!Array.isArray(contactIds) || contactIds.length === 0 || !contactIds.every((id) => typeof id === 'string' && id !== '')) {
    throw Object.assign(new Error('contacts requires a non-empty `contactIds` array of strings'), { http: 400 });
  }

  return contactIds.map(normalizeWaId);
}

module.exports = { dedupeContacts, contactCardIds };
