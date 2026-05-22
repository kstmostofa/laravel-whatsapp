/**
 * laravel-wa sidecar
 *
 * Tiny HTTP service wrapping whatsapp-web.js. Managed by the Laravel package
 * via Artisan commands (`php artisan whatsapp:sidecar:*`). Not intended to be
 * run by hand in production.
 *
 * All endpoints expect a Bearer token matching SIDECAR_TOKEN. PHP sets this on
 * every request and the same value is shared via the laravel-wa config.
 */

const express = require('express');
const qrcode = require('qrcode');
const path = require('path');
const fs = require('fs');
const { Client, LocalAuth, MessageMedia, Location } = require('whatsapp-web.js');

const PORT = parseInt(process.env.PORT || '3000', 10);
const HOST = process.env.HOST || '127.0.0.1';
const TOKEN = process.env.SIDECAR_TOKEN || '';
const SESSION_DIR = process.env.SESSION_DIR || path.join(__dirname, 'sessions');
const PID_FILE = process.env.SIDECAR_PID_FILE || '';

if (!fs.existsSync(SESSION_DIR)) fs.mkdirSync(SESSION_DIR, { recursive: true });

// Write our true PID overwriting whatever the launcher captured. macOS `nohup`
// forks rather than execs, so the shell's $! is the wrapper, not us — without
// this, `whatsapp:sidecar:stop` would kill the wrapper and leave us orphaned.
if (PID_FILE) {
  try {
    fs.mkdirSync(path.dirname(PID_FILE), { recursive: true });
    fs.writeFileSync(PID_FILE, String(process.pid));
  } catch (e) {
    console.error(`[laravel-wa-sidecar] failed to write PID file ${PID_FILE}: ${e.message}`);
  }
}

/** sessionId → { client, status, qrDataUri, subscribers: Set<res> } */
const sessions = new Map();

function auth(req, res, next) {
  if (!TOKEN) return next();
  const header = req.headers.authorization || '';
  if (header !== `Bearer ${TOKEN}`) {
    return res.status(401).json({ error: 'unauthorized' });
  }
  next();
}

function getSession(sessionId) {
  const session = sessions.get(sessionId);
  if (!session) throw Object.assign(new Error('session not found'), { http: 404 });
  return session;
}

function requireReady(session) {
  if (session.status !== 'ready') {
    throw Object.assign(new Error(`session not ready (status: ${session.status})`), { http: 409 });
  }
}

function broadcast(sessionId, event, data) {
  const session = sessions.get(sessionId);
  if (!session) return;
  const line = `event: ${event}\ndata: ${JSON.stringify({ sessionId, ...data })}\n\n`;
  for (const res of session.subscribers) {
    try { res.write(line); } catch (_) { /* subscriber gone */ }
  }
}

function serializeMessage(m) {
  if (!m) return null;
  return {
    id: m.id?._serialized ?? null,
    from: m.from,
    to: m.to,
    body: m.body,
    type: m.type,
    timestamp: m.timestamp,
    hasMedia: m.hasMedia,
    isForwarded: m.isForwarded,
    isStatus: m.isStatus,
    isStarred: m.isStarred,
    fromMe: m.fromMe,
    author: m.author,
    deviceType: m.deviceType,
  };
}

