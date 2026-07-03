const fs = require('fs');

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
      .sort();
  } catch (_) {
    return [];
  }
}

module.exports = { discoverPersistedSessions };
