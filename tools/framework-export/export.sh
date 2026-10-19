#!/usr/bin/env bash
#
# Copies Millwright into flarum/framework as extensions/package-manager, the
# Extension Manager that ships with Flarum 2.1, renamed on the way:
#
#   ErnestDefoe\Millwright   -> Flarum\ExtensionManager
#   ernestdefoe/millwright   -> flarum/extension-manager
#   ernestdefoe-millwright   -> flarum-extension-manager (ext id, translation keys)
#   /api/millwright/*        -> /api/extension-manager/*
#   millwright:*             -> extension-manager:*
#   storage/millwright       -> storage/extension-manager (an existing one is still read)
#   .Millwright-*            -> .ExtensionManager-*
#
# Re-runnable: it replaces the whole extension directory each time, so a change
# made in Millwright is carried across by running it again. Files that exist
# only in the framework copy live in overlay/ and are laid over the result.
#
#   tools/framework-export/export.sh [path/to/framework]
#
# Remove with Millwright once Flarum 2.1 ships.

set -euo pipefail

HERE="$(cd "$(dirname "$0")" && pwd)"
SRC="$(cd "$HERE/../.." && pwd)"
FRAMEWORK="${1:-$HOME/github/framework}"
DEST="$FRAMEWORK/extensions/package-manager"

[ -f "$FRAMEWORK/flarum-monorepo.json" ] || { echo "not a flarum/framework checkout: $FRAMEWORK" >&2; exit 1; }

# Everything but the licence (Flarum's) and installed node modules.
find "$DEST" -mindepth 1 -maxdepth 1 ! -name LICENSE.md ! -name node_modules -exec rm -rf {} +
rm -rf "$DEST/js/dist" "$DEST/js/dist-typings" "$DEST/js/src" "$DEST/js/tests"

