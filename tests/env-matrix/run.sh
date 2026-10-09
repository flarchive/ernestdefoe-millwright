#!/usr/bin/env bash
#
# Millwright's environment matrix: update an extension, then undo it, on the
# four ways people actually install Flarum 2, and report what held.
#
#   fiab      pianotell/flarum-in-a-box — Flarum baked INTO the image (overlayfs)
#   docker    ghcr.io/linkrobins/flarum-docker — a Composer-built image on a
#             volume, Redis (Valkey) cache, Horizon
#   zip       the official installation zip in plain PHP + Apache, shared-hosting
#             settings: proc_open/exec disabled, driven ONLY through the web API
#   composer  `composer create-project`, once driven from the CLI and once from
#             the web API (composer-cli, composer-web), each on a fresh forum
#
# Every run starts with root-owned cache files, made the way a real forum gets
# them (`docker exec … php flarum cache:clear` runs as root), and checks the
# pages and the after-update health check at each step.
#
# Usage:
#   tests/env-matrix/run.sh                       # on this machine (needs Docker)
#   tests/env-matrix/run.sh --remote root@host    # pack this checkout, run there
#   options: --only fiab,zip   --keep   --package acpl/mobile-tab --from 2.0.0-beta.12
#            --zip-url <url>   (default: the newest 2.x package Flarum publishes)
#
# Exit status: 0 when every check passed, 1 otherwise. See README.md.

set -uo pipefail

HERE="$(cd "$(dirname "$0")" && pwd)"
ROOT="$(cd "$HERE/../.." && pwd)"
P=mwmatrix                       # every container, network and volume starts with this
ONLY="fiab,docker,zip,composer-cli,composer-web,core-nightly,core-nightly-zip"
KEEP=0
PKG=acpl/mobile-tab
FROM=2.0.0-beta.12
ZIP_URL=""
CODE=""
REMOTE=""

while [ $# -gt 0 ]; do
  case "$1" in
    --remote)  REMOTE=$2; shift ;;
    --only)    ONLY=$2; shift ;;
    --keep)    KEEP=1 ;;
    --package) PKG=$2; shift ;;
    --from)    FROM=$2; shift ;;
    --zip-url) ZIP_URL=$2; shift ;;
    --code)    CODE=$2; shift ;;
    -h|--help) sed -n '2,25p' "$0"; exit 0 ;;
    *) echo "Unknown option: $1" >&2; exit 2 ;;
  esac
  shift
done

pack() {
  # The checkout as it stands, uncommitted work included: what is being tested
  # is this tree, not the last release.
  COPYFILE_DISABLE=1 tar czf "$1" -C "$ROOT" src js/dist resources less extend.php composer.json
}

if [ -n "$REMOTE" ]; then
  T=$(mktemp -d)
  pack "$T/code.tgz"
  cp "$0" "$T/run.sh"
  ssh "$REMOTE" "rm -rf /tmp/$P-run && mkdir -p /tmp/$P-run" || exit 2
  scp -q "$T/code.tgz" "$T/run.sh" "$REMOTE:/tmp/$P-run/" || exit 2
  rm -rf "$T"
  args="--only $ONLY --package $PKG --from $FROM"
  [ "$KEEP" = 1 ] && args="$args --keep"
  [ -n "$ZIP_URL" ] && args="$args --zip-url $ZIP_URL"
  ssh -t "$REMOTE" "bash /tmp/$P-run/run.sh --code /tmp/$P-run/code.tgz $args; s=\$?; rm -rf /tmp/$P-run; exit \$s"
  exit $?
fi

command -v docker >/dev/null || { echo "Docker is needed on the machine that runs this." >&2; exit 2; }

WORK=$(mktemp -d /tmp/$P.XXXXXX)
chmod 755 "$WORK"
if [ -z "$CODE" ]; then CODE="$WORK/code.tgz"; pack "$CODE"; fi
PASS_DB=$(head -c 18 /dev/urandom | base64 | tr -dc a-zA-Z0-9)
RESULTS=()
FAILED=0

