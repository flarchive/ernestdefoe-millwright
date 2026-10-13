#!/usr/bin/env bash
# =============================================================================
# Install the root guard into a Flarum site's console entry.
#
# 🚨 Why this exists
#
# `php flarum <anything>` run as root writes root-owned files into storage/.
# php-fpm runs as the web user and can then never replace them — deleting a file
# needs write permission on its PARENT DIRECTORY, and that is root's too. The
# formatter cache is the usual casualty: the entry outlives the generated class
# it names, every unserialize yields __PHP_Incomplete_Class, and every post on
# the site 500s until someone removes the files as root.
#
# It took ernestdefoe.online's post rendering down four times. Three of those
# were a human typing `docker exec <container> php flarum …` without
# `-u www-data` — which is not a mistake anyone stops making by being told.
#
# So the console entry stops relying on how it was invoked: run as root, it
# heals what an earlier root run left behind and drops to the web user before
# Flarum boots. `millwright:repair-formatter` then always has the permissions it
# needs, whoever ran it.
#
# Idempotent — re-running replaces any guard already installed, so this is safe
# to call on every container boot.
#
# Usage: install-root-guard.sh [webroot] [web-user]
#        install-root-guard.sh /var/www/html www-data
# =============================================================================
set -u

WEBROOT="${1:-/var/www/html}"
WEBUSER="${2:-www-data}"
BIN="$WEBROOT/flarum"

if [ ! -f "$BIN" ]; then
    echo "[!] no Flarum console at $BIN — root guard not installed" >&2
    exit 1
fi

# The guard itself. Single-quoted heredoc: nothing here is expanded by bash, so
# the PHP lands verbatim. $WEBUSER is substituted afterwards, deliberately.
GUARD=$(cat <<'GUARDPHP'
// >>> millwright root guard — installed by tools/install-root-guard.sh
// Running this CLI as root leaves root-owned files in storage/ that the web
// user can never replace, which takes post rendering down site-wide. Heal what
// an earlier root run left behind, then drop to the web user before booting.
if (PHP_SAPI === 'cli'
    && function_exists('posix_geteuid') && posix_geteuid() === 0
    && function_exists('posix_getpwnam') && ($mwPw = posix_getpwnam('__WEBUSER__'))) {
    foreach (['/storage', '/assets', '/public/assets'] as $mwRel) {
        $mwDir = __DIR__ . $mwRel;

        if (! is_dir($mwDir)) {
            continue;
        }

        // -print -quit: stop at the first stray rather than walk the whole tree.
        $mwStray = [];
        exec('find ' . escapeshellarg($mwDir) . ' ! -user __WEBUSER__ -print -quit 2>/dev/null', $mwStray);

        if ($mwStray !== []) {
            fwrite(STDERR, "[millwright] healing root-owned files under {$mwDir}\n");
            exec('chown -R __WEBUSER__:__WEBUSER__ ' . escapeshellarg($mwDir) . ' 2>/dev/null');
        }
    }

    // Millwright runs composer in-process; point it at a home the web user owns
    // before dropping, or every run warns and re-downloads the whole world.
    if (getenv('COMPOSER_HOME') === false) {
        putenv('COMPOSER_HOME=' . __DIR__ . '/storage/.composer');
    }
    putenv('HOME=' . $mwPw['dir']);

    posix_setgid($mwPw['gid']);
    posix_initgroups('__WEBUSER__', $mwPw['gid']);
    posix_setuid($mwPw['uid']);

    fwrite(STDERR, "[millwright] dropped root -> __WEBUSER__\n");
}
// <<< millwright root guard
GUARDPHP
)
GUARD=${GUARD//__WEBUSER__/$WEBUSER}

TMP=$(mktemp)

# Strip any guard already present, then re-insert after the opening PHP tag.
awk -v guard="$GUARD" '
    /^\/\/ >>> millwright root guard/ { skip = 1 }
    skip                              { if (/^\/\/ <<< millwright root guard/) { skip = 0 } ; next }
                                      { print }
    !done && /^<\?php[[:space:]]*$/   { print "" ; print guard ; done = 1 }
' "$BIN" > "$TMP"

# 🚨 Never leave a site with a console it cannot parse. Lint before writing.
if ! php -l "$TMP" >/dev/null 2>&1; then
    echo "[!] patched console failed php -l — leaving $BIN untouched" >&2
    rm -f "$TMP"
    exit 1
fi

if ! grep -q '>>> millwright root guard' "$TMP"; then
    echo "[!] no '<?php' line found in $BIN — leaving it untouched" >&2
    rm -f "$TMP"
    exit 1
fi

# cat, not mv: keeps the inode, mode and any ACL the site already had.
cat "$TMP" > "$BIN"
rm -f "$TMP"
chown "$WEBUSER:$WEBUSER" "$BIN" 2>/dev/null || true

echo "[+] root guard installed in $BIN (web user: $WEBUSER)"
