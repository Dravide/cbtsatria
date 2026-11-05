#!/usr/bin/env bash
#
# Cadangan basis data + media privat - Fase 9, PRD §19.4.
#
# =====================================================================
# Apa yang dicadangkan, dan mengapa ketiganya
# =====================================================================
#   1. basis data  - seluruh tabel, lewat `--single-transaction`
#   2. storage/app/private - media soal, berita acara, tanda tangan, ekspor
#   3. .env        - terpisah, karena APP_KEY di dalamnya
#
# Yang ketiga bukan kemewahan. Tanpa `APP_KEY` yang SAMA, seluruh kolom
# terenkripsi di dalam dump - dan seluruh cookie sesi - tidak bisa dibaca.
# Dump yang dipulihkan ke server dengan APP_KEY baru adalah dump yang
# isinya ada tetapi tidak bisa dipakai, dan itu baru ketahuan saat ada yang
# membuka berita acara.
#
# .env disimpan di arsip TERPISAH karena ia memuat kredensial: arsipnya
# sendiri yang bisa dienkripsi atau disimpan di tempat berbeda, tanpa
# menyeret seluruh basis data ke sana.
#
# =====================================================================
# `--single-transaction` adalah syarat, bukan penyetelan
# =====================================================================
# Tanpa itu, `mysqldump` mengunci tabel selama prosesnya berjalan. Di
# sekolah, cadangan terjadwal berjalan pada jam yang sama dengan ujian pagi -
# dan mengunci `exam_attempts` selama tiga puluh detik berarti tiga ratus
# siswa yang jawabannya gagal tersimpan.
#
# `--single-transaction` mengambil snapshot konsisten lewat transaksi
# InnoDB, sehingga penulisan siswa tetap berjalan selama pencadangan.
# Ia hanya bekerja untuk InnoDB, dan itulah mesin yang dipakai seluruh tabel
# di sini.
#
# =====================================================================
# Pemakaian
# =====================================================================
#   DB_PORT=3307 DB_PASSWORD=... ./scripts/backup/cbt-backup.sh
#
# Semua penyetelan lewat env, dengan nilai bawaan yang masuk akal. Skripnya
# ditulis untuk VPS Linux (target deploy), sehingga biner MySQL diambil dari
# PATH; di mesin pengembangan Windows, arahkan `MYSQL_BIN` ke binernya.
set -euo pipefail

# ---------------------------------------------------------------- penyetelan

DB_HOST="${DB_HOST:-127.0.0.1}"
DB_PORT="${DB_PORT:-3306}"
DB_DATABASE="${DB_DATABASE:-cbt_satria}"
DB_USERNAME="${DB_USERNAME:-root}"
DB_PASSWORD="${DB_PASSWORD:-}"

# Direktori tujuan. Bawaannya di LUAR proyek: cadangan yang tersimpan di
# dalam direktori aplikasi ikut terhapus oleh penyebaran berikutnya, dan
# itu adalah cara paling umum kehilangan cadangan yang tadinya ada.
CBT_BACKUP_DIR="${CBT_BACKUP_DIR:-/var/backups/cbt}"

# Berapa lama arsip disimpan. Tiga puluh hari menutup satu periode ujian
# penuh, sehingga kesalahan yang baru disadari sesudah ujian selesai masih
# punya cadangannya.
CBT_BACKUP_HARI="${CBT_BACKUP_HARI:-30}"

# Biner MySQL. Bawaannya dari PATH (VPS Linux). Mesin Windows memakai
# jalur Laragon lewat env - lihat docs/PELUNCURAN.md.
MYSQL_BIN="${MYSQL_BIN:-}"
MYSQLDUMP="${MYSQL_BIN:+$MYSQL_BIN/}mysqldump"
MYSQL="${MYSQL_BIN:+$MYSQL_BIN/}mysql"

# Akar proyek = dua tingkat di atas skrip ini.
AKAR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"

CAP="$(date +%Y%m%d-%H%M%S)"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

cetak() { printf '[%s] %s\n' "$(date +%H:%M:%S)" "$*"; }
gagal() { printf 'GALAT: %s\n' "$*" >&2; exit 1; }

# ---------------------------------------------------------------- pemeriksaan

command -v "$MYSQLDUMP" >/dev/null 2>&1 || gagal "mysqldump tidak ditemukan di '$MYSQLDUMP'. Setel MYSQL_BIN."

mkdir -p "$CBT_BACKUP_DIR" || gagal "Tidak bisa membuat '$CBT_BACKUP_DIR'."

# Kredensial lewat berkas sementara, bukan argumen: argumen baris perintah
# terbaca siapa pun yang menjalankan `ps` di server yang sama.
KREDENSIAL="$TMP/kredensial.cnf"
cat > "$KREDENSIAL" <<CNF
[client]
host=$DB_HOST
port=$DB_PORT
user=$DB_USERNAME
password="$DB_PASSWORD"
CNF
chmod 600 "$KREDENSIAL"

# ------------------------------------------------------------------- dump

cetak "Mencadangkan basis data '$DB_DATABASE' dari $DB_HOST:$DB_PORT ..."

DUMP="$TMP/$CAP.sql"

