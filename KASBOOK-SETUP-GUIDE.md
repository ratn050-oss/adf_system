# 📊 KASBOOK SYSTEM - Setup & Troubleshooting Guide

## Overview

Kasbook (Kasir Buku = Cash Book) adalah sistem tracking kas harian yang SIMPLE dan CLEAR. Tujuannya:

✅ **Admin bisa lihat dalam 3 detik:**
- Berapa uang masuk hari ini? (dari owner + revenue)
- Berapa uang keluar hari ini? (operasional)
- Berapa saldo akhir hari ini? (cash di tangan)

✅ **Memisahkan dengan JELAS:**
- Modal dari Owner → `[OWNER]` tag
- Revenue hotel → `[REVENUE]` tag
- Pengeluaran → tracking by transaction

---

## 📋 REQUIREMENTS

### Database Tables

Kasbok membutuhkan 2 tables yang WAJIB ada:

1. **`cash_accounts`** - Menyimpan akun kas
   - Columns: id, account_name, account_type, business_id, current_balance, opening_balance, description
   - Account types: `owner_capital`, `petty_cash`, `cash`

2. **`cash_account_transactions`** - Menyimpan transaksi kas
   - Columns: id, cash_account_id, transaction_type (debit/credit), amount, description, reference_number, transaction_date, created_by, created_at

### Configuration

File `config/config.php` harus punya:
```php
define('MASTER_DB_NAME', 'adfb2574_narayana');  // atau nama MASTER DB Anda
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', 'password');
define('ACTIVE_BUSINESS_ID', 'narayana-hotel');  // atau 'bens-cafe'
```

---

## 🚀 SETUP LANGKAH DEMI LANGKAH

### STEP 1: Buat Tables (jika belum ada)

1. Buka **phpMyAdmin** → Pilih database MASTER Anda
2. Tab **SQL** 
3. Copy semua isi dari file: `database-kasbook-setup.sql`
4. Paste ke SQL editor
5. Klik **Execute**

**Expected output:**
```
Query executed successfully
✓ cash_accounts table created
✓ cash_account_transactions table created
✓ Default accounts inserted for Narayana Hotel (business_id=1)
✓ Default accounts inserted for Ben's Cafe (business_id=2)
```

### STEP 2: Verify Tables Created

Jalankan query ini di phpMyAdmin → SQL:
```sql
SELECT * FROM cash_accounts;
SELECT * FROM cash_account_transactions;
```

