# Darmutiara · paket uji Averion 1.2.262

Paket ini menggabungkan arsip kode, arsip pelengkap, dan folder sertifikat dari container RizqHub. Dockerfile disalin dengan nilai bawaan Coolify produksi dihapus. Paket menggunakan database baru untuk uji lokal.

## Menjalankan di Windows

1. Ekstrak ZIP, lalu buka CMD di folder yang memuat `compose.yaml`. Siapkan Docker Desktop dan pastikan Docker sedang berjalan.
2. Jalankan `copy .env.example .env`. Buka `.env` dan isi kedua password dengan nilai baru yang kuat dan berbeda untuk lingkungan uji. Gunakan karakter alfanumerik agar mudah dimasukkan kembali di installer.
3. Jalankan `docker compose up -d --build`. Tunggu MariaDB siap. Jika perlu, lihat `docker compose logs db`.
4. Buka `http://localhost:8080/installer/`. Apabila port 8080 sedang dipakai, ubah `DARMUTIARA_HTTP_PORT` di `.env` dan gunakan port tersebut di browser.

| Kolom installer | Nilai |
|---|---|
| Database host | `db` |
| Port | `3306` |
| Database | `darmutiara` |
| Username database | `darmutiara` |
| Password database | Nilai `DARMUTIARA_DB_PASSWORD` dari `.env` |

Buat akun admin uji di installer. Gunakan aktivasi resmi jika lisensi diminta. Konfirmasikan cakupan lisensi untuk instalasi uji tambahan bila diperlukan. Pengaturan branding ada di admin setelah aplikasi siap. Atur nama **Darmutiara** dan isi program uji sesuai Darul Mutaqin Sejahtera.

Jalankan `docker compose stop` untuk menghentikan uji sambil menyimpan data. Jalankan `docker compose start` untuk melanjutkan. Simpan `.env` sebagai file lokal.

## Uji modul Kajian dan Diskusi

1. Masuk sebagai admin. Buka **Konten → Kajian** atau kunjungi `/admin/kajian`.
2. Buat kajian, isi pemateri dan jadwal dalam WIB, lalu pilih **Terbitkan untuk anggota**. Jika kajian khusus peserta program, pilih produk Averion yang memberi akses. Masukkan tautan HTTPS pertemuan dan rekaman bila tersedia.
3. Masuk sebagai anggota biasa. Buka **Jadwal Kajian** atau `/kajian`. Daftar ke kajian, unduh pengingat kalender, lalu tulis komentar. Impor file kalender agar aplikasi kalender Anda dapat memberi pengingat.
4. Kembali sebagai admin. Buka kajian tadi, lihat daftar peserta, lalu coba sembunyikan dan tampilkan kembali komentar. Coba juga membuka kajian terbatas dengan akun tanpa akses produk.

Untuk database uji baru, installer memasang tabel Kajian bersama skema awal. Jika Anda sudah menjalankan paket staging terdahulu, impor `migrations/darmutiara-kajian.sql` ke **database staging** dalam panel database, lalu jalankan ulang `docker compose up -d --build` dari folder paket baru bersama pengaturan `.env` staging yang lama. Migrasi itu dapat diulang dan tidak menghapus data. Lakukan pencadangan staging terlebih dahulu.

Pengingat versi ini berupa kalender dengan pengingat 30 menit sebelum kajian. Pengurus masih bisa memakai Broadcast Averion untuk pesan WhatsApp atau email. Modul ini memakai satu instalasi komunitas dan akses produk Averion.

## Status pemeriksaan

Versi, integritas ZIP, rute, referensi menu, struktur Compose, dan penyertaan skema Kajian diperiksa. PHP, Docker, dan MariaDB tidak tersedia di lingkungan pemeriksaan, sehingga build, pemasangan, aktivasi, dan alur Kajian di browser masih perlu diuji. Folder runtime akan dibuat oleh Docker saat build dan startup.