# `set -e` di atas membuat kegagalan mysqldump menghentikan skrip SEBELUM
# arsip dibuat. Itu disengaja: arsip yang berisi dump separuh jauh lebih
# berbahaya daripada tidak ada arsip sama sekali, karena ia terlihat seperti
# cadangan sampai ada yang benar-benar mencoba memulihkannya.
"$MYSQLDUMP" \
    --defaults-extra-file="$KREDENSIAL" \
    --single-transaction \
    --routines \
    --triggers \
    --events \
    --default-character-set=utf8mb4 \
    --hex-blob \
    "$DB_DATABASE" > "$DUMP" || gagal "mysqldump gagal - tidak ada arsip yang dibuat."

[ -s "$DUMP" ] || gagal "Dump kosong - tidak ada arsip yang dibuat."

# Berapa baris yang benar-benar ada, dicatat di samping arsipnya. Angka ini
# yang dibandingkan oleh `cbt-uji-pulih.sh`, dan menyimpannya BERSAMA
# arsipnya berarti pembandingnya tidak perlu menyentuh basis data aslinya.
AKAR_BARIS() {
    "$MYSQL" --defaults-extra-file="$KREDENSIAL" -N -B \
        -e "SELECT COUNT(*) FROM \`$1\`" "$DB_DATABASE" 2>/dev/null || echo '?'
}

{
    echo "cap=$CAP"
    echo "basis_data=$DB_DATABASE"
    echo "host=$DB_HOST:$DB_PORT"
    echo "exam_attempts=$(AKAR_BARIS exam_attempts)"
    echo "exam_results=$(AKAR_BARIS exam_results)"
    echo "report_exports=$(AKAR_BARIS report_exports)"
    echo "students=$(AKAR_BARIS users)"
} > "$TMP/baris.txt"

cetak "Media privat ..."

# Media privat dicadangkan TERPISAH lalu digabung ke arsip yang sama.
# `tar` gagal bila direktorinya belum ada pada instalasi baru; itu bukan
# kegagalan pencadangan, jadi hanya diperingatkan.
MEDIA="$AKAR/storage/app/private"
if [ -d "$MEDIA" ]; then
    tar -czf "$TMP/media.tar.gz" -C "$AKAR/storage/app" private
else
    cetak "PERINGATAN: '$MEDIA' tidak ada - arsip tidak memuat media."
fi

cetak "Berkas .env ..."

if [ -f "$AKAR/.env" ]; then
    cp "$AKAR/.env" "$TMP/env.cadangan"
    chmod 600 "$TMP/env.cadangan"
else
    cetak "PERINGATAN: '.env' tidak ada - arsip tidak memuat APP_KEY."
fi

# --------------------------------------------------------------- pembungkusan

cetak "Membungkus arsip ..."

ARSIP="$CBT_BACKUP_DIR/cbt-$CAP.tar.gz"

{
    echo "Dibuat: $(date -Iseconds)"
    echo "Proyek: $AKAR"
    echo
    cat "$TMP/baris.txt"
    echo
    echo "Isi:"
    echo "  basis-data.sql   dump lengkap, single-transaction"
    echo "  baris.txt        jumlah baris tabel kunci"
    echo "  media.tar.gz     storage/app/private"
    echo "  env.cadangan     .env, termasuk APP_KEY"
} > "$TMP/BACA.txt"

# `-C "$TMP"` supaya isi arsipnya relatif dan tidak memuat jalur sementara
# mesin yang membuatnya - arsip yang dipulihkan di server lain tidak perlu
# tahu apa pun tentang mesin asalnya.
mv "$DUMP" "$TMP/basis-data.sql"

# Daftar berkasnya disusun sebagai ARRAY, bukan `$( [ -f x ] && echo x )`.
# Bentuk yang kedua adalah perangkap pada skrip ber-`set -e`: substitusi
# perintah mewarisi `set -e`, sehingga `[ -f ... ]` yang gagal menghentikan
# skrip tepat pada kasus yang justru ingin ditangani - instalasi baru yang
# belum punya media.
ISI=(basis-data.sql baris.txt BACA.txt)

[ -f "$TMP/media.tar.gz" ] && ISI+=(media.tar.gz)
[ -f "$TMP/env.cadangan" ] && ISI+=(env.cadangan)

tar -czf "$ARSIP" -C "$TMP" "${ISI[@]}"

BESAR="$(du -h "$ARSIP" | cut -f1)"

cetak "Arsip: $ARSIP ($BESAR)"

# ----------------------------------------------------------------- rotasi

cetak "Merotasi arsip lebih tua dari $CBT_BACKUP_HARI hari ..."

HAPUS=$(find "$CBT_BACKUP_DIR" -maxdepth 1 -name 'cbt-*.tar.gz' -type f \
    -mtime "+$CBT_BACKUP_HARI" -print)

if [ -n "$HAPUS" ]; then
    # Daftarnya dicetak SEBELUM dihapus: penghapusan yang berbunyi hanya
    # lewat `-delete` adalah penghapusan yang tidak bisa ditelusuri saat ada
    # yang bertanya "kenapa cadangan bulan lalu hilang".
    echo "$HAPUS" | while read -r f; do cetak "  hapus: $f"; rm -f "$f"; done
else
    cetak "  tidak ada yang perlu dihapus."
fi

SISA=$(find "$CBT_BACKUP_DIR" -maxdepth 1 -name 'cbt-*.tar.gz' -type f | wc -l)
cetak "Selesai. $SISA arsip tersimpan di $CBT_BACKUP_DIR."

cat "$TMP/baris.txt"
