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

module.exports = { dedupeContacts };
