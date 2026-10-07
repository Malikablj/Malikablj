#!/usr/bin/env bash
# =============================================================================
# Update otomatis PIK Marketing Control di hosting cPanel (menu Terminal).
#
# Cukup tempel SATU perintah ini di cPanel › Terminal:
#
#   curl -fsSL https://raw.githubusercontent.com/Malikablj/Malikablj/claude/magical-cori-1m350e/marketing.permataindokemas.com/database/update-cpanel.sh | bash
#
# Yang dilakukan skrip (berhenti otomatis bila ada langkah yang gagal):
#   1. mencari folder aplikasi (yang berisi .env) di home Anda
#   2. backup file aplikasi + database ke ~/pik-backup/
#   3. mengunduh versi baru dari GitHub
#   4. memeriksa semua file PHP baru (syntax) dengan PHP di server
#   5. menimpa file aplikasi — .env, .htaccess utama, dan folder storage/ TIDAK disentuh
#   6. menghapus file menu Finance yang sudah tidak dipakai
#   7. menjalankan migrasi database (php database/migrate.php), tanpa menghapus data
#
# Kembalikan file ke versi sebelum update (memakai backup terakhir):
#   curl -fsSL <url skrip yang sama> | bash -s -- --rollback
#
# Opsional (bila deteksi otomatis gagal):
#   APP_DIR=/home/USER/marketing.permataindokemas.com   folder aplikasi
#   PHP_BIN=/opt/cpanel/ea-php83/root/usr/bin/php          PHP CLI 8.1+
#   REF=<commit/branch>                                    versi yang diunduh
#   contoh: curl -fsSL <url> | APP_DIR=/home/USER/folder bash
# =============================================================================

set -eo pipefail

REPO="Malikablj/Malikablj"
REF="${REF:-claude/magical-cori-1m350e}"
SUBDIR="marketing.permataindokemas.com"
BACKUP_DIR="$HOME/pik-backup"
MODE="${1:-update}"

OLD_FILES=(
    app/controllers/InvoiceController.php
    app/controllers/PoFinancialController.php
    app/models/Invoice.php
    app/models/PoFinancial.php
    app/views/customers/tabs/invoices.php
    app/views/invoices
    app/views/po_financials
)

if [ -t 1 ]; then G=$'\e[32m'; R=$'\e[31m'; Y=$'\e[33m'; B=$'\e[1m'; N=$'\e[0m'; else G=''; R=''; Y=''; B=''; N=''; fi
step() { printf '\n%s==> %s%s\n' "$B" "$1" "$N"; }
ok()   { printf '    %s✓%s %s\n' "$G" "$N" "$1"; }
warn() { printf '    %s!%s %s\n' "$Y" "$N" "$1"; }
fail() { printf '\n%sGAGAL:%s %s\n' "$R" "$N" "$1" >&2; exit 1; }

TMP_DIR=""
CNF=""
cleanup() {
    [ -n "$CNF" ] && rm -f "$CNF"
    [ -n "$TMP_DIR" ] && rm -rf "$TMP_DIR"
    return 0
}
trap cleanup EXIT

# ----------------------------------------------------------------------------- 1. folder aplikasi
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
        done < <(find "$HOME" -maxdepth 5 -path '*/app/helpers/Migrator.php' -not -path "$BACKUP_DIR/*" -not -path '*/.pik-update-*' 2>/dev/null || true)
        list="$(printf '%s' "$list" | sed '/^$/d' | sort -u)"
        local count
        count="$(printf '%s' "$list" | grep -c . || true)"
        if [ "$count" = "1" ]; then
            found="$list"
        elif [ "$count" = "0" ]; then
            fail "Folder aplikasi tidak ditemukan di $HOME. Jalankan ulang dengan APP_DIR=/path/folder-aplikasi (lihat keterangan di awal skrip)."
        else
            printf '%s\n' "Ditemukan beberapa folder aplikasi:" "$list" >&2
            fail "Pilih salah satu dengan APP_DIR=/path/folder-aplikasi lalu jalankan ulang."
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
    fail "PHP 8.1+ (dengan pdo_mysql) tidak ditemukan. Jalankan ulang dengan PHP_BIN=/opt/cpanel/ea-php83/root/usr/bin/php"
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
    printf '\n%sSelesai.%s Aplikasi kembali ke versi sebelum update. Struktur database baru tidak perlu dikembalikan\n' "$G" "$N"
    printf '(hanya menambah kolom/tabel). Backup database tetap ada di %s bila diperlukan.\n' "$BACKUP_DIR"
    exit 0
fi
[ "$MODE" = "update" ] || fail "Pilihan tidak dikenal: $MODE (gunakan tanpa argumen, atau --rollback)."