say()   { printf '\n\033[1m== %s\033[0m\n' "$*"; }
check() { # env, what, ok(0/1), detail
  local mark="PASS"; [ "$3" = 1 ] || { mark="FAIL"; FAILED=1; }
  RESULTS+=("$1|$2|$mark|$4")
  printf '  %-4s %-34s %s\n' "$mark" "$2" "$4"
}

teardown() {
  [ -f "$WORK/docker/compose.yml" ] && docker compose -p ${P} -f "$WORK/docker/compose.yml" down -v >/dev/null 2>&1
  docker ps -aq --filter "name=^${P}_" | xargs -r docker rm -f >/dev/null 2>&1
  docker volume ls -q --filter "name=^${P}" | xargs -r docker volume rm >/dev/null 2>&1
  docker network ls -q --filter "name=^${P}" | xargs -r docker network rm >/dev/null 2>&1
  rm -rf "$WORK" 2>/dev/null || true
}
# Leftovers from an interrupted or --keep run are ours by name; nothing else
# is touched. Volumes and networks too, not only containers: a kept Docker
# run left its app volume behind with Flarum installed on it, and the next
# run's fresh database made the image's own migrate step fail at boot.
docker ps -aq --filter "name=^${P}_" | xargs -r docker rm -f >/dev/null 2>&1
docker volume ls -q --filter "name=^${P}" | xargs -r docker volume rm >/dev/null 2>&1
docker network ls -q --filter "name=^${P}" | xargs -r docker network rm >/dev/null 2>&1
[ "$KEEP" = 1 ] || trap teardown EXIT

# ── helpers that run inside a container ───────────────────────────────────────
# C (container), U (web user), D (app dir), URL are set by each environment.

x()  { docker exec -u "$U" "$C" sh -c "cd $D && $1"; }
xr() { docker exec "$C" sh -c "cd $D && $1"; }
code() { curl -s -o /dev/null -m 30 -w '%{http_code}' "$URL$1"; }

wait_for() { # url, seconds
  local i=0; while [ $i -lt "$2" ]; do [ "$(curl -s -o /dev/null -m 5 -w '%{http_code}' "$1")" = 200 ] && return 0; sleep 3; i=$((i+3)); done; return 1
}

version() {
  x "php -r '\$j=json_decode(file_get_contents(\"vendor/composer/installed.json\"),true); foreach(\$j[\"packages\"] ?? \$j as \$p) if(\$p[\"name\"]===\"$PKG\") echo \$p[\"version\"];'"
}

ext_id() { echo "$PKG" | tr '/' '-'; }

migrations_ran() { # how many of the extension's migrations Flarum's log holds
  x "php -r '\$a=(require \"site.php\")->bootApp(); echo \$a->getContainer()->make(\"db\")->table(\"migrations\")->where(\"extension\", \"$(ext_id)\")->count();'" 2>/dev/null
}

recorded() { # database changes the latest run wrote down for undo
  x "f=\$(ls -t storage/millwright/runs/*/migrations.json 2>/dev/null | head -1); [ -n \"\$f\" ] && php -r 'echo count(json_decode(file_get_contents(\$argv[1]),true));' \"\$f\" || echo 0"
}

put() { # local-file → container path, readable by everyone
  docker cp "$1" "$C:$2" && docker exec "$C" chmod 644 "$2"
}

api_key() {
  cat > "$WORK/key.php" <<'PHP'
<?php $app = (require 'site.php')->bootApp(); $db = $app->getContainer()->make('db');
$admin = $db->table('group_user')->where('group_id', 1)->orderBy('user_id')->value('user_id');
$key = bin2hex(random_bytes(20));
$db->table('api_keys')->insert(['key' => $key, 'user_id' => $admin, 'created_at' => date('Y-m-d H:i:s')]);
echo $key;
PHP
  put "$WORK/key.php" /tmp/mwm-key.php && x "php /tmp/mwm-key.php"
}

plant_root_cache() {
  xr "php flarum cache:clear >/dev/null 2>&1; true"
  curl -s -o /dev/null -m 30 "$URL/"
  xr "find storage/cache storage/formatter -user root 2>/dev/null | wc -l" | tr -d ' '
}