async function bootSession(sessionId) {
  const existing = sessions.get(sessionId);
  if (existing) return existing;

  const client = new Client({
    authStrategy: new LocalAuth({ clientId: sessionId, dataPath: SESSION_DIR }),
    puppeteer: {
      headless: true,
      args: ['--no-sandbox', '--disable-setuid-sandbox', '--disable-dev-shm-usage'],
    },
  });

  const session = { client, status: 'initializing', qrDataUri: null, subscribers: new Set() };
  sessions.set(sessionId, session);

  client.on('qr', async (qr) => {
    session.qrDataUri = await qrcode.toDataURL(qr);
    session.status = 'qr';
    broadcast(sessionId, 'qr', { dataUri: session.qrDataUri });
  });

  client.on('authenticated', () => {
    session.status = 'authenticated';
    broadcast(sessionId, 'authenticated', {});
  });

  client.on('auth_failure', (msg) => {
    session.status = 'auth_failure';
    broadcast(sessionId, 'auth_failure', { message: msg });
  });

  client.on('ready', () => {
    session.status = 'ready';
    broadcast(sessionId, 'ready', {});
  });

  client.on('disconnected', (reason) => {
    session.status = 'disconnected';
    broadcast(sessionId, 'disconnected', { reason });
  });

  client.on('message', (m) => broadcast(sessionId, 'message', { message: serializeMessage(m) }));
  client.on('message_create', (m) => broadcast(sessionId, 'message_create', { message: serializeMessage(m) }));
  client.on('message_ack', (m, ack) => broadcast(sessionId, 'message_ack', { id: m.id?._serialized, ack }));
  client.on('message_revoke_everyone', (after, before) => broadcast(sessionId, 'message_revoke', {
    after: serializeMessage(after),
    before: serializeMessage(before),
  }));
  client.on('group_join', (n) => broadcast(sessionId, 'group_join', n));
  client.on('group_leave', (n) => broadcast(sessionId, 'group_leave', n));
  client.on('group_update', (n) => broadcast(sessionId, 'group_update', n));

  // Don't await — initialize() resolves only after 'ready'. We want
  // /start to return immediately so the caller can poll for QR.
  client.initialize().catch((e) => {
    session.status = 'error';
    broadcast(sessionId, 'error', { message: e.message });
  });

  return session;
}

// Normalize a recipient to whatsapp-web.js's expected Chat ID format.
//   `9665XXXXXXXX@c.us`  → returned as-is (already a WA ID)
//   `+9665XXXXXXXX`      → stripped to digits + `@c.us`
//   `9665XXXXXXXX`       → digits + `@c.us`
//   `…@g.us`, `…@lid`    → returned as-is
// Empty / falsy → returned as-is so whatsapp-web.js fails loudly.
function normalizeWaId(input) {
  if (!input || typeof input !== 'string') return input;
  if (input.includes('@')) return input;
  const digits = input.replace(/\D+/g, '');
  if (!digits) return input;
  return `${digits}@c.us`;
}

async function buildOutgoingMedia({ url, base64, mimeType, filename }) {
  if (url) return await MessageMedia.fromUrl(url, { unsafeMime: true });
  if (base64) return new MessageMedia(mimeType || 'application/octet-stream', base64, filename);
  throw Object.assign(new Error('media requires `url` or `base64`'), { http: 400 });
}

const app = express();
app.use(express.json({ limit: '50mb' }));
app.use(auth);

app.get('/health', (_, res) => {
  res.json({ ok: true, sessions: sessions.size, uptime: process.uptime() });
});

app.get('/sessions', (_, res) => {
  res.json([...sessions.entries()].map(([id, s]) => ({ id, status: s.status })));
});

app.post('/sessions/:id/start', async (req, res, next) => {
  try {
    const s = await bootSession(req.params.id);
    res.json({ id: req.params.id, status: s.status, qr: s.qrDataUri });
  } catch (e) { next(e); }
});

app.post('/sessions/:id/stop', async (req, res, next) => {
  try {
    const s = getSession(req.params.id);
    try { await s.client.destroy(); } catch (_) { /* already gone */ }
    sessions.delete(req.params.id);
    res.json({ ok: true });
  } catch (e) { next(e); }
});

app.delete('/sessions/:id', async (req, res, next) => {
  try {
    const s = sessions.get(req.params.id);
    if (s) { try { await s.client.destroy(); } catch (_) {} sessions.delete(req.params.id); }
    // Also wipe persisted auth so next start triggers a fresh QR.
    const authDir = path.join(SESSION_DIR, `session-${req.params.id}`);
    if (fs.existsSync(authDir)) fs.rmSync(authDir, { recursive: true, force: true });
    res.json({ ok: true });
  } catch (e) { next(e); }
});

