# Kuota Member Padel Sabtu

Website sederhana (PHP + SQLite, tanpa framework/composer) untuk menampilkan sisa kuota member dan halaman admin untuk mencatat pembayaran & penggunaan.

## Menjalankan

Lokal (butuh PHP 7.4+ dengan ekstensi `pdo_sqlite`):

```bash
cd kuota-member
php -S localhost:8000
```

Buka http://localhost:8000 (publik) dan http://localhost:8000/admin.php (admin).

Hosting (cPanel/shared hosting): upload seluruh folder, pastikan folder `database/` bisa ditulis oleh PHP (chmod 775). File `.htaccess` di `database/` dan `inc/` memblokir akses langsung (Apache). Untuk Nginx, tambahkan `location ~ ^/(database|inc)/ { deny all; }`.

## Login admin

Password default: `admin1234` — ganti di menu **Pengaturan** setelah login.

## Data awal

Saat pertama dibuka, `database/kuota.sqlite` dibuat otomatis lalu diisi dari `database/seed.sql` (hasil migrasi `Kuota_Member_Sabtu.xlsx` per 30 Okt 2026, termasuk pembayaran Erlina 27 Okt). Untuk reset ke data awal, hapus `database/kuota.sqlite`.

Backup = salin file `database/kuota.sqlite`.

Update dari versi sebelumnya: cukup timpa file kode, jangan hapus `kuota.sqlite`. Tabel keuangan dibuat otomatis saat halaman dibuka; data tiket reguler 24 Okt dari Excel hanya masuk otomatis pada instalasi baru, jadi di database lama catat manual di menu Keuangan.

## Cara kerja kuota

- **Pembayaran**: tiap baris = pembelian paket. Kuota disimpan per baris (default paket × kuota per paket), jadi mengubah pengaturan tidak mengubah riwayat.
- **Penggunaan**: tiap baris = potong 1 kuota (`Main (kuota)` atau `Refund/Cashback`). Input per meet dengan centang banyak member sekaligus; member yang sudah tercatat main di tanggal yang sama otomatis dilewati.
- **Sisa** = total kuota − main − refund/cashback. Status: Aktif, Sisa 1, Habis, Minus (cek).
- **Keuangan** (menu admin): catat pendapatan lain (tiket reguler, sponsor, dll.) dan pengeluaran (sewa lapangan, bola, dll.) secara manual. Laporan bulanan menggabungkan pembayaran paket member + pendapatan manual − pengeluaran, lengkap dengan akumulasi laba, rincian per kategori, dan penyesuaian kuota yang belum dimainkan (sisa jatah × harga per main). Bisa diunduh sebagai CSV.
- Member nonaktif disembunyikan dari halaman publik dan daftar centang, riwayatnya tetap ada.

## Struktur

```
index.php            halaman publik
admin.php            halaman admin (ringkasan, penggunaan, pembayaran, keuangan, member, pengaturan, export CSV)
inc/bootstrap.php    koneksi DB, perhitungan kuota, helper
inc/layout.php       header/footer
inc/finance.php      perhitungan laporan keuangan
database/schema.sql  skema tabel
database/seed.sql    data awal dari Excel
assets/style.css
```