install_millwright() { # composer command prefix
  local composer="$1"
  x "COMPOSER_MEMORY_LIMIT=-1 $composer require ernestdefoe/millwright $PKG:$FROM -q --no-interaction" >/dev/null 2>&1
  # Loosen the pin so the update has somewhere to go.
  x "COMPOSER_MEMORY_LIMIT=-1 $composer require '$PKG:>=$FROM' --no-update -q" >/dev/null 2>&1
  put "$CODE" /tmp/mwm-code.tgz
  x "tar xzf /tmp/mwm-code.tgz -C vendor/ernestdefoe/millwright 2>/dev/null; php flarum extension:enable ernestdefoe-millwright >/dev/null; php flarum extension:enable $(echo "$PKG" | tr '/' '-') >/dev/null; php flarum assets:publish >/dev/null; php flarum cache:clear >/dev/null 2>&1; true"
  # The stale-code window after a CLI change (opcache revalidate_freq) is not Millwright's to judge.
  sleep 3; wait_for "$URL/" 90 >/dev/null
}

roll_back() { # api key
  local key=$1 n before after state m0
  m0=$(migrations_ran)
  n=$(plant_root_cache)
  state=$(curl -s -m 300 -X POST -H "Authorization: Token $key" -H 'Content-Type: application/json' -d '{}' "$URL/api/millwright/rollback" \
    | php -r '$j=json_decode(stream_get_contents(STDIN),true); echo $j["run"]["state"] ?? ("error: " . substr(json_encode($j), 0, 160));')
  # The database half of an undo runs on the next request, booted from the
  # restored code — the admin page asks for its state straight away.
  undo_error=$(curl -s -m 300 -H "Authorization: Token $key" "$URL/api/millwright/state" | php -r '$j=json_decode(stream_get_contents(STDIN),true); echo $j["undoError"] ?? "";')
  after=$(version)
  check "$ENV" "undo: database half finished" "$([ -z "$undo_error" ] && echo 1)" "${undo_error:-ok}"
  check "$ENV" "undo: run state ($n root-owned)" "$([ "$state" = rolled-back ] && echo 1)" "$state"
  check "$ENV" "undo: version restored" "$([ "$after" = "$FROM" ] && echo 1)" "$after"
  check "$ENV" "undo: site answers" "$([ "$(code /)" = 200 ] && echo 1)" "home $(code /)"
  check "$ENV" "undo: db changes reversed" "$([ "$(( m0 - $(migrations_ran) ))" = "${MIGRATED:-0}" ] && echo 1)" "${MIGRATED:-0} to reverse, $(( m0 - $(migrations_ran) )) reversed"
}

update_cli() {
  local n out v m0 m1
  m0=$(migrations_ran)
  n=$(plant_root_cache)
  check "$ENV" "root-owned cache planted" "$([ "${n:-0}" -gt 0 ] && echo 1)" "$n file(s)"
  out=$(x "timeout 600 php flarum millwright:update $PKG 2>&1")
  v=$(version)
  check "$ENV" "update (cli): finished" "$(echo "$out" | grep -q '^  Finished$' && echo 1)" "$(echo "$out" | grep -E 'Failed (during|at)' | head -1 | cut -c1-120)"
  check "$ENV" "update (cli): health check ran" "$(echo "$out" | grep -q 'answering normally after the update' && echo 1)" "$(echo "$out" | grep -iE 'answering|not answering' | tail -1 | cut -c1-90)"
  check "$ENV" "update (cli): version moved" "$([ -n "$v" ] && [ "$v" != "$FROM" ] && echo 1)" "$FROM → $v"
  check "$ENV" "update (cli): site answers" "$([ "$(code /)" = 200 ] && echo 1)" "home $(code /)"
  m1=$(migrations_ran); MIGRATED=$((m1 - m0))
  check "$ENV" "update (cli): db changes recorded" "$([ "$(recorded)" = "$MIGRATED" ] && echo 1)" "$MIGRATED ran, $(recorded) recorded"
}

