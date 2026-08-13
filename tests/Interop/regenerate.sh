#!/bin/sh
# Regenerates the interop goldens (expected.nq) with jsonld.js inside a
# pinned node container, so no local node/npm install is needed.
#
#   ./tests/Interop/regenerate.sh
#
# Goldens change ONLY when fixtures change or the reference implementation
# fixes a bug; review any diff like a code change.
set -eu

cd "$(dirname "$0")"

docker run --rm \
  -v "$PWD":/interop \
  -w /interop \
  node:22-alpine \
  sh -ceu 'npm install --no-save --no-audit --no-fund jsonld@8 >/dev/null && node regenerate.mjs && rm -rf node_modules package-lock.json package.json'