app.get('/sessions/:id/qr', (req, res, next) => {
  try {
    const s = getSession(req.params.id);
    res.json({ status: s.status, qr: s.qrDataUri });
  } catch (e) { next(e); }
});

app.get('/sessions/:id/status', (req, res, next) => {
  try {
    const s = getSession(req.params.id);
    res.json({ id: req.params.id, status: s.status });
  } catch (e) { next(e); }
});

app.get('/sessions/:id/info', async (req, res, next) => {
  try {
    const s = getSession(req.params.id);
    requireReady(s);
    res.json({ id: req.params.id, info: s.client.info });
  } catch (e) { next(e); }
});

/**
 * Polymorphic send endpoint.
 * Body: { type: 'text'|'image'|'video'|'audio'|'document'|'sticker'|'location'|'reaction'|'reply', ... }
 */
app.post('/sessions/:id/messages', async (req, res, next) => {
  try {
    const s = getSession(req.params.id);
    requireReady(s);
    const b = req.body || {};
    const to = normalizeWaId(b.to);

    let result;
    switch (b.type) {
      case 'text':
        result = await s.client.sendMessage(to, b.body ?? '', b.quotedMessageId ? { quotedMessageId: b.quotedMessageId } : {});
        break;

      case 'reply':
        result = await s.client.sendMessage(to, b.body ?? '', { quotedMessageId: b.quotedMessageId });
        break;

      case 'image':
      case 'video':
      case 'audio':
      case 'document':
      case 'sticker': {
        const media = await buildOutgoingMedia(b);
        const options = {
          caption: b.caption,
          sendMediaAsSticker: b.type === 'sticker',
          sendMediaAsDocument: b.type === 'document',
          sendAudioAsVoice: b.sendAudioAsVoice === true && b.type === 'audio',
        };
        result = await s.client.sendMessage(to, media, options);
        break;
      }

      case 'location':
        result = await s.client.sendMessage(to, new Location(b.latitude, b.longitude, b.description));
        break;

      case 'reaction': {
        const msg = await s.client.getMessageById(b.messageId);
        await msg.react(b.emoji ?? '');
        result = { ok: true };
        break;
      }

      default:
        return res.status(400).json({ error: `unsupported type: ${b.type}` });
    }

    res.json(serializeMessage(result) || { ok: true });
  } catch (e) { next(e); }
});

// Download a message's media bytes (image/video/audio/document/sticker).
// Streams back with the original mime + filename so <img src=>, <audio src=>, etc. work.
app.get('/sessions/:id/messages/:messageId/media', async (req, res, next) => {
  try {
    const s = getSession(req.params.id);
    requireReady(s);
    const msg = await s.client.getMessageById(req.params.messageId);
    if (!msg || !msg.hasMedia) {
      return res.status(404).json({ error: 'no media for this message' });
    }
    const media = await msg.downloadMedia();
    if (!media || !media.data) {
      return res.status(404).json({ error: 'media download failed (may have expired on WhatsApp servers)' });
    }
    res.set('Content-Type', media.mimetype || 'application/octet-stream');
    res.set('Cache-Control', 'private, max-age=3600');
    if (media.filename) {
      res.set('Content-Disposition', `inline; filename="${media.filename.replace(/"/g, '')}"`);
    }
    res.send(Buffer.from(media.data, 'base64'));
  } catch (e) { next(e); }
});

app.post('/sessions/:id/messages/:messageId/delete', async (req, res, next) => {
  try {
    const s = getSession(req.params.id);
    requireReady(s);
    const msg = await s.client.getMessageById(req.params.messageId);
    await msg.delete(req.body?.forEveryone === true);
    res.json({ ok: true });
  } catch (e) { next(e); }
});

app.get('/sessions/:id/chats', async (req, res, next) => {
  try {
    const s = getSession(req.params.id);
    requireReady(s);
    const chats = await s.client.getChats();
    res.json(chats.map((c) => ({
      id: c.id._serialized,
      name: c.name,
      isGroup: c.isGroup,
      unreadCount: c.unreadCount,
      timestamp: c.timestamp,
      lastMessage: c.lastMessage ? serializeMessage(c.lastMessage) : null,
    })));
  } catch (e) { next(e); }
});