Harus ada output dengan 6 rows di cash_accounts (3 untuk Narayana + 3 untuk Ben's).

### STEP 3: Test Kedua File

#### Test 3A: kasbook-daily-simple-v2.php (READ ONLY - No errors expected)

1. Buka: `http://localhost/narayanakarimunjawa/adf_sytem/modules/owner/kasbook-daily-simple-v2.php`
2. Lihat hasil:
   - Harus tampil 3 cards: Kas Masuk, Kas Keluar, Saldo Akhir
   - Harus ada date picker atas
   - Harus ada table detail transaksi (sementara kosong karena belum ada data)
3. Test date picker:
   - Ubah tanggal
   - Klik "Load"
   - Hasil harus update

**Jika error:**
- ❌ "Petty Cash account tidak ditemukan" → Database belum run SQL setup
- ❌ "Database Error" → Check config.php MASTER_DB_NAME atau DB credentials
- ❌ Blank/White screen → Check PHP error log

#### Test 3B: kasbook-entry-v2.php (FORM - Create transaction)

1. Buka: `http://localhost/narayanakarimunjawa/adf_sytem/modules/owner/kasbook-entry-v2.php`
2. Lihat saldo kas saat ini (harus tampil di atas form)
3. Test tambah Kas Masuk:
   - Pilih **Kas Masuk**
   - Pilih **Setoran dari Owner**
   - Nominal: **500000**
   - Keterangan: **Test setoran modal pagi**
   - Klik **Simpan Transaksi**
   - Expected: ✅ Transaksi berhasil disimpan!
4. Test tambah Kas Keluar:
   - Pilih **Kas Keluar**
   - Nominal: **100000**
   - Keterangan: **Test belanja supplies**
   - Klik **Simpan Transaksi**
   - Expected: ✅ Transaksi berhasil disimpan!

**Jika error:**
- ❌ Form tidak submit → Check browser console (F12 → Console)
- ❌ "Gagal menyimpan" → Check PHP error log atau database connection

#### Test 3C: Verify Data Saved

1. Buka kembali `kasbook-daily-simple-v2.php`
2. Lihat hasilnya:
   - **Kas Masuk Hari Ini**: Rp 500,000 (dari owner) + Rp 0 (dari revenue) = Rp 500,000
   - **Kas Keluar Hari Ini**: Rp 100,000
   - **Saldo Akhir**: Rp 400,000 (500k - 100k)
   - **Detail Transaksi**: 2 transaksi tampil (Kas Masuk + Kas Keluar)

Jika hasilnya sesuai → **✅ KASBOOK READY TO USE!**

---

## 🔍 TROUBLESHOOTING

### Problem 1: "Petty Cash account tidak ditemukan"

**Cause:** Table `cash_accounts` tidak ada atau tidak ada record dengan account_type='petty_cash'

**Fix:**
```sql
-- Verify table exists
SHOW TABLES LIKE 'cash_accounts';

-- Check if petty_cash account exists
SELECT * FROM cash_accounts WHERE account_type = 'petty_cash';

-- If no result, run database-kasbook-setup.sql again
```

### Problem 2: "Database Error" / Connection Failed

**Cause:** config.php MASTER_DB_NAME atau credentials salah

**Fix:**
1. Check `config/config.php`:
   ```php
   define('MASTER_DB_NAME', '???');  // Should match your MASTER database
   ```
2. Test connection di phpMyAdmin
3. Verify DB_HOST, DB_USER, DB_PASS

### Problem 3: Form tidak submit / Data tidak tersimpan

**Cause:** cash_account_id mungkin null atau table structure berbeda

**Fix:**
```sql
-- Check table structure
DESCRIBE cash_account_transactions;

-- Should have these columns:
-- id, cash_account_id, transaction_type, amount, description, reference_number, transaction_date, created_by, created_at
```

### Problem 4: Saldo tidak matching / Kalkulasi salah

**Cause:** Transaksi lama belum di-migrate atau ada transaksi tanpa tag [OWNER]/[REVENUE]

**Fix:**
```sql
-- Update existing transactions dengan tag
UPDATE cash_account_transactions 
SET description = CONCAT('[OWNER] ', description)
WHERE description NOT LIKE '%[OWNER]%' 
  AND description NOT LIKE '%[REVENUE]%'
  AND transaction_type = 'debit';
```

---

## 💾 BACKUP QUERY

Sebelum production, backup data kasbook:
```sql
-- Backup cash_accounts
SELECT * INTO OUTFILE '/tmp/cash_accounts_backup.sql' FROM cash_accounts;

-- Backup transactions
SELECT * INTO OUTFILE '/tmp/cash_account_transactions_backup.sql' FROM cash_account_transactions;
```

---

## 📱 FEATURES KASBOOK

### kasbook-daily-simple-v2.php ✅
- ✅ 3 card summary (Kas Masuk, Kas Keluar, Saldo)
- ✅ Date picker untuk view per hari
- ✅ Detail transaction table dengan timestamp
- ✅ Breakdown owner vs revenue untuk Kas Masuk
- ✅ Print friendly layout
- ✅ Mobile responsive

### kasbook-entry-v2.php ✅
- ✅ Simple radio button untuk Kas Masuk vs Kas Keluar
- ✅ Source selection (Owner vs Revenue)
- ✅ Auto-tag description dengan [OWNER] atau [REVENUE]
- ✅ Amount + Keterangan + Date fields
- ✅ Reference number (opsional) untuk invoice tracking
- ✅ Real-time saldo display
- ✅ Success/error message feedback

---

## 🎯 NEXT STEPS FOR ADMIN

1. **Run SQL setup** di phpMyAdmin
2. **Test both files** para verify no errors
3. **Add links to dashboard** pointing to kasbook files
4. **Start using daily**:
   - Open kasbook-daily-simple-v2.php pagi hari
   - Lihat Kas Masuk (harap bertemu dengan physical cash)
   - Gunakan kasbook-entry-v2.php untuk record setiap transaksi
   - Check saldo akhir hari sebelum tutup
5. **Daily checklist**:
   - Kas Masuk dari owner sesuai? ✓
   - Revenue cash masuk? ✓
   - Pengeluaran tercatat? ✓
   - Saldo akhir cocok dengan cash fisik? ✓

---

## 🆘 SUPPORT

Jika ada error tidak terdaftar di troubleshooting:

1. Check PHP error log: `/logs/php_errors.log`
2. Check browser console: F12 → Console tab
3. Check MySQL error: `SELECT * FROM INFORMATION_SCHEMA.REFERENTIAL_CONSTRAINTS;`
4. Enable debug mode di config.php: `define('DEBUG', true);`

---

## 📊 DATABASE SCHEMA REFERENCE

### cash_accounts Table
```sql
CREATE TABLE cash_accounts (
    id INT PRIMARY KEY AUTO_INCREMENT,
    account_name VARCHAR(100),                    -- e.g., "Petty Cash - Narayana"
    account_type ENUM('owner_capital','petty_cash','cash'),  
    business_id INT,                              -- 1=Narayana, 2=Ben's Cafe
    current_balance DECIMAL(15,2),               -- Saldo saat ini
    opening_balance DECIMAL(15,2),               -- Saldo awal periode
    description TEXT,
    is_active BOOLEAN,
    created_at TIMESTAMP,
    updated_at TIMESTAMP
);
```

### cash_account_transactions Table
```sql
CREATE TABLE cash_account_transactions (
    id INT PRIMARY KEY AUTO_INCREMENT,
    cash_account_id INT NOT NULL,               -- FK to cash_accounts
    transaction_type ENUM('debit','credit'),   -- 'debit'=masuk, 'credit'=keluar
    amount DECIMAL(15,2) NOT NULL,
    description TEXT NOT NULL,                 -- "[OWNER] ...", "[REVENUE] ...", or plain text
    reference_number VARCHAR(50),              -- e.g., "INV-001", optional
    transaction_date DATE NOT NULL,            -- Tanggal transaksi terjadi
    created_by INT,                            -- User ID who created
    created_at TIMESTAMP                       -- Saat record dibuat
);
```

---

Version: **2.0** (Fixed - Optimized Queries)
Last Updated: **2024**
Status: **READY FOR PRODUCTION**
