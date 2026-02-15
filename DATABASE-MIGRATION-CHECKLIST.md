# 📋 DATABASE MIGRATION - HOSTING UPDATE CHECKLIST

**Tanggal:** 15 Februari 2026  
**Status:** 🔴 BELUM DIJALANKAN DI HOSTING  
**File Migration:** `migrate-hosting-database.php`

---

## 🎯 RINGKASAN PERUBAHAN

Hosting Anda belum memiliki schema database terbaru. File PHP sudah di-deploy via Git, tapi **database belum di-update**.

### Error yang Muncul:
```
SQLSTATE[42S22]: Column not found: 1054 Unknown column 'cash_account_id' in 'INSERT INTO'
```

**Root Cause:** Tabel `cash_book` di business database belum punya kolom `cash_account_id`

---

## 📊 DETAIL PERUBAHAN DATABASE

### 1️⃣ MASTER DATABASE (`adf_system`)

#### Table: `cash_accounts` (BARU)
**Fungsi:** Master akun kas untuk semua business (Kas, Bank, Modal Owner)

```sql
CREATE TABLE IF NOT EXISTS `cash_accounts` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `business_id` int(11) NOT NULL COMMENT 'Link to businesses table',
    `account_name` varchar(100) NOT NULL,
    `account_type` enum('cash','bank','owner_capital') NOT NULL DEFAULT 'cash',
    `current_balance` decimal(15,2) NOT NULL DEFAULT 0.00,
    `is_default_account` tinyint(1) NOT NULL DEFAULT 0,
    `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
    `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
    PRIMARY KEY (`id`),
    KEY `business_id` (`business_id`),
    KEY `account_type` (`account_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

**Default Accounts (per business):**
- 💵 **Kas Operasional** (type: cash, default account)
- 🏦 **Rekening Bank** (type: bank)
- 💰 **Kas Modal Owner** (type: owner_capital)

---

#### Table: `cash_account_transactions` (BARU)
**Fungsi:** Tracking transaksi untuk setiap cash account

