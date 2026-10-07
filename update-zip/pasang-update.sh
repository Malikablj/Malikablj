#!/usr/bin/env bash
# =============================================================================
# Pemasang update PIK Marketing Control (ikut di dalam pik-update.zip).
#
# Cara pakai di cPanel (langkah yang SAMA untuk setiap update):
#   1. Upload pik-update.zip ke folder home (folder paling atas di File Manager,
#      tempat folder public_html berada). Timpa bila file lama sudah ada.
#   2. cPanel › Terminal, tempel:
#        cd ~ && rm -rf pik-update && unzip -oq pik-update.zip -d pik-update && bash pik-update/pasang-update.sh
#
# Kembalikan file ke versi sebelum update terakhir:
#        cd ~ && bash pik-update/pasang-update.sh --rollback
#
# Yang dilakukan (berhenti otomatis bila ada langkah yang gagal):
#   1. mencari folder aplikasi (yang berisi .env) di home Anda
#   2. backup file aplikasi + database ke ~/pik-backup/
#   3. memeriksa semua file PHP baru dengan PHP server
#   4. menimpa file aplikasi — .env, .htaccess utama, dan folder storage/ TIDAK disentuh
#   5. menghapus file lama yang sudah tidak dipakai (daftar: hapus-file-lama.txt)
#   6. menjalankan migrasi database (php database/migrate.php), tanpa menghapus data
#
# Opsional (bila deteksi otomatis gagal), tulis sebelum "bash":
#   APP_DIR=/home/USER/marketing.permataindokemas.com   folder aplikasi
#   PHP_BIN=/opt/cpanel/ea-php83/root/usr/bin/php          PHP CLI 8.1+
# =============================================================================

set -eo pipefail

SUBDIR="marketing.permataindokemas.com"
BACKUP_DIR="$HOME/pik-backup"
MODE="${1:-update}"
PKG_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
SRC="$PKG_DIR/$SUBDIR"

if [ -t 1 ]; then G=$'\e[32m'; R=$'\e[31m'; Y=$'\e[33m'; B=$'\e[1m'; N=$'\e[0m'; else G=''; R=''; Y=''; B=''; N=''; fi
step() { printf '\n%s==> %s%s\n' "$B" "$1" "$N"; }
ok()   { printf '    %s✓%s %s\n' "$G" "$N" "$1"; }
warn() { printf '    %s!%s %s\n' "$Y" "$N" "$1"; }
fail() { printf '\n%sGAGAL:%s %s\n' "$R" "$N" "$1" >&2; exit 1; }

CNF=""
cleanup() {
    [ -n "$CNF" ] && rm -f "$CNF"
    return 0
}
trap cleanup EXIT

# ----------------------------------------------------------------------------- folder aplikasi
find_app_dir() {
    if [ -n "${APP_DIR:-}" ]; then
        APP_DIR="${APP_DIR%/}"
        [ -f "$APP_DIR/app/helpers/Migrator.php" ] || fail "APP_DIR=$APP_DIR bukan folder aplikasi PIK (app/helpers/Migrator.php tidak ada)."
        return
    fi
    local found="" d f
    for d in "$HOME/$SUBDIR" "$HOME/public_html/$SUBDIR" "$HOME/public_html"; do
        if [ -f "$d/app/helpers/Migrator.php" ] && [ -f "$d/.env" ]; then
            found="$d"
            break
        fi
    done
    if [ -z "$found" ]; then
        local list=""
        while IFS= read -r f; do
            d="$(dirname "$(dirname "$(dirname "$f")")")"
            if [ -f "$d/.env" ] && [ -f "$d/config/permissions.php" ]; then
                list="$list$d"$'\n'
            fi
        done < <(find "$HOME" -maxdepth 5 -path '*/app/helpers/Migrator.php' -not -path "$BACKUP_DIR/*" -not -path "$PKG_DIR/*" 2>/dev/null || true)
        list="$(printf '%s' "$list" | sed '/^$/d' | sort -u)"
        local count
        count="$(printf '%s' "$list" | grep -c . || true)"
        if [ "$count" = "1" ]; then
            found="$list"
        elif [ "$count" = "0" ]; then
            fail "Folder aplikasi tidak ditemukan di $HOME. Jalankan ulang dengan APP_DIR=/path/folder-aplikasi sebelum kata bash."
        else
            printf '%s\n' "Ditemukan beberapa folder aplikasi:" "$list" >&2
            fail "Pilih salah satu dengan APP_DIR=/path/folder-aplikasi sebelum kata bash, lalu jalankan ulang."
        fi
    fi
    APP_DIR="$found"
}

