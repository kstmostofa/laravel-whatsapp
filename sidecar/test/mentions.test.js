const assert = require('node:assert/strict');
const test = require('node:test');
const { mentionOptions, serializeMentions } = require('../mentions');

test('no mentions means no extra send options', () => {
  assert.deepEqual(mentionOptions({}), {});
  assert.deepEqual(mentionOptions({ mentions: [], groupMentions: [] }), {});
});

test('user mentions accept phone numbers and WhatsApp IDs', () => {
  assert.deepEqual(mentionOptions({ mentions: ['+966 51 111 1111', '966522222222@c.us'] }), {
    mentions: ['966511111111@c.us', '966522222222@c.us'],
  });
});

test('group mentions keep only subject and id', () => {
  assert.deepEqual(mentionOptions({ groupMentions: [{ subject: 'Team', id: '1203630000@g.us', extra: 1 }] }), {
    groupMentions: [{ subject: 'Team', id: '1203630000@g.us' }],
  });
});

test('received mentions serialize WID objects and default to empty lists', () => {
  assert.deepEqual(serializeMentions({}), { mentionedIds: [], groupMentions: [] });
  assert.deepEqual(
    serializeMentions({
      mentionedIds: ['966511111111@c.us', { user: '966522222222', server: 'c.us' }],
      groupMentions: [{ groupSubject: 'Team', groupJid: { _serialized: '1203630000@g.us' } }],
    }),
    {
      mentionedIds: ['966511111111@c.us', '966522222222@c.us'],
      groupMentions: [{ subject: 'Team', id: '1203630000@g.us' }],
    },
  );
});