```sql
CREATE TABLE IF NOT EXISTS `cash_account_transactions` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `cash_account_id` int(11) NOT NULL,
    `transaction_date` date NOT NULL,
    `description` varchar(255) NOT NULL,
    `amount` decimal(15,2) NOT NULL DEFAULT 0.00,
    `transaction_type` enum('income','expense','transfer','capital_injection') NOT NULL,
    `reference_number` varchar(50) DEFAULT NULL,
    `created_by` int(11) DEFAULT NULL,
    `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
    PRIMARY KEY (`id`),
    KEY `cash_account_id` (`cash_account_id`),
    KEY `transaction_date` (`transaction_date`),
    KEY `transaction_type` (`transaction_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

---

#### Table: `shift_logs` (BARU)
**Fungsi:** Log untuk End Shift feature (print laporan akhir shift)

```sql
CREATE TABLE IF NOT EXISTS `shift_logs` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `user_id` int(11) NOT NULL,
    `action` varchar(50) NOT NULL,
    `data` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`data`)),
    `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
    `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
    PRIMARY KEY (`id`),
    KEY `user_id` (`user_id`),
    KEY `idx_created_at` (`created_at`),
    KEY `idx_action` (`action`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

---

### 2️⃣ BUSINESS DATABASES (per business)

**Databases yang akan diupdate:**
- `adfb2574_narayana_hotel`
- `adfb2574_benscafe`
- (semua business database yang `is_active = 1`)

#### Table: `cash_book` - Kolom Baru

##### Kolom: `cash_account_id`
**Fungsi:** Link transaksi kas ke akun kas tertentu (Kas Operasional/Bank/Modal Owner)

```sql
ALTER TABLE `cash_book` 
ADD COLUMN `cash_account_id` INT(11) DEFAULT NULL 
AFTER `category_id`;
```

**Impact:**
- ✅ Owner Monitoring bisa pisahkan Modal Owner vs Pendapatan Operasional
- ✅ Dropdown "Pilih Akun" di form Buku Kas berfungsi
- ✅ Tracking saldo per akun kas (Kas Operasional, Bank, Modal Owner)

---

##### Kolom: `payment_method`
**Fungsi:** Metode pembayaran (cash/card/transfer/QRIS)

```sql
ALTER TABLE `cash_book` 
ADD COLUMN `payment_method` ENUM('cash','card','bank_transfer','qris','other') 
DEFAULT 'cash' 
AFTER `amount`;
```

**Impact:**
- ✅ Tracking metode pembayaran untuk laporan
- ✅ Integrasi dengan invoice payment method

---

##### Kolom: `reference_number`
**Fungsi:** Nomor referensi (nomor transfer, nomor kartu, dll)

```sql
ALTER TABLE `cash_book` 
ADD COLUMN `reference_number` VARCHAR(100) DEFAULT NULL 
AFTER `payment_method`;
```

**Impact:**
- ✅ Tracking nomor referensi pembayaran
- ✅ Validasi pembayaran bank transfer

---

## 🚀 CARA MENJALANKAN MIGRASI

### **METODE 1: Via Browser (DIREKOMENDASIKAN)**

1. **Login ke hosting** sebagai admin/developer/owner
2. **Akses URL:**
   ```
   https://adfsystem.online/migrate-hosting-database.php
   ```
3. **Review preview** perubahan yang akan dilakukan
4. **Klik tombol** "✅ JALANKAN MIGRASI"
5. **Tunggu proses** selesai (5-15 detik)
6. **Verifikasi hasil** dengan akses:
   ```
   https://adfsystem.online/debug-cash-accounts.php
   ```

---

### **METODE 2: Via phpMyAdmin (MANUAL)**

Jika metode 1 tidak bisa, jalankan SQL berikut di **phpMyAdmin**:

#### **Database:** `adf_system` (Master)

```sql
-- 1. Create cash_accounts table
CREATE TABLE IF NOT EXISTS `cash_accounts` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `business_id` int(11) NOT NULL,
    `account_name` varchar(100) NOT NULL,
    `account_type` enum('cash','bank','owner_capital') NOT NULL DEFAULT 'cash',
    `current_balance` decimal(15,2) NOT NULL DEFAULT 0.00,
    `is_default_account` tinyint(1) NOT NULL DEFAULT 0,
    `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
    `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
    PRIMARY KEY (`id`),
    KEY `business_id` (`business_id`),
    KEY `account_type` (`account_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Create cash_account_transactions table
CREATE TABLE IF NOT EXISTS `cash_account_transactions` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `cash_account_id` int(11) NOT NULL,
    `transaction_date` date NOT NULL,
    `description` varchar(255) NOT NULL,
    `amount` decimal(15,2) NOT NULL DEFAULT 0.00,
    `transaction_type` enum('income','expense','transfer','capital_injection') NOT NULL,
    `reference_number` varchar(50) DEFAULT NULL,
    `created_by` int(11) DEFAULT NULL,
    `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
    PRIMARY KEY (`id`),
    KEY `cash_account_id` (`cash_account_id`),
    KEY `transaction_date` (`transaction_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Create shift_logs table
CREATE TABLE IF NOT EXISTS `shift_logs` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `user_id` int(11) NOT NULL,
    `action` varchar(50) NOT NULL,
    `data` longtext,
    `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
    `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
    PRIMARY KEY (`id`),
    KEY `user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. Insert default accounts (Business ID 1 - Narayana Hotel)
INSERT INTO cash_accounts (business_id, account_name, account_type, is_default_account) VALUES
(1, 'Kas Operasional', 'cash', 1),
(1, 'Rekening Bank', 'bank', 0),
(1, 'Kas Modal Owner', 'owner_capital', 0);

-- 5. Insert default accounts (Business ID 2 - Bens Cafe)
INSERT INTO cash_accounts (business_id, account_name, account_type, is_default_account) VALUES
(2, 'Kas Operasional', 'cash', 1),
(2, 'Rekening Bank', 'bank', 0),
(2, 'Kas Modal Owner', 'owner_capital', 0);
```

#### **Database:** `adfb2574_narayana_hotel`

```sql
-- Add columns to cash_book table
ALTER TABLE `cash_book` ADD COLUMN `cash_account_id` INT(11) DEFAULT NULL AFTER `category_id`;
ALTER TABLE `cash_book` ADD COLUMN `payment_method` ENUM('cash','card','bank_transfer','qris','other') DEFAULT 'cash' AFTER `amount`;
ALTER TABLE `cash_book` ADD COLUMN `reference_number` VARCHAR(100) DEFAULT NULL AFTER `payment_method`;
```

#### **Database:** `adfb2574_benscafe`

```sql
-- Add columns to cash_book table
ALTER TABLE `cash_book` ADD COLUMN `cash_account_id` INT(11) DEFAULT NULL AFTER `category_id`;
ALTER TABLE `cash_book` ADD COLUMN `payment_method` ENUM('cash','card','bank_transfer','qris','other') DEFAULT 'cash' AFTER `amount`;
ALTER TABLE `cash_book` ADD COLUMN `reference_number` VARCHAR(100) DEFAULT NULL AFTER `payment_method`;
```

---

## ✅ VERIFIKASI SETELAH MIGRASI

### 1. Cek Tabel di Master Database
```sql
-- Check tables exist
SHOW TABLES LIKE 'cash%';
SHOW TABLES LIKE 'shift_logs';

-- Check accounts created
SELECT * FROM cash_accounts;
```

**Expected Result:**
- 3 tables: `cash_accounts`, `cash_account_transactions`, `shift_logs`
- 6 rows in `cash_accounts` (3 per business)

---

### 2. Cek Kolom di Business Database
```sql
-- Check columns added
SHOW COLUMNS FROM cash_book LIKE 'cash_account_id';
SHOW COLUMNS FROM cash_book LIKE 'payment_method';
SHOW COLUMNS FROM cash_book LIKE 'reference_number';
```

**Expected Result:**
- 3 new columns in `cash_book` table

---

### 3. Test Feature di Browser

#### Test 1: Dropdown Akun Kas
1. Login ke https://adfsystem.online
2. Buka: **Buku Kas Besar** → **Tambah Transaksi**
3. Cek dropdown **"Pilih Akun"**
   - ✅ Harus muncul 3 pilihan:
     - 💵 Kas Operasional (Uang cash dari tamu)
     - 🏦 Rekening Bank (Hasil transfer dari tamu)
     - 💰 Kas Modal Owner (Modal operasional dari owner)

#### Test 2: Owner Monitoring
1. Login sebagai Owner
2. Buka: **Owner Dashboard**
3. Cek 3 metric cards:
   - ✅ **Saldo Operasional** (biru) - Harus muncul angka
   - ✅ **Cash dari Owner** (kuning) - Harus muncul angka
   - ✅ **Pendapatan Tamu** (hijau) - Harus muncul angka

#### Test 3: Invoice Design
1. Buka: **Front Desk** → **In-House Guests**
2. Klik **Invoice** pada booking
3. Cek tampilan:
   - ✅ Logo perusahaan tampil (kiri atas)
   - ✅ Company info tampil (kanan atas)
   - ✅ Layout professional & compact

---

## 🔍 TROUBLESHOOTING

### Error: "Column already exists"
**Solusi:** Aman, berarti kolom sudah ada. Migration script akan skip.

### Error: "Table already exists"
**Solusi:** Aman, berarti tabel sudah ada. Migration script akan skip.

### Error: "Access denied"
**Solusi:** 
1. Pastikan login sebagai admin/developer/owner
2. Check file `config/config.php` untuk kredensial DB

### Dropdown masih kosong setelah migrasi
**Solusi:**
1. Akses `https://adfsystem.online/debug-cash-accounts.php`
2. Cek apakah accounts sudah ter-insert
3. Jika belum, jalankan manual INSERT via phpMyAdmin

---

## 📝 CHECKLIST DEPLOYMENT

### Pre-Migration
- [x] File PHP sudah di-deploy via Git ✅
- [ ] Backup database hosting (WAJIB!)
- [ ] Screenshot dropdown error (sudah ada)
- [ ] Test di lokal (sudah berhasil)

### Migration
- [ ] Akses migrate-hosting-database.php
- [ ] Review preview perubahan
- [ ] Klik "JALANKAN MIGRASI"
- [ ] Tunggu proses selesai
- [ ] Screenshot hasil migrasi

### Post-Migration
- [ ] Verifikasi tabel di phpMyAdmin
- [ ] Test dropdown akun kas
- [ ] Test owner monitoring
- [ ] Test invoice design
- [ ] Test chart legend colors
- [ ] Commit & push jika ada manual fix

---

## 🎯 EXPECTED OUTCOME

Setelah migrasi berhasil:

✅ **Dropdown Akun Kas:** Muncul 3 pilihan di form Buku Kas  
✅ **Owner Monitoring:** Pisah Modal Owner vs Pendapatan Operasional  
✅ **Invoice Design:** Logo + company info tampil professional  
✅ **Chart Colors:** Legend text readable di dark/light theme  
✅ **No More Errors:** Column not found error hilang  

---

## 📞 KONTAK SUPPORT

Jika ada masalah:
1. Screenshot error lengkap
2. Check debug-cash-accounts.php untuk diagnosis
3. Export log dari migration script
4. Hubungi developer

---

**Last Updated:** 15 Feb 2026, 10:20 WIB  
**Migration Script Version:** 1.0  
**Status:** 🟡 READY FOR DEPLOYMENT
