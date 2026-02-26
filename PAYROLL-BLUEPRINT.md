# BLUEPRINT: Modul Gaji (Payroll) — ADF System

## 📋 Ringkasan Fitur

Menu **Gaji** adalah modul penggajian untuk mengelola data karyawan, perhitungan gaji bulanan (manual input), dan laporan penggajian. Modul ini masuk ke **Business DB** (bukan master DB) karena setiap bisnis punya karyawan & struktur gaji berbeda.

---

## 🗄️ Database Schema (Business DB)

### Tabel 1: `payroll_employees` — Data Karyawan
Menyimpan master data karyawan termasuk gaji pokok dan jabatan.

```sql
CREATE TABLE IF NOT EXISTS payroll_employees (
    id INT AUTO_INCREMENT PRIMARY KEY,
    employee_code VARCHAR(20) NOT NULL,           -- Kode unik karyawan (auto: EMP-001)
    full_name VARCHAR(100) NOT NULL,               -- Nama lengkap
    position VARCHAR(100) NOT NULL,                -- Jabatan (Chef, Waiter, Manager, dll)
    department VARCHAR(100) DEFAULT NULL,           -- Departemen/Divisi (opsional)
    phone VARCHAR(20) DEFAULT NULL,                -- No HP
    address TEXT DEFAULT NULL,                      -- Alamat
    join_date DATE NOT NULL,                       -- Tanggal masuk kerja
    base_salary DECIMAL(15,2) NOT NULL DEFAULT 0,  -- Gaji pokok per bulan
    bank_name VARCHAR(50) DEFAULT NULL,            -- Nama bank (opsional)
    bank_account VARCHAR(50) DEFAULT NULL,         -- No rekening (opsional)
    is_active TINYINT(1) NOT NULL DEFAULT 1,       -- Status aktif
    notes TEXT DEFAULT NULL,                        -- Catatan
    created_by INT DEFAULT NULL,                   -- User yang input
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_employee_code (employee_code),
    INDEX idx_active (is_active),
    INDEX idx_position (position),
    INDEX idx_join_date (join_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### Tabel 2: `payroll_periods` — Periode Gaji
Menyimpan periode penggajian bulanan dan statusnya.

```sql
CREATE TABLE IF NOT EXISTS payroll_periods (
    id INT AUTO_INCREMENT PRIMARY KEY,
    period_month INT NOT NULL,                     -- Bulan (1-12)
    period_year INT NOT NULL,                      -- Tahun (2025, 2026, dll)
    period_label VARCHAR(50) NOT NULL,             -- Label: "Februari 2026"
    status ENUM('draft','submitted','approved','paid') NOT NULL DEFAULT 'draft',
    total_gross DECIMAL(15,2) NOT NULL DEFAULT 0,  -- Total gaji kotor seluruh karyawan
    total_deductions DECIMAL(15,2) NOT NULL DEFAULT 0, -- Total potongan
    total_net DECIMAL(15,2) NOT NULL DEFAULT 0,    -- Total gaji bersih (take home pay)
    total_employees INT NOT NULL DEFAULT 0,        -- Jumlah karyawan di periode ini
    submitted_at DATETIME DEFAULT NULL,            -- Tanggal pengajuan ke owner
    submitted_by INT DEFAULT NULL,                 -- User yang mengajukan
    approved_at DATETIME DEFAULT NULL,             -- Tanggal disetujui owner
    approved_by INT DEFAULT NULL,                  -- User yang menyetujui
    paid_at DATETIME DEFAULT NULL,                 -- Tanggal dibayarkan
    notes TEXT DEFAULT NULL,                       -- Catatan periode
    created_by INT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_period (period_month, period_year),
    INDEX idx_status (status),
    INDEX idx_year (period_year)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### Tabel 3: `payroll_slips` — Slip Gaji Per Karyawan Per Periode
Setiap baris = 1 slip gaji untuk 1 karyawan di 1 periode. Semua komponen di-input manual.

```sql
CREATE TABLE IF NOT EXISTS payroll_slips (
    id INT AUTO_INCREMENT PRIMARY KEY,
    period_id INT NOT NULL,                        -- FK ke payroll_periods
    employee_id INT NOT NULL,                      -- FK ke payroll_employees
    employee_name VARCHAR(100) NOT NULL,            -- Snapshot nama (untuk arsip)
    position VARCHAR(100) NOT NULL,                 -- Snapshot jabatan
    
    -- === KOMPONEN PENDAPATAN (manual input) ===
    base_salary DECIMAL(15,2) NOT NULL DEFAULT 0,  -- Gaji pokok (auto-fill dari master, bisa diedit)
    overtime DECIMAL(15,2) NOT NULL DEFAULT 0,     -- Lembur
    incentive DECIMAL(15,2) NOT NULL DEFAULT 0,    -- Insentif tambahan
    allowance DECIMAL(15,2) NOT NULL DEFAULT 0,    -- Tunjangan (makan, transport, dll)
    bonus DECIMAL(15,2) NOT NULL DEFAULT 0,        -- Bonus
    other_income DECIMAL(15,2) NOT NULL DEFAULT 0, -- Pendapatan lain
    
    -- === KOMPONEN POTONGAN (manual input) ===
    deduction_loan DECIMAL(15,2) NOT NULL DEFAULT 0,      -- Potongan pinjaman/kasbon
    deduction_absence DECIMAL(15,2) NOT NULL DEFAULT 0,   -- Potongan absensi/alpha
    deduction_tax DECIMAL(15,2) NOT NULL DEFAULT 0,       -- Potongan pajak
    deduction_bpjs DECIMAL(15,2) NOT NULL DEFAULT 0,      -- Potongan BPJS
    deduction_other DECIMAL(15,2) NOT NULL DEFAULT 0,     -- Potongan lain
    
    -- === KALKULASI (auto-hitung dari komponen di atas) ===
    total_earnings DECIMAL(15,2) NOT NULL DEFAULT 0,      -- SUM semua pendapatan
    total_deductions DECIMAL(15,2) NOT NULL DEFAULT 0,    -- SUM semua potongan
    net_salary DECIMAL(15,2) NOT NULL DEFAULT 0,          -- take home pay = earnings - deductions
    
    notes VARCHAR(255) DEFAULT NULL,               -- Catatan per slip
    created_by INT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    UNIQUE KEY unique_slip (period_id, employee_id),
    INDEX idx_period (period_id),
    INDEX idx_employee (employee_id),
    FOREIGN KEY (period_id) REFERENCES payroll_periods(id) ON DELETE CASCADE,
    FOREIGN KEY (employee_id) REFERENCES payroll_employees(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### Tabel 4: `payroll_slip_details` — Rincian Custom Per Slip (opsional)
Untuk menambah baris keterangan detail per komponen jika diperlukan nanti.

```sql
CREATE TABLE IF NOT EXISTS payroll_slip_details (
    id INT AUTO_INCREMENT PRIMARY KEY,
    slip_id INT NOT NULL,                          -- FK ke payroll_slips
    component_type ENUM('earning','deduction') NOT NULL, -- Jenis: pendapatan/potongan
    component_name VARCHAR(100) NOT NULL,           -- Nama komponen (bebas input)
    amount DECIMAL(15,2) NOT NULL DEFAULT 0,       -- Nominal
    notes VARCHAR(255) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (slip_id) REFERENCES payroll_slips(id) ON DELETE CASCADE,
    INDEX idx_slip (slip_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

---

## 📊 Relasi Antar Tabel

```
payroll_employees (Master Karyawan)
    │
    ├── 1 karyawan → banyak payroll_slips (per bulan)
    │
payroll_periods (Periode Bulanan)
    │
    ├── 1 periode → banyak payroll_slips (per karyawan)
    │
payroll_slips (Slip Gaji = karyawan × periode)
    │
    └── 1 slip → banyak payroll_slip_details (rincian custom)
```

---

## 🖥️ Struktur Menu & Halaman

### Lokasi File: `modules/payroll/`

```
modules/payroll/
├── index.php              -- Dashboard Gaji (ringkasan periode aktif + total)
├── employees.php          -- CRUD Data Karyawan
├── process.php            -- Proses/Input Gaji Bulanan (pilih periode → isi komponen per karyawan)
├── slips.php              -- Lihat Rincian Slip Gaji per periode
├── reports.php            -- Laporan Gaji (bulanan, tahunan, per karyawan)
├── print-slip.php         -- Cetak Slip Gaji (1 karyawan, window.print)
├── print-submission.php   -- Cetak Pengajuan Gaji ke Owner (seluruh karyawan 1 periode)
└── print-report.php       -- Cetak Laporan Gaji Bulanan/Tahunan
```

### Sidebar Menu (di header.php)

```
📋 Gaji                          (has-submenu, icon: dollar-sign)
   ├── 👤 Data Karyawan           → employees.php (CRUD)
   ├── 💰 Proses Gaji             → process.php (input gaji bulanan)
   ├── 📄 Rincian Gaji            → slips.php (lihat slip per periode)
   └── 📊 Laporan Gaji            → reports.php (laporan + cetak)
```

**Permission**: `payroll` — permission baru yang perlu ditambahkan ke:
- `menu_items` di master DB
- `$rolePermissions` di auth.php (admin, owner, manager, accountant)

---

## 📱 Alur Kerja (Workflow)

### Alur 1: Setup Awal
```
Admin/Owner buka "Data Karyawan"
  → Tambah karyawan baru (nama, jabatan, tgl masuk, gaji pokok)
  → Daftar karyawan aktif tampil di tabel
  → Bisa edit, nonaktifkan, atau hapus karyawan
```

### Alur 2: Proses Gaji Bulanan
```
1. Buka "Proses Gaji" → Pilih bulan & tahun
2. Sistem auto-generate payroll_period jika belum ada
3. Sistem auto-generate payroll_slips untuk semua karyawan aktif
   → base_salary auto-fill dari payroll_employees.base_salary
4. Admin input manual per karyawan:
   ┌─────────────────────────────────────────────────────┐
   │  Karyawan: Ahmad (Chef)                             │
   │                                                     │
   │  (+) Gaji Pokok    : Rp 3.000.000  ← auto-fill     │
   │  (+) Lembur         : Rp   500.000  ← input manual  │
   │  (+) Insentif       : Rp   200.000  ← input manual  │
   │  (+) Tunjangan      : Rp   300.000  ← input manual  │
   │  (+) Bonus          : Rp         0  ← input manual  │
   │  (+) Lain-lain      : Rp         0  ← input manual  │
   │  ─────────────────────────────────────               │
   │  Total Pendapatan   : Rp 4.000.000                   │
   │                                                     │
   │  (-) Pot. Kasbon    : Rp   200.000  ← input manual  │
   │  (-) Pot. Absensi   : Rp    50.000  ← input manual  │
   │  (-) Pot. Pajak     : Rp         0  ← input manual  │
   │  (-) Pot. BPJS      : Rp         0  ← input manual  │
   │  (-) Pot. Lain      : Rp         0  ← input manual  │
   │  ─────────────────────────────────────               │
   │  Total Potongan     : Rp   250.000                   │
   │                                                     │
   │  ═══════════════════════════════════                 │
   │  GAJI BERSIH (THP)  : Rp 3.750.000                  │
   │               [Simpan]                               │
   └─────────────────────────────────────────────────────┘
5. Total gaji & jumlah karyawan di-update ke payroll_periods
6. Ulangi untuk semua karyawan (bisa pakai tabel bulk-edit)
```

### Alur 3: Pengajuan Gaji ke Owner
```
1. Admin klik "Ajukan ke Owner" di halaman Proses/Rincian Gaji
2. Status berubah: draft → submitted
3. Cetak Pengajuan Gaji = dokumen print berisi:
   - Header bisnis
   - Periode gaji
   - Tabel seluruh karyawan + komponen gaji + total
   - Kolom tanda tangan: Dibuat Oleh | Disetujui Oleh | Tanggal
4. Owner menyetujui → status: approved
5. Setelah dibayar → status: paid
```

### Alur 4: Cetak Slip Gaji
```
1. Di halaman Rincian Gaji → klik icon print per karyawan
2. Buka print-slip.php?slip_id=X di tab baru
3. Layout slip gaji:
   ┌─────────────────────────────────────────────────┐
   │          [LOGO] NAMA BISNIS                      │
   │              SLIP GAJI                            │
   │         Periode: Februari 2026                    │
   │                                                   │
   │  Nama     : Ahmad                                 │
   │  Jabatan  : Chef                                  │
   │  Tgl Masuk: 15 Jan 2024                           │
   │  Masa Kerja: 2 tahun 1 bulan                      │
   │                                                   │
   │  PENDAPATAN                                       │
   │  Gaji Pokok .............. Rp 3.000.000           │
   │  Lembur .................. Rp   500.000           │
   │  Insentif ................ Rp   200.000           │
   │  Tunjangan ............... Rp   300.000           │
   │                           ───────────             │
   │  Total Pendapatan         Rp 4.000.000            │
   │                                                   │
   │  POTONGAN                                         │
   │  Kasbon .................. Rp   200.000           │
   │  Absensi ................. Rp    50.000           │
   │                           ───────────             │
   │  Total Potongan           Rp   250.000            │
   │                                                   │
   │  ═══════════════════════════════════              │
   │  GAJI BERSIH (THP)       Rp 3.750.000            │
   │                                                   │
   │  Diperiksa      Disetujui      Diterima           │
   │  _________      _________      _________          │
   └─────────────────────────────────────────────────┘
4. window.print() → print / save PDF
```

---

## 📊 Halaman Reports (Laporan Gaji)

### Tab 1: Laporan Bulanan
- Filter: Pilih bulan & tahun
- Tabel: Semua karyawan di periode tersebut
- Kolom: No | Nama | Jabatan | Gaji Pokok | Lembur | Insentif | Tunjangan | Total Pendapatan | Total Potongan | Gaji Bersih
- Footer: Total semua kolom
- Tombol: [Cetak Laporan] [Cetak Pengajuan ke Owner]

### Tab 2: Laporan Tahunan
- Filter: Pilih tahun
- Ringkasan 12 bulan dalam 1 tabel
- Kolom: Bulan | Jml Karyawan | Total Pendapatan | Total Potongan | Total Gaji Bersih
- Footer: Grand total setahun
- Chart: Bar chart pengeluaran gaji per bulan (opsional)
- Tombol: [Cetak Laporan Tahunan]

### Tab 3: Laporan Per Karyawan
- Filter: Pilih karyawan + range bulan
- Riwayat gaji karyawan tersebut per bulan
- Tombol: [Cetak]

---

## 🔒 Permission & Role Access

| Role        | Data Karyawan | Proses Gaji | Rincian Gaji | Laporan | Approve |
|-------------|:---:|:---:|:---:|:---:|:---:|
| Developer   | ✅ | ✅ | ✅ | ✅ | ✅ |
| Owner       | ✅ | ✅ | ✅ | ✅ | ✅ |
| Manager     | ✅ | ✅ | ✅ | ✅ | ❌ |
| Admin       | ✅ | ✅ | ✅ | ✅ | ❌ |
| Accountant  | ❌ | ✅ | ✅ | ✅ | ❌ |
| Staff       | ❌ | ❌ | ❌ | ❌ | ❌ |
| Frontdesk   | ❌ | ❌ | ❌ | ❌ | ❌ |

---

## 🛠️ File yang Perlu Diubah (Existing)

1. **`includes/header.php`** — Tambah menu "Gaji" di sidebar (antara Tagihan dan Procurement)
2. **`includes/auth.php`** — Tambah `'payroll'` ke `$rolePermissions` untuk role yang sesuai
3. **`database-payroll.sql`** — File SQL baru berisi 4 CREATE TABLE
4. **`config/businesses/*.php`** — Tambah `'payroll'` ke `enabled_modules`
5. **`includes/business_helper.php`** — Tambah `'payroll'` ke default enabled_modules di `autoSyncBusinessConfigs()`

---

## 📐 UI Design Guidelines (Mengikuti Pattern Sistem)

- **Layout**: Sidebar app (bukan standalone), menggunakan `header.php` + `footer.php`
- **CSS**: Gunakan CSS variables yang ada (`--primary-color`, `--bg-secondary`, dll)
- **Icons**: Feather Icons (`dollar-sign`, `users`, `file-text`, `printer`)
- **Tables**: Custom HTML table (bukan DataTables), class sesuai style sistem
- **Modals**: Inline modal untuk CRUD (tambah/edit karyawan, edit slip)
- **Forms**: Input group dengan label, format Rupiah menggunakan JS formatter
- **Print**: `window.print()` + `@media print` CSS, gunakan `print-helper.php`
- **Flash Messages**: `setFlash()` / `getFlash()` untuk success/error

---

## ⚡ Auto-Setup Database

Seperti modul lain, tabel payroll harus auto-create saat pertama kali diakses:
```php
// Di awal setiap halaman payroll
function ensurePayrollTables($db) {
    // Check if payroll_employees exists
    $check = $db->query("SHOW TABLES LIKE 'payroll_employees'");
    if ($check->rowCount() === 0) {
        // Run CREATE TABLE statements
        $sql = file_get_contents(__DIR__ . '/../../database-payroll.sql');
        $db->exec($sql);
    }
}
```

---

## 📦 Summary — Yang Akan Dibuat

| # | Item | Tipe | Keterangan |
|---|------|------|------------|
| 1 | `database-payroll.sql` | SQL | 4 tabel: employees, periods, slips, slip_details |
| 2 | `modules/payroll/index.php` | Page | Dashboard gaji — ringkasan periode aktif |
| 3 | `modules/payroll/employees.php` | Page | CRUD data karyawan |
| 4 | `modules/payroll/process.php` | Page | Input gaji bulanan per karyawan |
| 5 | `modules/payroll/slips.php` | Page | Lihat rincian slip gaji per periode |
| 6 | `modules/payroll/reports.php` | Page | Laporan bulanan, tahunan, per karyawan |
| 7 | `modules/payroll/print-slip.php` | Print | Cetak slip gaji per karyawan |
| 8 | `modules/payroll/print-submission.php` | Print | Cetak pengajuan gaji ke owner |
| 9 | `modules/payroll/print-report.php` | Print | Cetak laporan bulanan/tahunan |
| 10 | `includes/header.php` | Edit | Tambah menu sidebar Gaji |
| 11 | `includes/auth.php` | Edit | Tambah permission `payroll` |
| 12 | Config files | Edit | Enable module payroll |

**Total: 9 file baru + 3 file yang diedit**

---

## ❓ Pertanyaan Sebelum Eksekusi

1. Apakah komponen gaji (lembur, insentif, tunjangan, dll) sudah cukup atau perlu ditambah?
2. Apakah perlu integrasi dengan Cashbook (otomatis catat pengeluaran gaji ke cashbook)?
3. Apakah karyawan di payroll terpisah dari user sistem (karyawan belum tentu punya akun login)?
4. Apakah perlu fitur "Kasbon" (salary advance) yang terhubung ke potongan gaji?