# ----------------------------------------------------------------------------- PHP CLI 8.1+
find_php() {
    local p
    for p in "${PHP_BIN:-}" /opt/cpanel/ea-php83/root/usr/bin/php /opt/cpanel/ea-php84/root/usr/bin/php \
             /opt/cpanel/ea-php82/root/usr/bin/php /opt/cpanel/ea-php81/root/usr/bin/php \
             "$(command -v php 2>/dev/null || true)" /usr/local/bin/php /usr/bin/php; do
        [ -n "$p" ] && [ -x "$p" ] || continue
        if "$p" -r 'exit(PHP_VERSION_ID >= 80100 && extension_loaded("pdo_mysql") ? 0 : 1);' >/dev/null 2>&1; then
            PHP="$p"
            return
        fi
    done
    fail "PHP 8.1+ (dengan pdo_mysql) tidak ditemukan. Jalankan ulang dengan PHP_BIN=/opt/cpanel/ea-php83/root/usr/bin/php sebelum kata bash."
}

# ----------------------------------------------------------------------------- rollback
if [ "$MODE" = "--rollback" ]; then
    step "Mengembalikan file aplikasi dari backup terakhir"
    find_app_dir
    NAME="$(basename "$APP_DIR")"
    LAST="$(ls -1t "$BACKUP_DIR"/files-"$NAME"-*.tar.gz 2>/dev/null | head -n 1 || true)"
    [ -n "$LAST" ] || fail "Backup file tidak ditemukan di $BACKUP_DIR."
    tar -xzf "$LAST" -C "$(dirname "$APP_DIR")"
    ok "File dikembalikan dari $LAST"
    printf '\n%sSelesai.%s Aplikasi kembali ke versi sebelum update. Struktur database tidak perlu dikembalikan\n' "$G" "$N"
    printf '(migrasi hanya menambah kolom/tabel). Backup database tetap ada di %s bila diperlukan.\n' "$BACKUP_DIR"
    exit 0
fi
[ "$MODE" = "update" ] || fail "Pilihan tidak dikenal: $MODE (jalankan tanpa tambahan, atau dengan --rollback)."

[ -f "$SRC/app/helpers/Migrator.php" ] || fail "Isi paket tidak lengkap: folder $SUBDIR tidak ada di samping pasang-update.sh. Ekstrak ulang pik-update.zip."
printf '%sUpdate PIK Marketing Control%s\n' "$B" "$N"
[ -f "$PKG_DIR/VERSI.txt" ] && sed 's/^/  /' "$PKG_DIR/VERSI.txt"
for tool in tar gzip mysqldump; do
    command -v "$tool" >/dev/null 2>&1 || fail "Perintah '$tool' tidak tersedia di server ini."
done

step "1/6 Mencari folder aplikasi & PHP"
find_app_dir
find_php
ok "Folder aplikasi : $APP_DIR"
ok "PHP             : $PHP ($("$PHP" -r 'echo PHP_VERSION;'))"
CURRENT="$(grep -o "VERSION = '[0-9.]*'" "$APP_DIR/app/helpers/Migrator.php" | head -n 1 | cut -d"'" -f2 || true)"
NEW="$(grep -o "VERSION = '[0-9.]*'" "$SRC/app/helpers/Migrator.php" | head -n 1 | cut -d"'" -f2 || true)"
ok "Versi skema database: ${CURRENT:-?} → ${NEW:-?}"

# ----------------------------------------------------------------------------- backup
step "2/6 Backup file & database ke $BACKUP_DIR"
mkdir -p "$BACKUP_DIR"
chmod 700 "$BACKUP_DIR"
TS="$(date +%Y%m%d-%H%M%S)"
NAME="$(basename "$APP_DIR")"
FILES_BACKUP="$BACKUP_DIR/files-$NAME-$TS.tar.gz"
DB_BACKUP="$BACKUP_DIR/db-$NAME-$TS.sql.gz"
tar -czf "$FILES_BACKUP" -C "$(dirname "$APP_DIR")" --exclude="$NAME/storage/logs" --exclude="$NAME/storage/cache" "$NAME"
ok "File     : $FILES_BACKUP ($(du -h "$FILES_BACKUP" | cut -f1))"

