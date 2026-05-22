#!/usr/bin/env bash
# Build dist/laravel-whatsapp.css — the standalone CSS used when the host
# app sets WHATSAPP_UI_CSS_MODE=standalone (i.e. doesn't have Tailwind set up).
#
# Run from the package root:
#   ./dist-src/build.sh
#
# Requires: a Laravel app with `livewire/flux` installed (so the build can
# resolve Flux's stubs + flux.css). The build is run from that app's Vite,
# pointed at dist-src/whatsapp-ui.css as the entry, then the output is
# copied into ./dist/ here.
#
# In CI this is usually a one-time release step. The dist/ file is committed.

set -euo pipefail

PACKAGE_ROOT="$(cd "$(dirname "$0")/.." && pwd)"
TEST_APP="${LARAVEL_WHATSAPP_BUILD_APP:-$PACKAGE_ROOT/../../test-laravel-wa}"

if [[ ! -d "$TEST_APP" ]]; then
  echo "Build host Laravel app not found at: $TEST_APP" >&2
  echo "Set LARAVEL_WHATSAPP_BUILD_APP to a Laravel app that has livewire/flux installed." >&2
  exit 1
fi

echo "→ Package root: $PACKAGE_ROOT"
echo "→ Build host:   $TEST_APP"

# Stage our entry CSS inside the host app so Vite can compile it with
# Flux available at the expected relative path.
STAGE="$TEST_APP/resources/css/_laravel-whatsapp-build.css"

# Rewrite ../vendor → ../../vendor for the host app's path layout.
sed 's|\.\./vendor/|../../vendor/|g; s|\.\./resources/views|../../vendor/kstmostofa/laravel-whatsapp/resources/views|g' \
  "$PACKAGE_ROOT/dist-src/whatsapp-ui.css" > "$STAGE"

# Patch vite.config.js to include our entry (if not already there).
if ! grep -q "_laravel-whatsapp-build.css" "$TEST_APP/vite.config.js"; then
  echo "→ Adding entry to vite.config.js (temporary)…"
  # Make a backup so we can restore
  cp "$TEST_APP/vite.config.js" "$TEST_APP/vite.config.js.lwa-bak"
  # Naive injection — assumes input: [ … ] structure
  perl -i -pe "s|input: \[|input: ['resources/css/_laravel-whatsapp-build.css', |" "$TEST_APP/vite.config.js"
fi

(cd "$TEST_APP" && npm run build) >&2

# Find the compiled output and copy to dist/
BUILT="$(ls -1t "$TEST_APP/public/build/assets/_laravel-whatsapp-build"-*.css | head -1)"
if [[ -z "$BUILT" ]]; then
  echo "Could not find compiled CSS in $TEST_APP/public/build/assets/" >&2
  exit 1
fi

cp "$BUILT" "$PACKAGE_ROOT/dist/laravel-whatsapp.css"
echo "→ Wrote $PACKAGE_ROOT/dist/laravel-whatsapp.css ($(wc -c <"$PACKAGE_ROOT/dist/laravel-whatsapp.css") bytes)"

# Cleanup
rm -f "$STAGE"
if [[ -f "$TEST_APP/vite.config.js.lwa-bak" ]]; then
  mv "$TEST_APP/vite.config.js.lwa-bak" "$TEST_APP/vite.config.js"
fi

echo "✓ Done. Commit dist/laravel-whatsapp.css with your release."
