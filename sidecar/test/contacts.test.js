const assert = require('node:assert/strict');
const test = require('node:test');
const { dedupeContacts } = require('../contacts');

test('keeps one entry per id, merging what each duplicate knows', () => {
  const contacts = dedupeContacts([
    { id: '966511111111@c.us', name: null, pushname: 'Ali', number: '966511111111', isMyContact: false },
    { id: '966511111111@c.us', name: 'Ali (Sales)', pushname: null, number: '966511111111', isMyContact: true },
    { id: '966522222222@c.us', name: 'Omar', isMyContact: true },
    { id: null, name: 'no id' },
  ]);

  assert.equal(contacts.length, 2);
  assert.deepEqual(contacts[0], { id: '966511111111@c.us', name: 'Ali (Sales)', pushname: 'Ali', number: '966511111111', isMyContact: true });
});

test('a later empty value never blanks an earlier one', () => {
  const [contact] = dedupeContacts([
    { id: 'x@c.us', name: 'Saved', isBlocked: false },
    { id: 'x@c.us', name: '', isBlocked: false },
  ]);

  assert.equal(contact.name, 'Saved');
});