update_web() { # api key
  local key=$1 n start r i=0 state log v m0 m1
  m0=$(migrations_ran)
  n=$(plant_root_cache)
  check "$ENV" "root-owned cache planted" "$([ "${n:-0}" -gt 0 ] && echo 1)" "$n file(s)"
  start=$(curl -s -m 60 -X POST -H "Authorization: Token $key" -H 'Content-Type: application/json' -d "{\"packages\":[\"$PKG\"]}" "$URL/api/millwright/update")
  while [ $i -lt 300 ]; do
    i=$((i+1))
    r=$(curl -s -m 120 -X POST -H "Authorization: Token $key" -H 'Content-Type: application/json' -d '{}' "$URL/api/millwright/step")
    echo "$r" | grep -q '"idle":true' && break
  done
  state=$(echo "$r" | php -r '$j=json_decode(stream_get_contents(STDIN),true); echo $j["run"]["state"] ?? "?";')
  log=$(echo "$r" | php -r '$j=json_decode(stream_get_contents(STDIN),true); echo implode("\n", $j["run"]["log"] ?? []), "\n", $j["run"]["error"] ?? "";')
  v=$(version)
  check "$ENV" "update (web, $i steps): finished" "$([ "$state" = done ] && echo 1)" "$state $(echo "$start" | grep -o '"error":"[^"]*' | cut -c1-100)"
  check "$ENV" "update (web): health check ran" "$(echo "$log" | grep -q 'answering normally after the update' && echo 1)" "$(echo "$log" | grep -iE 'answering' | tail -1 | cut -c1-90)"
  check "$ENV" "update (web): version moved" "$([ -n "$v" ] && [ "$v" != "$FROM" ] && echo 1)" "$FROM → $v"
  check "$ENV" "update (web): site answers" "$([ "$(code /)" = 200 ] && echo 1)" "home $(code /)"
  m1=$(migrations_ran); MIGRATED=$((m1 - m0))
  check "$ENV" "update (web): db changes recorded" "$([ "$(recorded)" = "$MIGRATED" ] && echo 1)" "$MIGRATED ran, $(recorded) recorded"
}

need_db() {
  docker network inspect "${P}_net" >/dev/null 2>&1 || docker network create "${P}_net" >/dev/null
  if ! docker ps -q --filter "name=^${P}_db$" | grep -q .; then
    docker run -d --name ${P}_db --network ${P}_net -e MARIADB_ROOT_PASSWORD="$PASS_DB" mariadb:11 >/dev/null
    local i=0; until docker exec ${P}_db mariadb -uroot -p"$PASS_DB" -e 'select 1' >/dev/null 2>&1 || [ $i -gt 60 ]; do sleep 2; i=$((i+1)); done
  fi
  docker exec ${P}_db mariadb -uroot -p"$PASS_DB" -e "CREATE DATABASE IF NOT EXISTS $1; CREATE USER IF NOT EXISTS 'flarum'@'%' IDENTIFIED BY '$PASS_DB'; GRANT ALL ON $1.* TO 'flarum'@'%';"
}

php_apache() { # name port docroot
  docker run -d --name "$1" --network ${P}_net -p 127.0.0.1:$2:80 -v "$WORK/$1:/app" \
    -e WEB_DOCUMENT_ROOT="$3" -e PHP_MEMORY_LIMIT=512M webdevops/php-apache:8.4 >/dev/null
  sleep 5
}

flarum_install() { # database, url
  cat > "$WORK/$C/install.yml" <<YML
debug: false
baseUrl: $2
databaseConfiguration: {driver: mariadb, host: ${P}_db, database: $1, username: flarum, password: "$PASS_DB", prefix: "", port: 3306}
adminUser: {username: admin, password: "${PASS_DB}Aa1!", password_confirmation: "${PASS_DB}Aa1!", email: admin@example.test}
settings: {forum_title: Millwright matrix}
YML
  chown 1000:1000 "$WORK/$C/install.yml"
  x "php flarum install --file=$D/install.yml >/dev/null 2>&1; rm -f install.yml"
}

# ── the environments ──────────────────────────────────────────────────────────

env_fiab() {
  ENV=fiab C=${P}_fiab U=www-data D=/var/www/html URL=http://127.0.0.1:18101
  say "Flarum-in-a-box (Flarum inside the image)"
  docker pull -q pianotell/flarum-in-a-box >/dev/null
  docker run -d --name $C -p 127.0.0.1:18101:80 pianotell/flarum-in-a-box >/dev/null
  wait_for "$URL/" 180 || { check fiab "forum came up" 0 "no 200 after 180s"; return; }
  install_millwright composer
  update_cli
  roll_back "$(api_key)"
}

env_docker() {
  ENV=docker C=${P}_docker U=www-data D=/var/www/html URL=http://127.0.0.1:18102
  say "Standard Flarum 2 Docker image (volume, Redis cache)"
  mkdir -p "$WORK/docker"
  cat > "$WORK/docker/.env" <<ENV
APP_URL=$URL
FORUM_TITLE=Millwright matrix
ADMIN_USER=admin
ADMIN_PASS=${PASS_DB}Aa1!
ADMIN_EMAIL=admin@example.test
DB_HOST=mariadb
DB_NAME=flarum
DB_USER=flarum
DB_PASS=$PASS_DB
DB_ROOT_PASS=${PASS_DB}r
REDIS_HOST=valkey
REDIS_PORT=6379
REDIS_PASSWORD=
MAIL_HOST=smtp.example.test
MAIL_PORT=587
MAIL_ENCRYPTION=tls
MAIL_FROM=forum@example.test
REALTIME_ENABLED=false
ENV
  # 🚨 Our own compose file, not the project's: theirs names its container
  # flarum_app and publishes port 80 — both of which a real forum on the same
  # host may already own.
  cat > "$WORK/docker/compose.yml" <<YML
services:
  flarum:
    image: ghcr.io/linkrobins/flarum-docker:latest
    container_name: ${P}_docker
    env_file: .env
    depends_on: { mariadb: { condition: service_healthy }, valkey: { condition: service_healthy } }
    ports: ["127.0.0.1:18102:80"]
    volumes: [ "app:/var/www/html" ]
  mariadb:
    image: mariadb:11
    container_name: ${P}_docker_db
    environment: { MYSQL_DATABASE: flarum, MYSQL_USER: flarum, MYSQL_PASSWORD: "$PASS_DB", MYSQL_ROOT_PASSWORD: "${PASS_DB}r" }
    healthcheck: { test: ["CMD", "healthcheck.sh", "--connect", "--innodb_initialized"], interval: 5s, retries: 30 }
  valkey:
    image: valkey/valkey:alpine
    container_name: ${P}_docker_valkey
    healthcheck: { test: ["CMD", "valkey-cli", "ping"], interval: 5s, retries: 30 }
volumes:
  app:
YML
  docker compose -p ${P} -f "$WORK/docker/compose.yml" up -d >/dev/null 2>&1
  wait_for "$URL/" 300 || { check docker "forum came up" 0 "no 200 after 300s"; return; }
  install_millwright composer
  update_cli
  roll_back "$(api_key)"
}

latest_zip() {
  local v
  v=$(curl -s https://api.github.com/repos/flarum/installation-packages/contents/packages/v2.x \
    | grep -o '"name": *"v2[^"]*"' | sed 's/.*"\(v2[^"]*\)"/\1/' | sort -V | tail -1)
  [ -n "$v" ] || v=v2.0.0-rc.8
  echo "https://github.com/flarum/installation-packages/raw/main/packages/v2.x/$v/flarum-$v-no-public-dir-php8.4.zip"
}

env_zip() {
  ENV=zip C=${P}_zip U=application D=/app URL=http://127.0.0.1:18103
  say "Official zip, shared-hosting settings (proc_open/exec off, web only)"
  need_db flarum_zip
  local url=${ZIP_URL:-$(latest_zip)}
  mkdir -p "$WORK/$C"
  curl -sL -o "$WORK/f.zip" "$url" && (cd "$WORK/$C" && unzip -q ../f.zip) || { check zip "zip downloaded" 0 "$url"; return; }
  check zip "zip unpacked" 1 "$(basename "$url")"
  chown -R 1000:1000 "$WORK/$C"
  php_apache $C 18103 /app
  flarum_install flarum_zip "$URL"
  install_millwright "php -d disable_functions= \$(command -v composer)"
  # Now lock it down, the way a shared host is.
  docker exec $C sh -c 'echo "disable_functions=proc_open,exec,shell_exec,system,passthru,popen" > /usr/local/etc/php/conf.d/zz-shared-host.ini; supervisorctl restart php-fpm:php-fpmd >/dev/null'
  sleep 2
  echo '<?php echo function_exists("proc_open") ? "on" : "off";' > "$WORK/$C/mwm-probe.php"; chown 1000:1000 "$WORK/$C/mwm-probe.php"
  local probe; probe=$(curl -s "$URL/mwm-probe.php"); rm -f "$WORK/$C/mwm-probe.php"
  check zip "proc_open disabled for the web" "$([ "$probe" = off ] && echo 1)" "proc_open $probe"
  local key; key=$(api_key)
  update_web "$key"
  roll_back "$key"
}

env_composer() { # mode: cli | web
  local mode=${1:-cli} port=18104 db=flarum_cp
  [ "$mode" = web ] && { port=18105; db=flarum_cpw; }
  ENV=composer-$mode C=${P}_composer_$mode U=application D=/app URL=http://127.0.0.1:$port
  say "composer create-project, driven from the $mode"
  need_db $db
  mkdir -p "$WORK/$C"; chown 1000:1000 "$WORK/$C"
  php_apache $C $port /app/public
  x "COMPOSER_MEMORY_LIMIT=-1 composer create-project 'flarum/flarum:^2.0@rc' . -q --no-interaction" >/dev/null 2>&1
  flarum_install $db "$URL"
  install_millwright composer
  local key; key=$(api_key)
  # Each mode gets its own forum: a second update over the same database after
  # an undo would test the extension's own down step, not Millwright. (Mobile
  # Tab 2.0.1's down leaves the permission row its up inserted.)
  if [ "$mode" = web ]; then update_web "$key"; else update_cli; fi
  roll_back "$key"
}

core_version() { x "php flarum info 2>/dev/null | sed -n 's/^Flarum core: //p'"; }
db_version() { x "php -r '\$a=(require \"site.php\")->bootApp(); echo \$a->getContainer()->make(\"db\")->table(\"settings\")->where(\"key\",\"version\")->value(\"value\");'" 2>/dev/null; }
all_migrations() { x "php -r '\$a=(require \"site.php\")->bootApp(); echo \$a->getContainer()->make(\"db\")->table(\"migrations\")->count();'" 2>/dev/null; }

# Flarum itself: the release this forum is on → the nightly build, through the
# web API as the admin screen does it, then undo back to the release.
env_core_nightly() { # host: composer (can start processes) | zip (shared hosting, cannot)
  local host=${1:-composer} port=18106 db=flarum_core
  [ "$host" = zip ] && { port=18107; db=flarum_corezip; }
  ENV=core-nightly-$host C=${P}_core_$host U=application D=/app URL=http://127.0.0.1:$port
  say "Flarum core: release → nightly → undo (web, $host install)"
  need_db $db
  mkdir -p "$WORK/$C"; chown 1000:1000 "$WORK/$C"
  if [ "$host" = zip ]; then
    local url=${ZIP_URL:-$(latest_zip)}
    curl -sL -o "$WORK/core.zip" "$url" && (cd "$WORK/$C" && unzip -q ../core.zip) || { check "$ENV" "zip downloaded" 0 "$url"; return; }
    chown -R 1000:1000 "$WORK/$C"
    php_apache $C $port /app
    flarum_install $db "$URL"
    install_millwright "php -d disable_functions= \$(command -v composer)"
    docker exec $C sh -c 'echo "disable_functions=proc_open,exec,shell_exec,system,passthru,popen" > /usr/local/etc/php/conf.d/zz-shared-host.ini; supervisorctl restart php-fpm:php-fpmd >/dev/null'
    sleep 2
  else
    php_apache $C $port /app/public
    x "COMPOSER_MEMORY_LIMIT=-1 composer create-project 'flarum/flarum:^2.0@rc' . -q --no-interaction" >/dev/null 2>&1
    flarum_install $db "$URL"
    install_millwright composer
  fi
  local key v0 m0 dv0 start r i=0 state v1 m1 dv1 undo_state undo_error v2 m2 dv2
  key=$(api_key); v0=$(core_version); m0=$(all_migrations); dv0=$(db_version)
  plant_root_cache >/dev/null
  start=$(curl -s -m 120 -X POST -H "Authorization: Token $key" -H 'Content-Type: application/json' -d '{"packages":["flarum/core"],"nightly":true}' "$URL/api/millwright/update")
  while [ $i -lt 400 ]; do
    i=$((i+1))
    r=$(curl -s -m 300 -X POST -H "Authorization: Token $key" -H 'Content-Type: application/json' -d '{}' "$URL/api/millwright/step")
    echo "$r" | grep -q '"idle":true' && break
  done
  state=$(echo "$r" | php -r '$j=json_decode(stream_get_contents(STDIN),true); echo $j["run"]["state"] ?? "?";')
  v1=$(core_version); m1=$(all_migrations); dv1=$(db_version)
  # When it didn't finish, say where and why: the run records the step that
  # failed and its error, and "failed" alone can't be diagnosed after the
  # containers are gone.
  local why
  why=$(echo "$r" | php -r '$j=json_decode(stream_get_contents(STDIN),true); $run=$j["run"]??[]; if (!empty($run["error"])) echo "at ", $run["errorStep"] ?? "?", ": ", substr($run["error"], 0, 400);')
  check "$ENV" "nightly: update finished ($i steps)" "$([ "$state" = done ] && echo 1)" "$state $why $(echo "$start" | grep -o '"error":"[^"]*' | cut -c1-200)"
  check "$ENV" "nightly: core moved" "$([ -n "$v1" ] && [ "$v1" != "$v0" ] && echo 1)" "$v0 → $v1 (database says $dv1)"
  check "$ENV" "nightly: site answers" "$([ "$(code /)" = 200 ] && [ "$(code /api)" = 200 ] && echo 1)" "home $(code /), api $(code /api)"
  plant_root_cache >/dev/null
  undo_state=$(curl -s -m 600 -X POST -H "Authorization: Token $key" -H 'Content-Type: application/json' -d '{}' "$URL/api/millwright/rollback" | php -r '$j=json_decode(stream_get_contents(STDIN),true); echo $j["run"]["state"] ?? ("error: " . substr(json_encode($j), 0, 160));')
  undo_error=$(curl -s -m 300 -H "Authorization: Token $key" "$URL/api/millwright/state" | php -r '$j=json_decode(stream_get_contents(STDIN),true); echo $j["undoError"] ?? "";')
  v2=$(core_version); m2=$(all_migrations); dv2=$(db_version)
  check "$ENV" "undo: request answered" "$([ "$undo_state" = rolled-back ] && echo 1)" "$undo_state"
  check "$ENV" "undo: database half finished" "$([ -z "$undo_error" ] && echo 1)" "${undo_error:-ok}"
  check "$ENV" "undo: core back on the release" "$([ "$v2" = "$v0" ] && echo 1)" "$v2"
  check "$ENV" "undo: Flarum's recorded version back" "$([ "$dv2" = "$dv0" ] && echo 1)" "$dv2"
  check "$ENV" "undo: db changes reversed" "$([ "$m2" = "$m0" ] && echo 1)" "$m0 before, $m1 on nightly, $m2 after"
  check "$ENV" "undo: site answers" "$([ "$(code /)" = 200 ] && [ "$(code /api)" = 200 ] && echo 1)" "home $(code /), api $(code /api)"
}

for e in ${ONLY//,/ }; do
  case "$e" in
    fiab|docker|zip) "env_$e" ;;
    composer-cli|composer-web) env_composer "${e#composer-}" ;;
    core-nightly|core-nightly-composer) env_core_nightly composer ;;
    core-nightly-zip) env_core_nightly zip ;;
    *) echo "Unknown environment: $e" >&2; FAILED=1 ;;
  esac
done

say "Summary"
printf '  %-13s %-34s %s\n' ENV CHECK RESULT
for r in "${RESULTS[@]}"; do IFS='|' read -r e w m d <<<"$r"; printf '  %-13s %-34s %s\n' "$e" "$w" "$m"; done
[ "$KEEP" = 1 ] && echo && echo "Kept: containers ${P}_*; work dir $WORK. Remove with: docker rm -f \$(docker ps -aq --filter name=^${P}_); docker volume rm \$(docker volume ls -q --filter name=^${P})"
echo
if [ "$FAILED" = 0 ]; then echo "All checks passed."; else echo "Some checks FAILED."; fi
exit $FAILED
