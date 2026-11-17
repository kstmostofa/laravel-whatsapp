const { normalizeWaId, serializeWid } = require('./wa-id');

/**
 * sendMessage() options that tag people and groups in a text.
 *
 * WhatsApp only links a mention when the body also contains it:
 * `@9665XXXXXXXX` for each user in `mentions` and `@<subject>` for each
 * group in `groupMentions`.
 *
 * @param {{mentions?: any, groupMentions?: any}} body  the send request body
 * @returns {{mentions?: string[], groupMentions?: Array<{subject: string, id: string}>}}
 */
function mentionOptions({ mentions, groupMentions }) {
  const options = {};
  if (Array.isArray(mentions) && mentions.length > 0) {
    options.mentions = mentions.map(normalizeWaId);
  }
  if (Array.isArray(groupMentions) && groupMentions.length > 0) {
    options.groupMentions = groupMentions.map(({ subject, id }) => ({ subject, id }));
  }

  return options;
}

/**
 * Mentions on a received message, in the same shape mentionOptions() takes.
 * whatsapp-web.js may hand over WIDs as objects instead of strings.
 *
 * @param {{mentionedIds?: any[], groupMentions?: any[]}} message
 * @returns {{mentionedIds: string[], groupMentions: Array<{subject: ?string, id: ?string}>}}
 */
function serializeMentions(message) {
  return {
    mentionedIds: (Array.isArray(message.mentionedIds) ? message.mentionedIds : []).map(serializeWid).filter(Boolean),
    groupMentions: (Array.isArray(message.groupMentions) ? message.groupMentions : []).map((g) => ({
      subject: g?.groupSubject ?? null,
      id: serializeWid(g?.groupJid),
    })),
  };
}

module.exports = { mentionOptions, serializeMentions };
