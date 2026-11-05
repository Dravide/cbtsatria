#!/usr/bin/env bash
#
# Bukti pemulihan - kriteria keluar Fase 9.
#
# =====================================================================
# Mengapa kriteria keluarnya menuntut DRILL, bukan prosedur
# =====================================================================
# `blueprint-cbt.html:1286` menulis: "prosedur pemulihan sudah dibuktikan
# minimal sekali dari cadangan nyata". Kalimat itu sengaja tidak berbunyi
# "prosedurnya ada", karena cadangan yang tidak pernah dipulihkan bukan
# cadangan - ia berkas. Kegagalan yang paling umum bukan cadangan yang tidak
# dibuat, melainkan cadangan yang ternyata tidak bisa dipulihkan, dan itu
# baru ketahuan pada hari sekolah kehilangan nilainya.
#
# Skrip ini membuktikan empat hal sekaligus, dan keempatnya bisa gagal:
#
#   1. arsipnya bisa dibuka
#   2. dumpnya bisa diimpor
#   3. jumlah baris tabel kunci SAMA dengan sumbernya
#   4. aplikasinya benar-benar bisa membaca hasil pemulihannya
#
# Poin ketiga yang dimaksud kriteria itu. Impor yang "berhasil" tetapi
# kehilangan tabel tidak akan menghasilkan galat apa pun dari `mysql`.
#
# =====================================================================
# Arah perbandingannya: SUMBER vs HASIL, bukan arsip vs hasil
# =====================================================================
# Angka di `baris.txt` dicatat saat pencadangan. Membandingkan hasil pulih
# terhadap angka itu membuktikan arsipnya utuh, tetapi TIDAK membuktikan
# bahwa angka itu masih sesuai dengan basis data yang sekarang. Karena itu
# yang dibandingkan adalah basis data sumber SEBELUM dan SESUDAH.
#
# Konsekuensinya harus disadari: bila ada yang menulis ke basis data sumber
# SELAMA drill ini berjalan, angkanya bisa berbeda dan FAIL-nya jujur -
# bukan bug.
#
# =====================================================================
# Pemakaian
# =====================================================================
#   DB_PORT=3307 MYSQL_BIN=/c/laragon/bin/mysql/mysql-8.4.3-winx64/bin \
#     ./scripts/backup/cbt-uji-pulih.sh
#
# Basis data tujuan SELALU `<sumber>_pulih`, dan itu ditetapkan di sini -
# bukan diterima sebagai argumen. Skrip yang menerima basis data tujuan
# adalah skrip yang suatu hari menerima basis data produksi.
set -euo pipefail

DB_HOST="${DB_HOST:-127.0.0.1}"
DB_PORT="${DB_PORT:-3306}"
DB_DATABASE="${DB_DATABASE:-cbt_satria}"
DB_USERNAME="${DB_USERNAME:-root}"
DB_PASSWORD="${DB_PASSWORD:-}"
MYSQL_BIN="${MYSQL_BIN:-}"
MYSQL="${MYSQL_BIN:+$MYSQL_BIN/}mysql"

CBT_BACKUP_DIR="${CBT_BACKUP_DIR:-/var/backups/cbt}"

AKAR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"

TUJUAN="${DB_DATABASE}_pulih"

TABEL=(exam_attempts exam_results report_exports users)

cetak() { printf '[%s] %s\n' "$(date +%H:%M:%S)" "$*"; }
gagal() { printf 'GAGAL: %s\n' "$*" >&2; exit 1; }

# ------------------------------------------------------------------ arsip

ARSIP="${1:-}"

if [ -z "$ARSIP" ]; then
    # Arsip terbaru, diurutkan menurut nama - dan nama arsipnya memuat cap
    # waktu yang urutannya sama dengan waktu pembuatannya, sehingga
    # pengurutan nama sama dengan pengurutan waktu tanpa memanggil `find
    # -printf` (yang tidak ada di BSD/macOS).
    ARSIP="$(find "$CBT_BACKUP_DIR" -maxdepth 1 -name 'cbt-*.tar.gz' -type f 2>/dev/null | sort | tail -1)"
fi

[ -n "$ARSIP" ] && [ -f "$ARSIP" ] || gagal "Tidak ada arsip di '$CBT_BACKUP_DIR'. Jalankan cbt-backup.sh lebih dulu."

cetak "Arsip: $ARSIP"

# ------------------------------------------------------- pengukuran sumber

KREDENSIAL="$(mktemp)"
trap 'rm -f "$KREDENSIAL"' EXIT

cat > "$KREDENSIAL" <<CNF
[client]
host=$DB_HOST
port=$DB_PORT
user=$DB_USERNAME
password="$DB_PASSWORD"
CNF
chmod 600 "$KREDENSIAL"

hitung() {
    "$MYSQL" --defaults-extra-file="$KREDENSIAL" -N -B \
        -e "SELECT COUNT(*) FROM \`$2\`" "$1" 2>/dev/null || echo '?'
}

cetak "Mengukur sumber '$DB_DATABASE' ..."

declare -A SUMBER

for t in "${TABEL[@]}"; do
    SUMBER[$t]="$(hitung "$DB_DATABASE" "$t")"
    printf '  %-16s %s\n' "$t" "${SUMBER[$t]}"
done

# ------------------------------------------------------------------ pulih

cetak "Memulihkan ke '$TUJUAN' (basis data throwaway) ..."

DB_HOST="$DB_HOST" DB_PORT="$DB_PORT" \
DB_USERNAME="$DB_USERNAME" DB_PASSWORD="$DB_PASSWORD" \
MYSQL_BIN="$MYSQL_BIN" \
    "$AKAR/scripts/backup/cbt-pulih.sh" "$ARSIP" "$TUJUAN" --tanpa-media \
    || gagal "cbt-pulih.sh gagal."

# ------------------------------------------------------------- pembandingan

cetak "Membandingkan ..."

LULUS=1

for t in "${TABEL[@]}"; do
    hasil="$(hitung "$TUJUAN" "$t")"

    if [ "$hasil" = "${SUMBER[$t]}" ]; then
        printf '  OK    %-16s %s\n' "$t" "$hasil"
    else
        printf '  BEDA  %-16s sumber=%s pulih=%s\n' "$t" "${SUMBER[$t]}" "$hasil"
        LULUS=0
    fi
done

# Pemeriksaan keempat: aplikasinya benar-benar bisa membaca hasilnya.
# Jumlah baris yang cocok pada basis data yang skemanya rusak tetap
# menghasilkan angka yang cocok - pemeriksaan ini yang menutupnya.
cetak "Menguji skema hasil pemulihan lewat aplikasi ..."

if DB_DATABASE="$TUJUAN" DB_HOST="$DB_HOST" DB_PORT="$DB_PORT" \
   DB_USERNAME="$DB_USERNAME" DB_PASSWORD="$DB_PASSWORD" \
   php "$AKAR/artisan" migrate:status >/dev/null 2>&1; then
    echo "  OK    migrate:status terbaca"
else
    echo "  BEDA  migrate:status GAGAL pada basis data hasil pemulihan"
    LULUS=0
fi

# ----------------------------------------------------------------- kesimpulan

echo
if [ "$LULUS" -eq 1 ]; then
    echo "PASS - pemulihan menghasilkan jumlah baris yang sama dengan sumbernya."
    echo "       Basis data uji: $TUJUAN (boleh dihapus)."
    exit 0
fi

echo "FAIL - hasil pemulihan TIDAK sama dengan sumbernya."
echo "       Jangan anggap cadangan ini bisa dipakai sebelum selisihnya dijelaskan."
exit 1