CNF="$(mktemp "$BACKUP_DIR/.my-XXXXXX.cnf")"
chmod 600 "$CNF"
# Kredensial dibaca dari .env memakai konfigurasi aplikasi sendiri (tidak ditampilkan di layar).
DB_NAME="$("$PHP" -r '
    define("APP_ROOT", $argv[1]);
    require $argv[1] . "/app/bootstrap.php";
    $c = config("database");
    $q = static fn ($v): string => "\"" . addcslashes((string) $v, "\\\"") . "\"";
    $out = "[client]\nuser=" . $q($c["username"]) . "\npassword=" . $q($c["password"]) . "\n";
    $out .= !empty($c["socket"]) ? "socket=" . $q($c["socket"]) . "\n" : "host=" . $q($c["host"]) . "\nport=" . (int) $c["port"] . "\n";
    file_put_contents($argv[2], $out);
    echo $c["database"];
' "$APP_DIR" "$CNF")" || fail "Tidak dapat membaca konfigurasi database dari $APP_DIR/.env"
[ -n "$DB_NAME" ] || fail "DB_DATABASE kosong di .env"
if ! mysqldump --defaults-extra-file="$CNF" --single-transaction --no-tablespaces --skip-lock-tables "$DB_NAME" | gzip > "$DB_BACKUP"; then
    rm -f "$DB_BACKUP"
    fail "Backup database '$DB_NAME' gagal. Update dibatalkan — tidak ada file yang diubah."
fi
gzip -dc "$DB_BACKUP" | grep -q "CREATE TABLE" || fail "Backup database kosong/tidak valid. Update dibatalkan — tidak ada file yang diubah."
ok "Database : $DB_BACKUP ($(du -h "$DB_BACKUP" | cut -f1))"
rm -f "$CNF"
CNF=""

# ----------------------------------------------------------------------------- cek syntax
step "3/6 Memeriksa file PHP baru dengan PHP server"
ERRORS="$(find "$SRC" -name '*.php' -print0 | xargs -0 -n 1 "$PHP" -l 2>&1 | grep -v '^No syntax errors' || true)"
[ -z "$ERRORS" ] || { printf '%s\n' "$ERRORS" >&2; fail "Ada file PHP yang tidak cocok dengan PHP server. Tidak ada file yang diubah."; }
ok "Semua file PHP valid"

# ----------------------------------------------------------------------------- pasang file
step "4/6 Memasang file baru (.env, .htaccess utama & storage/ tidak disentuh)"
for dir in app config cron database public tests; do
    [ -d "$SRC/$dir" ] || continue
    mkdir -p "$APP_DIR/$dir"
    cp -Rf "$SRC/$dir/." "$APP_DIR/$dir/"
done
for file in README.md .env.example .gitignore robots.txt; do
    [ -f "$SRC/$file" ] && cp -f "$SRC/$file" "$APP_DIR/$file"
done
ok "File aplikasi diperbarui"

step "5/6 Menghapus file lama yang tidak dipakai"
REMOVED=0
if [ -f "$PKG_DIR/hapus-file-lama.txt" ]; then
    while IFS= read -r old || [ -n "$old" ]; do
        old="${old%$'\r'}"
        case "$old" in ''|'#'*) continue ;; esac
        case "$old" in /*|*..*|.env|storage*|.htaccess) warn "Dilewati (tidak aman): $old"; continue ;; esac
        if [ -e "$APP_DIR/$old" ]; then
            rm -rf "${APP_DIR:?}/$old"
            rmdir "$(dirname "$APP_DIR/$old")" 2>/dev/null || true   # folder yang jadi kosong
            ok "Dihapus: $old"
            REMOVED=$((REMOVED + 1))
        fi
    done < "$PKG_DIR/hapus-file-lama.txt"
fi
[ "$REMOVED" -gt 0 ] || ok "Tidak ada file lama yang perlu dihapus"

# ----------------------------------------------------------------------------- migrasi
step "6/6 Migrasi database"
if ! (cd "$APP_DIR" && "$PHP" database/migrate.php); then
    printf '\n%sMigrasi gagal.%s Kembalikan file dengan:  cd ~ && bash pik-update/pasang-update.sh --rollback\n' "$R" "$N" >&2
    printf 'lalu kirim pesan error di atas.\n' >&2
    exit 1
fi

printf '\n%s%sUpdate selesai.%s\n' "$G" "$B" "$N"
cat <<EOF
  Backup file     : $FILES_BACKUP
  Backup database : $DB_BACKUP

Bila ada masalah, kembalikan file ke versi sebelumnya dengan:
  cd ~ && bash pik-update/pasang-update.sh --rollback
EOF
if [ -f "$PKG_DIR/LANGKAH-SETELAH-UPDATE.txt" ]; then
    printf '\n%sLangkah berikutnya:%s\n' "$B" "$N"
    sed 's/^/  /' "$PKG_DIR/LANGKAH-SETELAH-UPDATE.txt"
fi
