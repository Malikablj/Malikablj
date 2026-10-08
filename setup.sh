#!/usr/bin/env bash
# NPD Project Control — instalasi / pemeriksaan ulang lewat Terminal cPanel.
#   cd ~/npd.permataindokemas.com && bash setup.sh
# Aman dijalankan berulang (tidak menghapus data). PHP lain: PHP_BIN=/opt/cpanel/ea-php83/root/usr/bin/php bash setup.sh
# Semua pertanyaan ditangani di sini (bash), sehingga PHP tidak butuh proc_open/system/posix yang sering dimatikan hosting.
set -eu
cd "$(dirname "$0")"
APP_DIR="$(pwd)"

find_php() {
    for p in "${PHP_BIN:-}" /usr/local/bin/php php /opt/cpanel/ea-php83/root/usr/bin/php /opt/cpanel/ea-php82/root/usr/bin/php \
             /opt/cpanel/ea-php84/root/usr/bin/php php8.3 php8.2 php8.4; do
        [ -n "$p" ] || continue
        if command -v "$p" >/dev/null 2>&1 || [ -x "$p" ]; then
            if "$p" -r 'exit(PHP_VERSION_ID >= 80200 && PHP_SAPI === "cli" ? 0 : 1);' >/dev/null 2>&1; then
                command -v "$p" 2>/dev/null || echo "$p"
                return 0
            fi
        fi
    done
    return 1
}

if ! PHP="$(find_php)"; then
    echo "PHP 8.2 atau lebih baru (CLI) tidak ditemukan."
    echo "Pilih PHP 8.2/8.3 untuk domain ini di cPanel › MultiPHP Manager, lalu ulangi,"
    echo "atau tunjukkan lokasinya: PHP_BIN=/opt/cpanel/ea-php83/root/usr/bin/php bash setup.sh"
    exit 1
fi
echo "PHP CLI: $PHP ($("$PHP" -r 'echo PHP_VERSION;'))"

env_value() { # nilai KEY dari .env (tanpa kutip)
    sed -n "s/^$1=//p" .env 2>/dev/null | tail -n 1 | sed "s/^['\"]//; s/['\"]\$//"
}
is_placeholder() {
    case "$(printf '%s' "$1" | tr '[:upper:]' '[:lower:]')" in ''|usernamenpd|passwordnpd|root) return 0 ;; *) return 1 ;; esac
}

# --- .env & data database
if [ ! -f .env ]; then
    if [ -f .env.production ]; then cp .env.production .env; else cp .env.example .env; fi
    chmod 600 .env
    echo "  .env dibuat."
fi
if is_placeholder "$(env_value DB_USER)" || is_placeholder "$(env_value DB_PASS)"; then
    echo
    echo "Data database (cPanel › MySQL Databases). User cPanel biasanya berawalan nama akun, mis. perb8631_namauser."
    DEF_DB="$(env_value DB_NAME)"; DEF_DB="${DEF_DB:-perb8631_npd}"
    read -r -p "  Nama database [$DEF_DB]: " NPD_SETUP_DB_NAME
    NPD_SETUP_DB_NAME="${NPD_SETUP_DB_NAME:-$DEF_DB}"
    read -r -p "  User database: " NPD_SETUP_DB_USER
    read -r -s -p "  Password database (tidak tampil): " NPD_SETUP_DB_PASS; echo
    if [ -z "$NPD_SETUP_DB_USER" ] || [ -z "$NPD_SETUP_DB_PASS" ]; then echo "  User dan password database wajib diisi."; exit 1; fi
    export NPD_SETUP_DB_NAME NPD_SETUP_DB_USER NPD_SETUP_DB_PASS
fi

# --- 1–3: PHP, .env, storage, koneksi database
"$PHP" bin/setup.php --phase=prepare
unset NPD_SETUP_DB_PASS

# --- 4: tabel & data awal + migrasi (tidak menghapus data)
echo; echo "== 4/6 Tabel & data awal"
"$PHP" bin/install.php
"$PHP" bin/migrate.php

# --- 5: Admin pertama
echo; echo "== 5/6 Admin pertama"
if "$PHP" bin/setup.php --phase=has-admin; then
    :
else
    echo "  Buat akun Admin pertama (untuk login ke aplikasi). Password min. 8 karakter, huruf + angka."
    for attempt in 1 2 3; do
        read -r -p "  Nama [Admin NPD]: " A_NAME; A_NAME="${A_NAME:-Admin NPD}"
        read -r -p "  Email: " A_EMAIL
        read -r -s -p "  Password (tidak tampil): " A_PASS; echo
        read -r -s -p "  Ulangi password: " A_PASS2; echo
        if [ "$A_PASS" != "$A_PASS2" ]; then echo "  Password tidak sama, ulangi."; continue; fi
        if NPD_ADMIN_PASSWORD="$A_PASS" "$PHP" bin/create-admin.php --name="$A_NAME" --email="$A_EMAIL" --title="Admin Sistem"; then
            break
        fi
        [ "$attempt" = 3 ] && { echo "  Gagal membuat Admin. Ulangi: bash setup.sh"; exit 1; }
    done
    unset A_PASS A_PASS2
fi

# --- 6: Cron Jobs & pemeriksaan akhir
"$PHP" bin/setup.php --phase=finish --php="$PHP"
APP_URL="$(env_value APP_URL)"
set +e
if [ "${APP_URL#https://}" != "$APP_URL" ]; then
    "$PHP" bin/check-deployment.php --url="$APP_URL"
else
    "$PHP" bin/check-deployment.php
fi
CODE=$?
set -e
echo
if [ "$CODE" = 0 ]; then
    echo "Selesai. Buka ${APP_URL:-situs Anda} lalu login dengan akun Admin."
else
    echo "Ada pemeriksaan GAGAL di atas — perbaiki lalu jalankan ulang: bash setup.sh  (folder: $APP_DIR)"
fi
exit "$CODE"