mkdir -p "$DEST/js/tests/unit/admin" "$DEST/tests"
cp -R "$SRC/src" "$SRC/bootstrap" "$SRC/less" "$SRC/extend.php" "$SRC/README.md" "$DEST/"
cp -R "$SRC/resources/locale" "$DEST/locale"
cp -R "$SRC/tests/unit" "$SRC/tests/fixtures" "$DEST/tests/"
cp -R "$SRC/js/src" "$SRC/js/admin.ts" "$DEST/js/"
cp "$SRC"/js/test/*.test.ts "$DEST/js/tests/unit/admin/"

# The tests moved one level deeper (js/test -> js/tests/unit/admin).
perl -pi -e "s#'\.\./src/#'../../../src/#g" "$DEST"/js/tests/unit/admin/*.test.ts
# Jest provides describe/it/expect as globals.
perl -ni -e "print unless /^import \{[^}]*\} from 'vitest';\n/" "$DEST"/js/tests/unit/admin/*.test.ts

# 🚨 Order matters: the specific names first, the bare word last.
find "$DEST" -path "$DEST/js/node_modules" -prune -o -type f \
    \( -name '*.php' -o -name '*.ts' -o -name '*.tsx' -o -name '*.js' -o -name '*.less' -o -name '*.yml' -o -name '*.md' -o -name '*.json' \) -print0 \
  | xargs -0 perl -pi -e '
      s/ErnestDefoe(\\+)Millwright/Flarum${1}ExtensionManager/g;
      s/MillwrightServiceProvider/ExtensionManagerServiceProvider/g;
      s/MillwrightPage/ExtensionManagerPage/g;
      s/removeWithMillwright/removeWithExtensionManager/g;
      s/millwrightDir/dataDir/g;
      s#resources/locale#locale#g;
      s/MILLWRIGHT_EARLY_STORAGE/FLARUM_EXTENSION_MANAGER_EARLY_STORAGE/g;
      s/\@?ernestdefoe-millwright/flarum-extension-manager/g;
      s/\@?ernestdefoe\/millwright/flarum\/extension-manager/g;
      s/Millwright-(?=[a-zA-Z])/ExtensionManager-/g;

      # Prose about the Extension Manager this one replaces. Not the core
      # class Flarum\Extension\ExtensionManager, which has no space.
      s/\b(?:[Tt]he |Flarum\x27s )?Extension Manager\b/\x{1}OLD\x{1}/g;
      s/\b[Tt]he Millwright\b/\x{1}EM\x{1}/g;
      s/\bA Millwright\b/An Extension Manager/g;
      s/\ba Millwright\b/an Extension Manager/g;
      s/\bMillwright\b/\x{1}EM\x{1}/g;
      s/\bmillwright\b/extension-manager/g;

      # Capital at the start of a sentence, a comment line or a YAML value.
      s/((?:^\s*(?:[*#]|\/\/|\/\*\*?)?\s*|[.!?]\s+|^\s*[\w-]+:\s+["\x27]?|^#+\s+|["\x27`(]))\x{1}(OLD|EM)\x{1}/$1 . ($2 eq "OLD" ? "The previous Extension Manager" : "The Extension Manager")/ge;
      s/\x{1}OLD\x{1}/the previous Extension Manager/g;
      s/\x{1}EM\x{1}/the Extension Manager/g;
    '

# Files named after Millwright.
find "$DEST" -path "$DEST/js/node_modules" -prune -o -type f -name '*Millwright*' -print | while read -r f; do
    mv "$f" "$(dirname "$f")/$(basename "$f" | sed s/Millwright/ExtensionManager/)"
done

# Flarum's licence header on every PHP file that lacks one.
find "$DEST" -path "$DEST/js/node_modules" -prune -o -name '*.php' -type f -print0 | xargs -0 perl -0pi -e '
    unless (/This file is part of Flarum\./) {
        s/\A<\?php\n\n?/<?php\n\n\/*\n * This file is part of Flarum.\n *\n * For detailed copyright and license information, please view the\n * LICENSE file that was distributed with this source code.\n *\/\n\n/;
    }'

# The test fixtures run in a subprocess and load Composer themselves: from the
# extension's own vendor/ when installed alone, the monorepo's otherwise.
perl -pi -e 's#^require __DIR__\.\x27/\.\./\.\./vendor/autoload\.php\x27;#require is_file(__DIR__.\x27/../../vendor/autoload.php\x27) ? __DIR__.\x27/../../vendor/autoload.php\x27 : __DIR__.\x27/../../../../vendor/autoload.php\x27;#' "$DEST"/tests/fixtures/*.php

cp -R "$HERE/overlay/." "$DEST/"
php "$HERE/patch-extend.php" "$DEST/extend.php"

# StyleCI's import order and the blank line after the imports. The rename
# changes how the imports sort, so this can only be done on the exported copy.
find "$DEST" -path "$DEST/js/node_modules" -prune -o -path "$DEST/vendor" -prune -o -name '*.php' -type f -print0 \
    | xargs -0 php "$HERE/sort-imports.php"

# Flarum's own Prettier rules, so the framework's format check passes on the
# renamed code. 🚨 Prettier 2.8.8, the version the extensions' `prettier ^2.5.1`
# resolves to in the monorepo's yarn.lock and the one CI runs; Prettier 3
# formats differently and failed CI on code it had just passed locally.
# 🚨 Never `npx prettier@2.8.8` here: inside the monorepo npx quietly runs the
# root's Prettier 3 instead. A copy of its own, outside the monorepo.
PRETTIER_HOME="${XDG_CACHE_HOME:-$HOME/.cache}/framework-export/prettier-2.8.8"
if [ ! -f "$PRETTIER_HOME/node_modules/prettier/bin-prettier.js" ]; then
    npm install --silent --no-save --prefix "$PRETTIER_HOME" prettier@2.8.8 >/dev/null
fi
(cd "$DEST/js" && node "$PRETTIER_HOME/node_modules/prettier/bin-prettier.js" --write src >/dev/null)

echo "Exported $(cd "$SRC" && git rev-parse --short HEAD) to $DEST"
