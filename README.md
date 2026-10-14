# kopdes-iac-automationAWS — Platform Koperasi Desa (KopDes) Merah Putih

Platform aplikasi web digital multi-role untuk tata kelola Koperasi Desa (KopDes) Merah Putih, rantai pasok komoditas pangan desa, keanggotaan warga, transaksi kasir Point of Sale (POS), dan laporan ringkasan ekonomi terintegrasi.

---

## Fitur Utama Aplikasi

- **Multi-Role Access Control (RBAC)**:
  - **HEAD_GOV (Kepala Wilayah)**: Pemantauan omzet kumulatif, pembuatan unit koperasi baru (+ Spawn KopDes dengan peta Leaflet interaktif), manajemen akun manager unit, dan pengiriman rekapitulasi laporan ekonomi.
  - **MANAGER (Pengelola Unit)**: Pengelolaan katalog produk pangan/sembako, pendaftaran anggota koperasi, transaksi kasir POS desa, dan cetak nota.
  - **CITIZEN (Warga Desa)**: Registrasi mandiri, penjelajahan katalog produk koperasi wilayah, pembelian komoditas, dan status keanggotaan.
- **Katalog Komoditas & POS Kasir**: Pencatatan transaksi real-time dengan kode invoice otomatis.
- **Geospasial & Peta Wilayah**: Visualisasi sebaran unit KopDes di peta interaktif Banyumas dan seluruh Indonesia berbasis OpenStreetMap & Leaflet.js.
- **Cloud-Ready Configuration**: Mendukung koneksi database MySQL terpusat (Amazon RDS) maupun SQLite lokal, dengan deteksi env otomatis via `.env`.
- **Integrasi Notifikasi Laporan**: Didukung modul pelaporan ekonomi via **Amazon Simple Notification Service (SNS)** yang 100% kompatibel dengan AWS Academy Learner Lab.

---

## Deployment Cloud (IaC Automation)

Aplikasi web KopDes ini dirancang untuk di-deploy secara otomatis ke arsitektur **AWS Native** berbasis **Ubuntu 24.04 LTS (Noble Numbat)**, Auto Scaling Group (ASG), Application Load Balancer (ALB), dan Amazon RDS MySQL menggunakan **Terraform** dan **Ansible**.

Seluruh kode otomasi infrastruktur cloud, script bootstrap, template IaC, dan panduan langkah-demi-langkah tersedia di repository:
👉 **[terraform-ansible-automated-lab](https://github.com/fendyramadhani9-cloud/terraform-ansible-automated-lab)** (Panduan Lengkap: [TUTORIAL.md](https://github.com/fendyramadhani9-cloud/terraform-ansible-automated-lab/blob/main/TUTORIAL.md))

---

## Struktur Direktori Web Application

```text
kopdes/
|-- config/                  # Konfigurasi aplikasi & database (MySQL PDO / SQLite)
|-- database/                # Skema SQL (schema.sql), seed data (seed.sql), dan inisialisasi (init.php)
|-- includes/                # Library inti: auth, CSRF, layout (header, sidebar, footer), ses_mailer, helpers
|-- pages/                   # Antarmuka tampilan dan endpoint API berbasis role:
|   |-- api/                 # Endpoint AJAX/REST (spawn_kopdes, transactions, members, products)
|   |-- auth/                # Halaman login, logout, dan registrasi warga
|   |-- citizen/             # Dashboard warga, katalog, dan profil unit koperasi
|   |-- headgov/             # Dashboard eksekutif, peta wilayah, kelola manager, laporan ekonomi
|   `-- manager/             # Dashboard pengelola unit, produk, anggota, transaksi kasir
|-- public/                  # Document root web (index.php, CSS, JavaScript, aset gambar, Leaflet)
|-- tests/                   # Automated end-to-end and integration test suite
|-- .env.example             # Template konfigurasi environment aplikasi
`-- README.md                # Dokumentasi aplikasi (file ini)
```

---

## Akun Login Default (Uji Coba)

Seluruh akun default menggunakan password: `password123`

| Role | Email Login | Hak Akses |
| :--- | :--- | :--- |
| `HEAD_GOV` | `head@gov.local` | Administrator Wilayah (Executive Dashboard, Spawn KopDes, Reports) |
| `MANAGER` | `manager@gov.local` s.d. `manager15@gov.local` | Pengelola Unit Usaha (15 Unit KopDes Awal Banyumas) |
| `CITIZEN` | `citizen@gov.local` (atau daftar via Register) | Warga Desa (Belanja, Keanggotaan Koperasi) |

---

## Cara Menjalankan Aplikasi Secara Lokal

### Prasyarat
- PHP 8.1 atau lebih baru dengan ekstensi `pdo`, `pdo_sqlite` (atau `pdo_mysql`), `curl`, `mbstring`.

### Langkah Menjalankan:
1. Salin konfigurasi environment:
   ```bash
   cp .env.example .env
   ```
2. Inisialisasi skema tabel dan data seed:
   ```bash
   php database/init.php
   ```
3. Jalankan server lokal:
   ```bash
   php -S 127.0.0.1:8000 -t public
   ```
4. Buka browser pada alamat:
   ```text
   http://127.0.0.1:8000/
   ```