app.get('/sessions/:id/groups', async (req, res, next) => {
  try {
    const s = getSession(req.params.id);
    requireReady(s);
    const chats = await s.client.getChats();
    res.json(chats.filter((c) => c.isGroup).map((c) => ({
      id: c.id._serialized,
      name: c.name,
      description: c.description,
      participants: (c.participants || []).map((p) => ({
        id: p.id._serialized,
        isAdmin: p.isAdmin,
        isSuperAdmin: p.isSuperAdmin,
      })),
    })));
  } catch (e) { next(e); }
});

app.post('/sessions/:id/groups', async (req, res, next) => {
  try {
    const s = getSession(req.params.id);
    requireReady(s);
    const { name, participants } = req.body;
    const group = await s.client.createGroup(name, participants);
    res.json(group);
  } catch (e) { next(e); }
});

app.post('/sessions/:id/groups/:groupId/participants/add', async (req, res, next) => {
  try {
    const s = getSession(req.params.id);
    requireReady(s);
    const chat = await s.client.getChatById(req.params.groupId);
    const result = await chat.addParticipants(req.body.participants || []);
    res.json(result);
  } catch (e) { next(e); }
});

app.post('/sessions/:id/groups/:groupId/participants/remove', async (req, res, next) => {
  try {
    const s = getSession(req.params.id);
    requireReady(s);
    const chat = await s.client.getChatById(req.params.groupId);
    const result = await chat.removeParticipants(req.body.participants || []);
    res.json(result);
  } catch (e) { next(e); }
});

app.post('/sessions/:id/groups/:groupId/leave', async (req, res, next) => {
  try {
    const s = getSession(req.params.id);
    requireReady(s);
    const chat = await s.client.getChatById(req.params.groupId);
    await chat.leave();
    res.json({ ok: true });
  } catch (e) { next(e); }
});

app.put('/sessions/:id/groups/:groupId/subject', async (req, res, next) => {
  try {
    const s = getSession(req.params.id);
    requireReady(s);
    const chat = await s.client.getChatById(req.params.groupId);
    await chat.setSubject(req.body.subject || '');
    res.json({ ok: true });
  } catch (e) { next(e); }
});

app.get('/sessions/:id/contacts', async (req, res, next) => {
  try {
    const s = getSession(req.params.id);
    requireReady(s);
    const contacts = await s.client.getContacts();
    res.json(contacts.map((c) => ({
      id: c.id._serialized,
      name: c.name,
      pushname: c.pushname,
      number: c.number,
      isUser: c.isUser,
      isWAContact: c.isWAContact,
      isMyContact: c.isMyContact,
      isBlocked: c.isBlocked,
      isBusiness: c.isBusiness,
    })));
  } catch (e) { next(e); }
});

app.get('/sessions/:id/contacts/:contactId', async (req, res, next) => {
  try {
    const s = getSession(req.params.id);
    requireReady(s);
    const contact = await s.client.getContactById(req.params.contactId);
    res.json(contact);
  } catch (e) { next(e); }
});

app.get('/sessions/:id/contacts/:number/exists', async (req, res, next) => {
  try {
    const s = getSession(req.params.id);
    requireReady(s);
    const exists = await s.client.isRegisteredUser(req.params.number);
    res.json({ number: req.params.number, exists });
  } catch (e) { next(e); }
});

// Helper: race a promise against a timeout — returns null if the inner promise
// doesn't resolve within `ms`. Used to keep slow WhatsApp lookups from
// blocking the request indefinitely.
function withTimeout(promise, ms) {
  return Promise.race([
    promise.catch(() => null),
    new Promise(resolve => setTimeout(() => resolve(null), ms)),
  ]);
}