printf '%sUpdate PIK Marketing Control%s (versi: %s)\n' "$B" "$N" "$REF"
for tool in curl tar gzip mysqldump; do
    command -v "$tool" >/dev/null 2>&1 || fail "Perintah '$tool' tidak tersedia di server ini."
done

step "1/6 Mencari folder aplikasi & PHP"
find_app_dir
find_php
ok "Folder aplikasi : $APP_DIR"
ok "PHP             : $PHP ($("$PHP" -r 'echo PHP_VERSION;'))"
CURRENT="$(grep -o "VERSION = '[0-9.]*'" "$APP_DIR/app/helpers/Migrator.php" | head -n 1 | cut -d"'" -f2 || true)"
ok "Versi skema saat ini: ${CURRENT:-tidak diketahui}"

# ----------------------------------------------------------------------------- 2. backup
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

# ----------------------------------------------------------------------------- 3. unduh
step "3/6 Mengunduh versi baru dari GitHub"
TMP_DIR="$(mktemp -d "$HOME/.pik-update-XXXXXX")"
if [ -n "${SRC_TARBALL:-}" ]; then
    cp "$SRC_TARBALL" "$TMP_DIR/src.tar.gz"          # untuk pengujian tanpa internet
else
    if printf '%s' "$REF" | grep -Eq '^[0-9a-f]{7,40}$'; then
        URL="https://github.com/$REPO/archive/$REF.tar.gz"
    else
        URL="https://github.com/$REPO/archive/refs/heads/$REF.tar.gz"
    fi
    curl -fsSL --retry 3 -o "$TMP_DIR/src.tar.gz" "$URL" || fail "Gagal mengunduh $URL"
fi
tar -xzf "$TMP_DIR/src.tar.gz" -C "$TMP_DIR"
SRC="$(find "$TMP_DIR" -mindepth 2 -maxdepth 2 -type d -name "$SUBDIR" | head -n 1)"
[ -n "$SRC" ] && [ -f "$SRC/app/helpers/Migrator.php" ] || fail "Isi unduhan tidak sesuai (folder $SUBDIR tidak ada)."
NEW="$(grep -o "VERSION = '[0-9.]*'" "$SRC/app/helpers/Migrator.php" | head -n 1 | cut -d"'" -f2 || true)"
ok "Versi skema baru: ${NEW:-tidak diketahui}"

# ----------------------------------------------------------------------------- 4. cek syntax
step "4/6 Memeriksa file PHP baru dengan PHP server"
ERRORS="$(find "$SRC" -name '*.php' -print0 | xargs -0 -n 1 "$PHP" -l 2>&1 | grep -v '^No syntax errors' || true)"
[ -z "$ERRORS" ] || { printf '%s\n' "$ERRORS" >&2; fail "Ada file PHP yang tidak cocok dengan PHP server. Tidak ada file yang diubah."; }
ok "Semua file PHP valid"

# ----------------------------------------------------------------------------- 5. pasang file
step "5/6 Memasang file baru (.env, .htaccess utama & storage/ tidak disentuh)"
for dir in app config cron database public tests; do
    mkdir -p "$APP_DIR/$dir"
    cp -Rf "$SRC/$dir/." "$APP_DIR/$dir/"
done
for file in README.md .env.example .gitignore robots.txt; do
    [ -f "$SRC/$file" ] && cp -f "$SRC/$file" "$APP_DIR/$file"
done
ok "File aplikasi diperbarui"
for old in "${OLD_FILES[@]}"; do
    if [ -e "$APP_DIR/$old" ]; then
        rm -rf "${APP_DIR:?}/$old"
        ok "Dihapus: $old"
    fi
done

# ----------------------------------------------------------------------------- 6. migrasi
step "6/6 Migrasi database"
if ! (cd "$APP_DIR" && "$PHP" database/migrate.php); then
    printf '\n%sMigrasi gagal.%s Kembalikan file dengan perintah rollback (lihat di bawah), lalu kirim pesan error di atas.\n' "$R" "$N" >&2
    printf '  curl -fsSL https://raw.githubusercontent.com/%s/%s/%s/database/update-cpanel.sh | bash -s -- --rollback\n' "$REPO" "$REF" "$SUBDIR" >&2
    exit 1
fi

printf '\n%s%sUpdate selesai.%s\n' "$G" "$B" "$N"
cat <<EOF
  Backup file     : $FILES_BACKUP
  Backup database : $DB_BACKUP

Langkah berikutnya:
  1. Buka aplikasi di browser dan login sebagai Admin.
  2. Settings › Users → buat akun untuk divisi PPIC, Produksi, dan Gudang.

Bila ada masalah, kembalikan file ke versi sebelumnya dengan:
  curl -fsSL https://raw.githubusercontent.com/$REPO/$REF/$SUBDIR/database/update-cpanel.sh | bash -s -- --rollback
EOF
