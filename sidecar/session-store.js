const fs = require('fs');

// Session IDs become directory names (`session-<id>`), so keep them to a safe
// charset — the same rule the Laravel UI enforces.
const SESSION_ID_PATTERN = /^[A-Za-z0-9_-]{1,64}$/;

function isValidSessionId(id) {
  return typeof id === 'string' && SESSION_ID_PATTERN.test(id);
}

function discoverPersistedSessions(sessionDir) {
  if (!sessionDir || !fs.existsSync(sessionDir)) return [];

  try {
    return fs.readdirSync(sessionDir, { withFileTypes: true })
      .filter((entry) => (
        entry.isDirectory()
        && entry.name.startsWith('session-')
        && entry.name.length > 'session-'.length
      ))
      .map((entry) => entry.name.slice('session-'.length))
      .filter(isValidSessionId)
      .sort();
  } catch (_) {
    return [];
  }
}

module.exports = { discoverPersistedSessions, isValidSessionId };
