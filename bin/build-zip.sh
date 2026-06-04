#!/usr/bin/env bash
#
# Build a production-ready WordPress.org plugin ZIP.
#
# Produces dist/tagbridge.zip containing only the files that should ship: the
# runtime PHP, assets, languages, readme, and a --no-dev vendor directory.
# Development files (tests, tooling config, node/composer dev deps, dotfiles)
# are excluded.

set -euo pipefail

SLUG="tagbridge"
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
STAGE="$(mktemp -d)"
DEST="${STAGE}/${SLUG}"
DIST="${ROOT}/dist"

echo "Building ${SLUG}.zip ..."
mkdir -p "${DEST}" "${DIST}"
rm -f "${DIST}/${SLUG}.zip"

# Runtime files only.
cp "${ROOT}/tagbridge.php"  "${DEST}/"
cp "${ROOT}/uninstall.php" "${DEST}/"
cp "${ROOT}/readme.txt"    "${DEST}/"
cp -R "${ROOT}/src"        "${DEST}/"
cp -R "${ROOT}/assets"     "${DEST}/"
cp -R "${ROOT}/languages"  "${DEST}/"

# Vendored runtime dependencies (no dev tooling).
# composer.json and composer.lock ship with the plugin so reviewers and users
# can see and reproduce the dependency tree (WordPress.org asks for this).
cp "${ROOT}/composer.json" "${DEST}/"
cp "${ROOT}/composer.lock" "${DEST}/"
( cd "${DEST}" && composer install --no-dev --optimize-autoloader --no-scripts --no-interaction --quiet )

# Strip junk that may have been copied.
find "${DEST}" -name ".DS_Store" -delete
find "${DEST}" -name ".gitkeep" -delete
find "${DEST}" -type d -name ".git" -prune -exec rm -rf {} +

# Trim non-runtime files from vendored dependencies (keep licenses + lib code).
# WordPress.org rejects shell scripts and various dev files, so strip them.
find "${DEST}/vendor" -type d \( \
	-iname tests -o -iname test -o -iname examples -o -iname ".github" \
	-o -iname ".changeset" -o -iname scripts -o -iname Test -o -iname bin \
	\) -prune -exec rm -rf {} +
find "${DEST}/vendor" -type f \( \
	-iname "*.sh" -o -iname ".gitignore" -o -iname ".gitattributes" -o -iname "*.dist" \
	-o -iname "phpunit*.xml*" -o -iname "RELEASING.md" -o -iname "CONTRIBUTING.md" \
	-o -iname "CHANGELOG.md" -o -iname "Makefile" -o -iname "*.yml" -o -iname "*.yaml" \
	\) -delete

# Remove ALL hidden files/dirs anywhere in the package (WordPress.org disallows them).
find "${DEST}" -depth -name ".*" ! -name "." ! -name ".." -exec rm -rf {} +

# Build the ZIP with the slug as the top-level folder.
( cd "${STAGE}" && zip -rq "${DIST}/${SLUG}.zip" "${SLUG}" )

rm -rf "${STAGE}"

echo "Done: ${DIST}/${SLUG}.zip"
ls -lh "${DIST}/${SLUG}.zip"
