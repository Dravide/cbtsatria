#!/usr/bin/env bash
#
# Pulihkan satu arsip cadangan ke satu basis data - Fase 9, PRD §19.4.
#
# =====================================================================
# Basis data tujuannya WAJIB disebut, dan WAJIB bernama throwaway
# =====================================================================
# Ini satu-satunya pengaman yang berdiri di antara skrip ini dan kehilangan
# seluruh data sekolah, dan ia sengaja dibuat menyebalkan.
#
# Pemulihan menulis DROP TABLE. Pada basis data yang salah, hasilnya bukan
# galat melainkan sekolah yang kehilangan nilainya - dan pemulihannya
# berjalan tanpa bertanya karena memang itu tugasnya. Satu salah ketik pada
# `DB_DATABASE` di tengah malam adalah satu-satunya cara kejadian itu
# berlangsung.
#
# Karena itu basis data tujuannya diperiksa DUA kali:
#   1. harus disebut eksplisit sebagai argumen (tidak ada nilai bawaan)
#   2. namanya harus berakhiran `_test` atau `_pulih`
#
# Nama basis data produksi yang tidak sengaja diketik akan tertolak sebelum
# satu byte pun ditulis. Pemulihan yang BENAR-BENAR ingin menimpa produksi
# (bencana sungguhan) memakai `--paksa`, dan flag itu ditulis dengan sadar.
#
# =====================================================================
# Pemakaian
# =====================================================================
#   ./scripts/backup/cbt-pulih.sh /var/backups/cbt/cbt-20261104-030000.tar.gz cbt_satria_pulih
#
#   --paksa     izinkan target bernama lain (bencana sungguhan)
#   --tanpa-media  lewati pemulihan storage/app/private
set -euo pipefail

PAKSA=0
TANPA_MEDIA=0
ARG=()

for a in "$@"; do
    case "$a" in
        --paksa) PAKSA=1 ;;
        --tanpa-media) TANPA_MEDIA=1 ;;
        *) ARG+=("$a") ;;
    esac
done

set -- "${ARG[@]+"${ARG[@]}"}"

[ $# -ge 2 ] || {
    echo "Pemakaian: $0 <arsip.tar.gz> <basis-data-tujuan> [--paksa] [--tanpa-media]" >&2
    exit 2
}

ARSIP="$1"
TUJUAN="$2"

DB_HOST="${DB_HOST:-127.0.0.1}"
DB_PORT="${DB_PORT:-3306}"
DB_USERNAME="${DB_USERNAME:-root}"
DB_PASSWORD="${DB_PASSWORD:-}"
MYSQL_BIN="${MYSQL_BIN:-}"
MYSQL="${MYSQL_BIN:+$MYSQL_BIN/}mysql"

AKAR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"

cetak() { printf '[%s] %s\n' "$(date +%H:%M:%S)" "$*"; }
gagal() { printf 'GALAT: %s\n' "$*" >&2; exit 1; }

# ------------------------------------------------------------ pengaman nama

[ -f "$ARSIP" ] || gagal "Arsip '$ARSIP' tidak ada."

case "$TUJUAN" in
    *_test|*_pulih) ;;
    *)
        if [ "$PAKSA" -ne 1 ]; then
            gagal "Target '$TUJUAN' BUKAN basis data throwaway. Namanya harus berakhiran '_test' atau '_pulih'. Bila ini memang pemulihan bencana, ulangi dengan --paksa."
        fi
        cetak "PERINGATAN: --paksa dipakai - menimpa '$TUJUAN'."
        ;;
esac

command -v "$MYSQL" >/dev/null 2>&1 || gagal "mysql tidak ditemukan di '$MYSQL'. Setel MYSQL_BIN."

TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

KREDENSIAL="$TMP/kredensial.cnf"
cat > "$KREDENSIAL" <<CNF
[client]
host=$DB_HOST
port=$DB_PORT
user=$DB_USERNAME
password="$DB_PASSWORD"
CNF
chmod 600 "$KREDENSIAL"

cetak "Membuka '$ARSIP' ..."
tar -xzf "$ARSIP" -C "$TMP"

[ -f "$TMP/basis-data.sql" ] || gagal "Arsip tidak memuat 'basis-data.sql' - bukan arsip dari cbt-backup.sh."

# -------------------------------------------------------------- basis data

cetak "Membuat '$TUJUAN' bila belum ada ..."
"$MYSQL" --defaults-extra-file="$KREDENSIAL" \
    -e "CREATE DATABASE IF NOT EXISTS \`$TUJUAN\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci" \
    || gagal "Tidak bisa membuat basis data '$TUJUAN'."

cetak "Memulihkan isinya (DROP TABLE lalu impor) ..."

if ! "$MYSQL" --defaults-extra-file="$KREDENSIAL" "$TUJUAN" < "$TMP/basis-data.sql"; then
    gagal "Impor gagal. Basis data '$TUJUAN' berisi pemulihan yang TIDAK lengkap."
fi

# ---------------------------------------------------------------- media

if [ "$TANPA_MEDIA" -eq 1 ]; then
    cetak "Media dilewati (--tanpa-media)."
elif [ -f "$TMP/media.tar.gz" ]; then
    cetak "Memulihkan media privat ..."

    # Media ditulis ke direktori proyek yang sedang dipakai. Berkas lama
    # yang namanya sama akan tertimpa; berkas yang TIDAK ada di arsip tidak
    # dihapus - pemulihan yang menghapus berkas di luar arsipnya bisa
    # membuang media yang lebih baru daripada cadangannya.
    tar -xzf "$TMP/media.tar.gz" -C "$AKAR/storage/app"
else
    cetak "PERINGATAN: arsip tidak memuat media."
fi

# ---------------------------------------------------------------- .env

if [ -f "$TMP/env.cadangan" ]; then
    # .env TIDAK ditimpa diam-diam. Ia memuat kredensial basis data mesin
    # ASAL, dan menimpanya di mesin lain berarti aplikasinya menunjuk ke
    # basis data yang salah - tepat sesudah dipulihkan, saat orang paling
    # tidak menduganya.
    cp "$TMP/env.cadangan" "$AKAR/.env.dari-cadangan"
    chmod 600 "$AKAR/.env.dari-cadangan"
    cetak "'.env' arsip disimpan sebagai '.env.dari-cadangan' - TIDAK ditimpa. Bandingkan manual."
fi

cetak "Selesai. Basis data '$TUJUAN' sudah dipulihkan."

if [ -f "$TMP/baris.txt" ]; then
    echo "--- jumlah baris yang dicatat saat pencadangan ---"
    cat "$TMP/baris.txt"
fi

echo "--- jumlah baris SESUDAH pemulihan ---"
for t in exam_attempts exam_results report_exports users; do
    n=$("$MYSQL" --defaults-extra-file="$KREDENSIAL" -N -B -e "SELECT COUNT(*) FROM \`$t\`" "$TUJUAN" 2>/dev/null || echo '?')
    printf '%-16s %s\n' "$t" "$n"
done
