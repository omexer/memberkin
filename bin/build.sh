#!/usr/bin/env bash
#
# Builds the release zip for WordPress.org: build/memberkin/ and build/memberkin-<version>.zip.
#
# Copies the plugin without the files listed in .distignore (tests, developer tools, config),
# then installs only the runtime Composer dependencies.
#
# Usage: bin/build.sh

set -euo pipefail

root="$(cd "$(dirname "$0")/.." && pwd)"
slug="memberkin"
version="$(sed -n 's/^ \* Version: *//p' "$root/memberkin.php" | head -n 1)"
build="$root/build"
dest="$build/$slug"

rm -rf "$build"
mkdir -p "$dest"

rsync -a --exclude-from="$root/.distignore" "$root/" "$dest/"

composer install --working-dir="$dest" --no-dev --optimize-autoloader --no-interaction --quiet
rm -rf "$dest/vendor/bin"

# Composer packages may ship hidden files and dev config; WordPress.org does not allow them.
find "$dest/vendor" \( -name '.*' -o -name 'phpcs.xml*' -o -name 'phpunit.xml*' -o -name 'composer.lock' \) -prune -exec rm -rf {} +

( cd "$build" && zip -qr "$slug-$version.zip" "$slug" )

echo "Built $build/$slug-$version.zip"
