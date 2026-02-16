# DOKUMENTASI MODUL FINANCE (MANAJEMEN KEUANGAN)

## Daftar Isi
1. [Persiapan Awal](#persiapan-awal)
2. [Fitur Utama](#fitur-utama)
3. [Panduan Penggunaan](#panduan-penggunaan)
4. [Struktur Menu](#struktur-menu)

---

## Persiapan Awal

### 1. Register Menu Finance di Database
Setelah update kode, Anda perlu mendaftarkan menu Finance ke database:

```
Buka browser → http://localhost/adf_system/update-menus.php
Klik "Run" atau tunggu proses selesai
```

Menu "Manajemen Keuangan" akan otomatis muncul di navigasi utama.

### 2. Pastikan Tabel Project & Expenses Sudah Ada
Jalankan migration untuk memastikan tabel tersedia:

```
- projects
- project_expenses
- project_expense_categories
```

Jika belum ada, jalankan: 
```
database/migration-investor-project.sql
```

---

## Fitur Utama

### 📊 Dashboard Keuangan (`modules/finance/index.php`)

**Fungsi:**
- Menampilkan overview semua project
- Summary budget, pengeluaran, dan sisa budget
- Quick stats untuk total project

**Yang Ditampilkan:**
- Total Budget Project (semua project)
- Total Pengeluaran (semua project)  
- Sisa Budget (remaining)
- Jumlah Project Aktif
- Daftar project dengan progress bar
- Activity terbaru (5 pengeluaran terakhir)

**Tombol Aksi:**
- "+ Catat Pengeluaran" → Buka ledger.php
- "Laporan" → Buka reports.php
- "Buku Kas" (per project) → Buka ledger.php dengan project selected
- "Laporan" (per project) → Buka reports.php dengan project selected

---

### 📝 Buku Kas Project (`modules/finance/ledger.php`)

**Konsep:**
Ledger/buku kas untuk mencatat semua pengeluaran per project. Seperti pembukuan project expense.

**Fitur:**
1. **Pilih Project** - Dropdown untuk memilih project
2. **Summary Stats:**
   - Budget Project
   - Total Pengeluaran
   - Sisa Budget
   - Total Transaksi

3. **Catat Pengeluaran Baru:**
   - Tanggal pengeluaran (required)
   - Kategori (required)
   - Jumlah (required)
   - No. Referensi (optional)
   - Keterangan (optional)
   - Tombol "Simpan Pengeluaran"

4. **Tabel Riwayat:**
   - Tanggal
   - Kategori
   - Keterangan
   - No. Referensi
   - Jumlah
   - Tombol Delete per item

**Flow:**
```
Dashboard → Buku Kas
        ↓
    Pilih Project
        ↓
    Lihat Ledger + Stats
        ↓
    Catat Pengeluaran Baru
        ↓
    Lihat Riwayat
        ↓
    Delete jika perlu
```

---

### 📈 Laporan Project (`modules/finance/reports.php`)

**Konsep:**
Generate laporan pengeluaran project dengan breakdown per kategori.

**Fitur:**
1. **Filter Laporan:**
   - Pilih Project (required)
   - Jenis Laporan: Bulanan / Mingguan / Harian
   - Periode (pilih bulan/minggu/hari sesuai jenis)

2. **Summary Report:**
   - Total Pengeluaran (periode tertentu)
   - Budget Project
   - Sisa Budget (periode tertentu)
   - Jumlah Transaksi

3. **Tabel Pengeluaran:**
   - Tanggal
   - Kategori
   - Keterangan
   - Jumlah
   - Total baris

4. **Category Breakdown (sidebar):**
   - Breakdown pengeluaran per kategori
   - Persentase per kategori
   - Jumlah item per kategori

5. **Print Function:**
   - Tombol "Print" untuk cetak laporan
   - Format siap print (hide filter, header, etc)

**Tipe Laporan:**
- **Bulanan:** Laporan pengeluaran 1 bulan (pilih month)
- **Mingguan:** Laporan pengeluaran 1 minggu (pilih week)
- **Harian:** Laporan pengeluaran 1 hari (pilih date)

---

## Panduan Penggunaan

### Skenario 1: Mencatat Pengeluaran Project Baru

```
1. Buka "Manajemen Keuangan" dari menu utama
   ├─ Atau klik "+ Catat Pengeluaran" jika sudah di dashboard

2. Pilih project dari dropdown "Pilih Project"

3. Di section "Catat Pengeluaran Baru", isi:
   - Tanggal pengeluaran
   - Kategori (Pembelian Material, Pembayaran Truk, Tiket Kapal, Gaji Tukang)
   - Jumlah (Rp)
   - No. Invoice (optional)
   - Keterangan (optional)

4. Klik "Simpan Pengeluaran"

5. Data akan muncul di "Riwayat Pengeluaran" tabel bawah
```

### Skenario 2: Melihat Laporan Pengeluaran Bulanan

```
1. Buka "Manajemen Keuangan" → klik "Laporan"
   
2. Filter:
   - Pilih Project
   - Jenis Laporan: "Bulanan"
   - Pilih Bulan: contoh "2026-02"

3. Lihat:
   - Summary stats (total, budget, sisa)
   - Tabel pengeluaran detail
   - Category breakdown di sidebar

4. Klik "Print" untuk cetak laporan
```

### Skenario 3: Perbandingan Budget vs Realisasi

```
1. Buka Dashboard Keuangan

2. Lihat summary cards:
   - "Total Budget Project" vs "Total Pengeluaran"
   - "Sisa Budget" = Budget - Pengeluaran

3. Lihat progress bar di setiap project card:
   - Menunjukkan % pengeluaran dari budget
   - Warna bar: gradien ungu (#6366f1 ke #8b5cf6)

4. Klik project card "Laporan" untuk detail mingguan/bulanan
```

---

## Struktur Menu

### Navigasi Menu Utama
```
1. Dashboard
2. Buku Kas Besar (cashbook)
3. Kelola Divisi
4. Frontdesk
5. Sales Invoice
6. PO & SHOOP (procurement)
7. Reports (general reports)
8. Investor (investor management)
9. Project (project management)
10. ✨ MANAJEMEN KEUANGAN (NEW!) ← Finance Module
11. Pengaturan (settings)
```

### Sub-menu Finance
Tidak ada dropdown, langsung buka halaman index.php

Dari halaman Finance (index.php), user bisa navigate ke:
- **Ledger** (Buku Kas) - via tombol "Buku Kas" di setiap project
- **Reports** - via tombol "Laporan" atau "Laporan" di setiap project

---

## API Endpoints (Backend)

### 1. Simpan Pengeluaran
```
POST /api/project-expense-save.php

Parameters:
- project_id (required)
- expense_date (required) 
- expense_category_id (required)
- amount_idr (required)
- reference_no (optional)
- description (optional)
- status (optional, default: 'submitted')

Response:
- success: true/false
- message: string
```

### 2. Hapus Pengeluaran
```
POST /api/project-expense-delete.php

Parameters:
- expense_id (required)

Response:
- success: true/false
- message: string
```

### 3. Query Laporan
- Data diambil langsung dari database (real-time)
- No API endpoint khusus, query langsung di PHP controller

---

## Kategori Pengeluaran Default

```
1. Pembelian Material (MAT)
2. Pembayaran Truk (TRUCK)
3. Tiket Kapal (SHIP)
4. Gaji Tukang (LABOR)
```

Untuk menambah kategori baru, edit tabel `project_expense_categories` di database.

---

## Tips & Best Practices

### 1. Selalu Isi Kategori & Deskripsi
Memudahkan tracking dan laporan kategorisasi pengeluaran.

### 2. Update Budget Project Secara Berkala
Jika budget berubah, update di tabel `projects.budget`

### 3. Gunakan Laporan untuk Monitoring
Setiap minggu/bulan, check laporan untuk memastikan pengeluaran on-budget.

### 4. Archive Pengeluaran Lama
Untuk project yang sudah selesai, jangan hapus - mark as 'completed'.

### 5. Print Laporan untuk Audit
Gunakan fitur print reporting untuk dokumen arsip.

---

## Troubleshooting

### Menu Finance Tidak Tampil
**Solusi:**
1. Jalankan `update-menus.php` dari browser
2. Clear cache browser (Ctrl+Shift+R)
3. Check database: `SELECT * FROM menu_items WHERE menu_code='finance'`

### Error "Project Not Found"
**Solusi:**
1. Pastikan project sudah dibuat di modul Project
2. Check table `projects` di database

### Pengeluaran Tidak Tersimpan
**Solusi:**
1. Check API response: `project-expense-save.php`
2. Verify table `project_expenses` exist
3. Check category ID valid di `project_expense_categories`

### Laporan Tidak Menampilkan Data
**Solusi:**
1. Pastikan pengeluaran sudah dicatat
2. Check filter tanggal sudah benar
3. Verify expense_date dalam range laporan

---

## Update: Investor Module

Modul Investor tetap ada dengan fitur:
- **Data Investor:** Manage investor data
- **Catat Setoran:** Pencatatan setoran investor (tombol sudah functional)
- **Riwayat Setoran:** List transaksi setoran

**Perbedaan dengan Finance:**
- **Investor** = Dana masuk dari investor
- **Finance** = Pengeluaran project

Kedua modul terpisah untuk tracking capital vs expenses.

---

## File Structure

```
modules/finance/
├── index.php          (Dashboard)
├── ledger.php         (Buku Kas - Pencatatan)
└── reports.php        (Laporan)

api/
├── project-expense-save.php     (Save expense)
└── project-expense-delete.php   (Delete expense)

config/ 
└── menu setup (update-menus.php, rebuild-menus.php)
```

---

## Versi & Last Updated

- **Created:** 2026-02-16
- **Module Version:** 1.0
- **Last Updated:** 2026-02-16

---

**Pertanyaan?** Hubungi developer/admin system.
