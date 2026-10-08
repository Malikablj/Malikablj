#!/usr/bin/env bash
# Membuat paket rilis siap-unggah (zip) untuk hosting cPanel / server biasa, dari commit HEAD.
#
#   RELEASE_APP_URL=https://npd.permataindokemas.com RELEASE_DB_NAME=perb8631_npd \
#   RELEASE_DB_USER=usernamenpd RELEASE_DB_PASS=passwordnpd  bash bin/build-release.sh [dist/nama.zip]
#
# Isi zip (tanpa folder induk, langsung diekstrak di folder domain):
#   kode aplikasi + vendor produksi (composer --no-dev, font mPDF hanya DejaVu yang dipakai PDF),
#   .env.production (disalin setup.sh menjadi .env hanya bila .env belum ada, sehingga unggah ulang versi baru
#   tidak menimpa konfigurasi server; APP_KEY dibuat di server), storage/ kosong, setup.sh, INSTALL-CPANEL.md.
# Tidak ikut: .git, tests/, legacy/, phpunit.xml, data runtime (storage/*), .env lokal.
set -eu
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
OUT="${1:-$ROOT/dist/npd-project-control-cpanel.zip}"
case "$OUT" in /*) ;; *) OUT="$PWD/$OUT" ;; esac
APP_URL="${RELEASE_APP_URL:-}"
WORK="$(mktemp -d)"
STAGE="$WORK/app"
trap 'rm -rf "$WORK"' EXIT
mkdir -p "$STAGE" "$(dirname "$OUT")"

cd "$ROOT"
if [ -n "$(git status --porcelain -- . ':!dist')" ]; then
    echo "Peringatan: ada perubahan belum di-commit — paket dibuat dari HEAD ($(git rev-parse --short HEAD))." >&2
fi
git archive HEAD | tar -x -C "$STAGE"
rm -rf "$STAGE/tests" "$STAGE/legacy" "$STAGE/phpunit.xml" "$STAGE/.github" "$STAGE/.phpunit.cache"

echo "== composer install --no-dev"
(cd "$STAGE" && composer install --no-dev --optimize-autoloader --no-interaction --no-progress --prefer-dist -q)

echo "== pangkas vendor"
# PDF memakai font DejaVu saja (PdfFactory default_font = dejavusans); font lain ±80 MB tidak dipakai
find "$STAGE/vendor/mpdf/mpdf/ttfonts" -type f ! -name 'DejaVu*' -delete
# dokumentasi/sampel paket tidak dibutuhkan saat berjalan
for d in docs doc samples examples .github; do
    find "$STAGE/vendor" -mindepth 3 -maxdepth 3 -type d -name "$d" -prune -exec rm -rf {} +
done
cp "$STAGE/config/.htaccess" "$STAGE/vendor/.htaccess"

echo "== .env.production"
ENVF="$STAGE/.env.production"
cp "$STAGE/.env.example" "$ENVF"
set_env() { # set_env KEY VALUE (nilai tanpa baris baru; dikutip tunggal bila perlu)
    local k="$1" v="$2" q
    if printf '%s' "$v" | grep -Eq '^[A-Za-z0-9_.:/@+=,-]*$'; then q="$v"; else q="'$v'"; fi
    if grep -q "^$k=" "$ENVF"; then
        K="$k" Q="$q" perl -0pi -e 's/^\Q$ENV{K}\E=.*$/$ENV{K}=$ENV{Q}/m' "$ENVF"
    else
        printf '%s=%s\n' "$k" "$q" >> "$ENVF"
    fi
}
set_env APP_ENV production
set_env APP_DEBUG 0
set_env SESSION_SECURE 1
set_env DB_HOST localhost
[ -n "$APP_URL" ] && set_env APP_URL "$APP_URL"
[ -n "${RELEASE_DB_NAME:-}" ] && set_env DB_NAME "$RELEASE_DB_NAME"
[ -n "${RELEASE_DB_USER:-}" ] && set_env DB_USER "$RELEASE_DB_USER"
[ -n "${RELEASE_DB_PASS:-}" ] && set_env DB_PASS "$RELEASE_DB_PASS"

echo "== storage & izin file"
for d in documents exports logs cache sessions backups; do
    mkdir -p "$STAGE/storage/$d"
    [ -f "$STAGE/storage/$d/.gitkeep" ] || : > "$STAGE/storage/$d/.gitkeep"
done
find "$STAGE" -type d -exec chmod 755 {} +
find "$STAGE" -type f -exec chmod 644 {} +
chmod 755 "$STAGE/setup.sh" "$STAGE/bin/build-release.sh"
chmod 600 "$ENVF"
printf '%s\n' "$(git rev-parse HEAD)" > "$STAGE/RELEASE"

echo "== zip"
rm -f "$OUT"
(cd "$STAGE" && zip -qr -X "$OUT" . )
SIZE=$(du -h "$OUT" | cut -f1)
SUM=$(sha256sum "$OUT" | cut -d' ' -f1)
FILES=$(unzip -Z1 "$OUT" | wc -l)
echo "Selesai: $OUT ($SIZE, $FILES file, commit $(git rev-parse --short HEAD))"
echo "SHA-256: $SUM"