// Stream a contact's WhatsApp profile picture. Returns 404 if the contact
// has no picture set, has hidden it from us, the fetch times out, or the
// CDN fetch fails. Bounded to ~6s total so a single bad contact can't stall
// a page render.
app.get('/sessions/:id/contacts/:contactId/picture', async (req, res, next) => {
  try {
    const s = getSession(req.params.id);
    requireReady(s);

    const url = await withTimeout(s.client.getProfilePicUrl(req.params.contactId), 5000);
    if (!url) {
      return res.status(404).json({ error: 'no profile picture' });
    }

    const upstream = await withTimeout(fetch(url), 5000);
    if (!upstream || !upstream.ok) {
      return res.status(404).json({ error: 'profile picture fetch failed' });
    }

    res.set('Content-Type', upstream.headers.get('content-type') || 'image/jpeg');
    res.set('Cache-Control', 'private, max-age=3600');
    res.send(Buffer.from(await upstream.arrayBuffer()));
  } catch (e) { next(e); }
});

// Edit a previously-sent message (15-minute window per WhatsApp).
app.post('/sessions/:id/messages/:messageId/edit', async (req, res, next) => {
  try {
    const s = getSession(req.params.id);
    requireReady(s);
    const msg = await s.client.getMessageById(req.params.messageId);
    if (!msg) return res.status(404).json({ error: 'message not found' });
    const body = req.body?.body ?? '';
    const result = await msg.edit(body);
    res.json(serializeMessage(result) || { ok: true });
  } catch (e) { next(e); }
});

/**
 * Status / Stories.
 *
 * whatsapp-web.js handles status by sending to the special chat ID
 * `status@broadcast` — text, image, video, or voice. The "audience" is
 * controlled by your phone's privacy settings (everyone / my contacts).
 *
 * Body: { type: 'text'|'image'|'video', body?, url?, base64?, mimeType?, caption?, backgroundColor?, font? }
 */
app.post('/sessions/:id/status', async (req, res, next) => {
  try {
    const s = getSession(req.params.id);
    requireReady(s);
    const b = req.body || {};

    let result;
    switch (b.type) {
      case 'text': {
        const options = {};
        if (b.backgroundColor) options.backgroundColor = b.backgroundColor;
        if (typeof b.font === 'number') options.font = b.font;
        result = await s.client.sendMessage('status@broadcast', b.body ?? '', options);
        break;
      }
      case 'image':
      case 'video': {
        const media = await buildOutgoingMedia(b);
        result = await s.client.sendMessage('status@broadcast', media, { caption: b.caption });
        break;
      }
      default:
        return res.status(400).json({ error: `unsupported status type: ${b.type}` });
    }

    res.json(serializeMessage(result) || { ok: true });
  } catch (e) { next(e); }
});

// Server-Sent Events for the bridge command to consume.
app.get('/sessions/:id/events', (req, res, next) => {
  try {
    const s = getSession(req.params.id);
    res.set({
      'Content-Type': 'text/event-stream',
      'Cache-Control': 'no-cache',
      Connection: 'keep-alive',
      'X-Accel-Buffering': 'no',
    });
    res.flushHeaders();
    res.write(`event: hello\ndata: ${JSON.stringify({ sessionId: req.params.id, status: s.status })}\n\n`);
    s.subscribers.add(res);
    req.on('close', () => s.subscribers.delete(res));
  } catch (e) { next(e); }
});

// Centralized error handler — turns thrown { http } errors into HTTP responses.
app.use((err, _req, res, _next) => {
  const status = err.http || 500;
  res.status(status).json({ error: err.message || 'internal error' });
});

const server = app.listen(PORT, HOST, () => {
  console.log(`[laravel-wa-sidecar] listening on http://${HOST}:${PORT}`);
});

function shutdown(signal) {
  console.log(`[laravel-wa-sidecar] caught ${signal}, shutting down…`);
  Promise.all([...sessions.values()].map((s) => s.client.destroy().catch(() => {})))
    .finally(() => server.close(() => process.exit(0)));
  setTimeout(() => process.exit(1), 10000).unref();
}
process.on('SIGTERM', () => shutdown('SIGTERM'));
process.on('SIGINT', () => shutdown('SIGINT'));
