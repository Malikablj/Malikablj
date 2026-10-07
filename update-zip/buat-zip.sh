#!/usr/bin/env bash
# =============================================================================
# Membuat update-zip/pik-update.zip dari versi aplikasi yang sudah di-commit.
# (Untuk developer — user cukup meng-upload pik-update.zip ke cPanel.)
#
#   bash update-zip/buat-zip.sh
#
# Isi zip:
#   pasang-update.sh               pemasang (dijalankan user di cPanel › Terminal)
#   marketing.permataindokemas.com/ seluruh file aplikasi (tanpa .env & data storage)
#   hapus-file-lama.txt            file yang pernah dihapus dari repo → dihapus juga di server
#   LANGKAH-SETELAH-UPDATE.txt     catatan rilis ini (diambil dari update-zip/, bila ada)
#   VERSI.txt                      tanggal build, commit, versi skema database
# =============================================================================

set -eo pipefail

ROOT="$(git -C "$(dirname "${BASH_SOURCE[0]}")" rev-parse --show-toplevel)"
APP="marketing.permataindokemas.com"
HERE="$ROOT/update-zip"
OUT="$HERE/pik-update.zip"

cd "$ROOT"
if ! git diff --quiet HEAD -- "$APP" "$HERE/pasang-update.sh" || [ -n "$(git ls-files --others --exclude-standard -- "$APP")" ]; then
    echo "Commit dulu perubahan di $APP / update-zip/pasang-update.sh — zip selalu dibuat dari versi yang sudah di-commit." >&2
    exit 1
fi

TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT
PKG="$TMP/pkg"
mkdir -p "$PKG"

git archive HEAD "$APP" | tar -x -C "$PKG"
cp "$HERE/pasang-update.sh" "$PKG/pasang-update.sh"
chmod 755 "$PKG/pasang-update.sh"
[ -f "$HERE/LANGKAH-SETELAH-UPDATE.txt" ] && cp "$HERE/LANGKAH-SETELAH-UPDATE.txt" "$PKG/"

{
    echo "# File yang sudah dihapus dari aplikasi; ikut dihapus di server saat update (bila masih ada)."
    git log --diff-filter=D --name-only --pretty=format: HEAD -- "$APP" | sed '/^$/d' | sort -u | while IFS= read -r f; do
        git cat-file -e "HEAD:$f" 2>/dev/null || printf '%s\n' "${f#"$APP"/}"
    done
} > "$PKG/hapus-file-lama.txt"

SCHEMA="$(grep -o "VERSION = '[0-9.]*'" "$PKG/$APP/app/helpers/Migrator.php" | head -n 1 | cut -d"'" -f2)"
{
    echo "Dibuat      : $(date '+%Y-%m-%d %H:%M')"
    echo "Commit      : $(git rev-parse --short HEAD) — $(git log -1 --format=%s)"
    echo "Skema DB    : $SCHEMA"
} > "$PKG/VERSI.txt"

rm -f "$OUT"
(cd "$PKG" && zip -qr -X "$OUT" .)
echo "Selesai: $OUT ($(du -h "$OUT" | cut -f1))"
sed 's/^/  /' "$PKG/VERSI.txt"
