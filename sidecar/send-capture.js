/**
 * whatsapp-web.js 1.34's sendMessage() often resolves to `undefined` even
 * though the message was sent — its in-page helper returns nothing on
 * current WhatsApp Web builds. Callers then get no message ID, so they can't
 * quote, react to, edit or delete what they just sent.
 *
 * The message still raises `message_create` with a proper ID. sendAndCapture()
 * listens for it while sending: if the send result has no usable ID, it
 * returns the first outgoing message that matches what was sent.
 *
 * @param {import('events').EventEmitter} client
 * @param {() => Promise<any>} send            performs client.sendMessage(...)
 * @param {(message: any) => boolean} matches  identifies our message among outgoing ones
 * @param {(message: any) => ?string} idOf     serialized ID of a message, or null
 * @param {number} [timeoutMs]
 * @returns {Promise<any>}  the sent message, or the original result if nothing matched in time
 */
async function sendAndCapture(client, send, matches, idOf, timeoutMs = 5000) {
  let capture;
  const captured = new Promise((resolve) => {
    capture = resolve;
  });
  const onCreate = (message) => {
    if (message && message.fromMe && matches(message)) capture(message);
  };

  // Subscribe first: message_create can fire before sendMessage() resolves.
  client.on('message_create', onCreate);
  try {
    const result = await send();
    if (result && idOf(result)) return result;

    let timer;
    const timeout = new Promise((resolve) => {
      timer = setTimeout(() => resolve(result ?? null), timeoutMs);
    });

    try {
      return await Promise.race([captured, timeout]);
    } finally {
      clearTimeout(timer);
    }
  } finally {
    client.removeListener('message_create', onCreate);
  }
}

/** Matcher for a text (or caption) send. */
function bodyMatcher(body) {
  const expected = String(body ?? '');

  return (message) => String(message.body ?? '') === expected;
}

module.exports = { sendAndCapture, bodyMatcher };
