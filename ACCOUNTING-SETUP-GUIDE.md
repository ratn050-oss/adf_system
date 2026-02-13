# 🔧 Multi-Account Cash System - Local Development Implementation Guide

## 📋 Overview

Sistem ini menambahkan fitur **Multi-Account Cash Management** untuk memisahkan:
- **Kas Operasional**: Pendapatan dari operasional bisnis
- **Kas Modal Owner**: Dana dari pemilik untuk operasional harian
- **Bank**: Transaksi perbankan

Ini memecahkan masalah: Uang modal dari owner tidak boleh tercatat sebagai revenue/pemasukan.

## ⚠️ PENTING: LOCAL DEVELOPMENT ONLY

**JANGAN JALANKAN SETUP INI DI PRODUCTION!**
- Sistem deteksi otomatis hanya mengizinkan akses dari localhost
- Production database di hosting terlindungi
- Testing HARUS dilakukan di local development dulu

---

## 🚀 Step 1: Browser-Based Setup Wizard

### Langkah Pertama: Akses Setup Wizard

1. Buka browser di lokasi yang mendukung port 8081:
   ```
   http://localhost:8081/adf_system/tools/setup-accounting-local-safe.php
   ```

2. Klik tombol **Next →** untuk setiap step

### Step 1: Intro
- Bacalah penjelasan sistem
- Pahami 3 akun default yang akan dibuat

### Step 2: Create Master Database Tables
- Klik **Next →** untuk membuat tabel:
  - `cash_accounts` - Master akun kas
  - `cash_account_transactions` - Riwayat transaksi per akun
- Tunggu pesan ✅ success

### Step 3: Add Columns to Business Databases
- Sistem akan add kolom `cash_account_id` ke tabel `cash_book` setiap business
- Akan skip jika kolom sudah ada (aman untuk re-run)

### Step 4: Create Default Cash Accounts
- 3 akun default dibuat per business:
  1. **Kas Operasional** (cash) - Pendapatan operasional
  2. **Kas Modal Owner** (owner_capital) - Dana dari pemilik
  3. **Bank** (bank) - Transaksi bank

**Setelah Step 4 selesai**: Setup lokal BERHASIL ✅

---

## 📝 Step 2: Test Form Cashbook (Kasir)

### Buka Form Input Cashbook

1. Login ke sistem dengan kasir/user normal
2. Go to: **Cashbook → Add New Transaction**
3. Atau langsung ke: `http://localhost:8081/adf_system/modules/cashbook/add.php`

### Lihat Field Baru: "Akun Kas"

Form sekarang memiliki dropdown **"Akun Kas"**:
```
- Kas Operasional (default)
- Kas Modal Owner
- Bank
```

### Test Input Pemasukan

**Scenario 1: Penjualan Kamar (Income)**
- Tanggal: Hari ini
- Divisi: Front Desk
- Kategori: Room Service
- Akun Kas: **Kas Operasional** ✓ (Pemasukan operasional)
- Jumlah: 500.000
- Click **Simpan Transaksi**

**Expected**: Transaksi tercatat dengan cash_account_id = Kas Operasional

---

## 💰 Step 3: Test Setoran Modal Owner

### Input Setoran Modal

1. Go to **Cashbook → Add New Transaction**
2. Fill form:
   - Tipe Transaksi: **Pemasukan** (sebagai dana masuk)
   - Divisi: Finance/Owner
   - Kategori: **Setoran Modal Owner** (buat category baru jika belum ada)
   - Akun Kas: **Kas Modal Owner** ✓ (PENTING!)
   - Jumlah: 10.000.000
   - Deskripsi: "Setoran modal bulanan dari owner"
3. Click **Simpan Transaksi**

**Expected**: 
- Transaksi tercatat di Kas Modal Owner
- Bukan tercatat sebagai revenue di dashboard

---

## 📊 Step 4: Monitor Di Owner Dashboard

### Akses Owner Capital Monitor

1. Go to: **Owner → Monitor Kas Modal Owner**
2. Atau langsung: `http://localhost:8081/adf_system/modules/owner/owner-capital-monitor.php`

### Lihat Statistik Bulan Ini

Dashboard menampilkan:
- **Modal Diterima**: Rp 10.000.000 (dari Step 3)
- **Modal Digunakan**: Rp 0 (belum ada pengeluaran)
- **Saldo Kas Modal**: Rp 10.000.000
- **Efisiensi Modal**: 0%
- **Trend Chart**: Grafik mingguan penggunaan modal

### Edit Transaksi

1. Go to **Cashbook → List Transaksi**
2. Klik transaksi yang sudah dibuat untuk edit
3. Lihat field **"Akun Kas"** sekarang bisa diubah
4. Test ubah dari "Kas Operasional" ke "Kas Modal Owner" untuk lihat update

---

## 🔄 Step 5: Complete Workflow Test

### Scenario: Owner Kirim Modal, Kasir Gunakan

**User 1: Owner - Setor Modal**
1. Input transaksi:
   - Type: Pemasukan
   - Kategori: Setoran Modal
   - Account: **Kas Modal Owner**
   - Jumlah: 5.000.000

**User 2: Kasir - Pengeluaran dari Modal**
1. Input transaksi:
   - Type: Pengeluaran
   - Kategori: Gaji Karyawan / Supplies
   - Account: **Kas Modal Owner** (pengeluaran dari modal)
   - Jumlah: 2.000.000

**User 3: Owner - Monitor Di Dashboard**
1. Akses owner-capital-monitor.php
2. Lihat:
   - Modal Diterima: Rp 5.000.000
   - Modal Digunakan: Rp 2.000.000
   - Saldo: Rp 3.000.000
   - Efisiensi: 40%

**Expected Result**: 
- ✅ Modal terpisah dari operasional
- ✅ Owner bisa monitor pengeluaran modal
- ✅ Dashboard menunjukkan akurat

---

## 🛠️ Troubleshooting

### Problem 1: Setup wizard returns 404
**Solution:**
- Pastikan file ada: `tools/setup-accounting-local-safe.php`
- Clear browser cache dengan Ctrl+Shift+Delete
- Akses dengan port: `localhost:8081`

### Problem 2: "Kas Modal Owner account not found"
**Solution:**
- Jalankan setup wizard Step 4 lagi
- Verify di database: 
  ```sql
  SELECT * FROM cash_accounts WHERE account_type = 'owner_capital';
  ```

### Problem 3: Form tidak menampilkan "Akun Kas"
**Solution:**
- Clear browser cache
- Refresh page dengan Ctrl+F5 (hard refresh)
- Verify database column: 
  ```sql
  SHOW COLUMNS FROM cash_book LIKE 'cash_account_id';
  ```

### Problem 4: Error "Access denied" saat setup
**Solution:**
- Gunakan environment yang support MySQL lokal
- Pastikan credentials: root / (no password)
- Jika error, tambahkan di setup-accounting-local-safe.php:
  ```php
  // After line 20
  if (!$isLocalhost) {
      echo "Current HOST: " . ($_SERVER['HTTP_HOST'] ?? 'NOT SET') . "\n";
      die('DEBUG: Check HOST value above');
  }
  ```

---

## 📂 File Structure (Local Development)

```
adf_system/
├── tools/
│   ├── setup-accounting-local-safe.php   ← SETUP WIZARD (Browser only)
│   ├── setup-accounting-cli.php          ← CLI version (archived)
│   └── setup-accounting-local.php        ← Old version (archived)
├── modules/
│   ├── cashbook/
│   │   ├── add.php                       ← UPDATED: Added account selection
│   │   └── edit.php                      ← UPDATED: Added account selection
│   └── owner/
│       └── owner-capital-monitor.php     ← NEW: Owner monitoring dashboard
└── database/
    └── adf_benscafe (example business)
        └── cash_book                     ← UPDATED: Added cash_account_id column
```

---

## 🚀 Next: When Ready for Production

Setelah testing sukses lokal, untuk deploy ke production:

1. **Manual SQL Setup** (Don't use auto-script on production)
   ```sql
   -- Run on hosting master database
   USE adfb2574_adf;
   CREATE TABLE IF NOT EXISTS `cash_accounts` (
       -- Schema sama seperti di setup wizard
   );
   
   CREATE TABLE IF NOT EXISTS `cash_account_transactions` (
       -- Schema sama seperti di setup wizard
   );
   ```

2. **Update Each Business Database**
   ```sql
   -- For each business (adfb2574_narayana_hotel, etc)
   ALTER TABLE `cash_book` ADD COLUMN `cash_account_id` INT(11) DEFAULT NULL;
   ```

3. **Insert Default Accounts**
   ```sql
   -- For each business (set business_id appropriately)
   INSERT INTO cash_accounts (business_id, account_name, account_type, is_default_account)
   VALUES 
   (1, 'Kas Operasional', 'cash', 1),
   (1, 'Kas Modal Owner', 'owner_capital', 0),
   (1, 'Bank', 'bank', 0);
   ```

4. **Verify Production**
   - Login to hosting system
   - Test cashbook form with account selection
   - Test owner dashboard

---

## 📞 Support

Jika ada masalah:
1. Check troubleshooting section
2. Review database schema dengan tools/setup-accounting-local-safe.php
3. Verify cash_account_id column di cash_book table
4. Test dengan browser developer console (F12)

---

## 📚 Implementation Timeline

✅ **Completed**:
- [x] Local setup wizard created
- [x] Database schema designed & implemented
- [x] Cashbook forms updated with account selection
- [x] Owner monitoring dashboard built
- [x] Git commit with documentation

🟡 **Testing** (Current Step):
- [ ] Run setup wizard steps 1-4  
- [ ] Test cashbook input with different accounts
- [ ] Monitor owner capital dashboard
- [ ] Verify all calculations correct

⏳ **Next Phase**:
- [ ] Add category "Setoran Modal Owner" if needed
- [ ] Create monthly reconciliation report
- [ ] Manual production deployment (when ready)
- [ ] Train owner on dashboard usage

---

**Date Created**: February 13, 2026  
**Version**: 1.0 - Local Development  
**Status**: 🟢 Ready for Testing
