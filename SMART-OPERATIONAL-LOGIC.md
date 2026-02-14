# 🧠 Smart Operational Logic

## Overview
Sistem sekarang memiliki **logika pintar** untuk manajemen kas operasional yang otomatis mencegah saldo minus.

## How It Works

### 1. **Normal Flow - Petty Cash Cukup**
```
User Input Pengeluaran:
- Pilih Akun: Petty Cash
- Jumlah: Rp 100.000
- Petty Cash Balance: Rp 200.000

✅ Result: 
   - Potong dari Petty Cash
   - Saldo Petty Cash: Rp 200.000 - Rp 100.000 = Rp 100.000
```

### 2. **Smart Logic - Petty Cash Habis/Tidak Cukup**
```
User Input Pengeluaran:
- Pilih Akun: Petty Cash
- Jumlah: Rp 500.000
- Petty Cash Balance: Rp 100.000 (TIDAK CUKUP!)
- Modal Owner Balance: Rp 2.000.000

⚡ SMART AUTO-SWITCH:
   - System detect: Petty Cash tidak cukup
   - Auto switch to: Modal Owner
   - Potong dari Modal Owner: Rp 500.000
   - Saldo Modal Owner: Rp 2.000.000 - Rp 500.000 = Rp 1.500.000
   
✅ Success Message:
   "Transaksi berhasil! ⚡ Petty Cash tidak cukup, otomatis dipotong dari Modal Owner."
   
📝 Description Updated:
   Original: "Bayar listrik"
   Auto: "Bayar listrik [AUTO: Petty Cash habis, potong dari Modal Owner]"
```

### 3. **Balance Update Logic**
```
INCOME Transaction:
   current_balance = current_balance + amount
   
EXPENSE Transaction:
   current_balance = current_balance - amount
```

## Business Logic

### Operational Cash Hierarchy:
1. **Petty Cash (First Priority)**
   - Cash dari tamu hotel
   - Digunakan pertama untuk pengeluaran operasional
   
2. **Modal Owner (Backup)**
   - Modal operasional dari owner
   - Digunakan otomatis ketika Petty Cash habis

### Key Features:
✅ **No Negative Balance** - System prevents minus saldo
✅ **Auto Switch** - Seamless transition dari Petty Cash ke Modal Owner
✅ **Transparent Tracking** - Notification di description dan success message
✅ **Real-time Balance** - current_balance updated setiap transaksi

## Testing Scenario

### Test 1: Normal Petty Cash
```
1. Input Pemasukan Cash Rp 500K → Petty Cash
2. Cek Dashboard: Petty Cash = Rp 500K
3. Input Pengeluaran Rp 200K → Pilih Petty Cash
4. Success: Potong dari Petty Cash
5. Cek Dashboard: Petty Cash = Rp 300K
```

### Test 2: Smart Auto-Switch
```
1. Pastikan Petty Cash = Rp 100K
2. Pastikan Modal Owner = Rp 2.000K
3. Input Pengeluaran Rp 500K → Pilih Petty Cash
4. ⚡ System detect insufficient balance
5. Auto switch to Modal Owner
6. Success message tampil notifikasi
7. Cek Dashboard:
   - Petty Cash = Rp 100K (tidak berubah)
   - Modal Owner = Rp 1.500K (berkurang Rp 500K)
   - Total Kas Operasional tetap akurat
```

### Test 3: Both Insufficient (Edge Case)
```
1. Petty Cash = Rp 50K
2. Modal Owner = Rp 100K
3. Input Pengeluaran Rp 200K → Pilih Petty Cash
4. ❌ Auto-switch gagal (Modal Owner juga tidak cukup)
5. Transaksi tetap pakai Petty Cash (akan minus)
6. Warning: Perlu tambah modal dari owner
```

## Dashboard Impact

Widget **Daily Operational** akan show:
- **Modal Owner**: Setoran dari owner
- **Petty Cash**: Saldo cash dari tamu
- **TOTAL KAS**: Petty Cash + Modal Owner (available cash)
- **Digunakan**: Total pengeluaran operasional (dari Petty Cash + Modal Owner)

## Benefits

1. 💰 **Smart Cash Management** - Auto optimization penggunaan kas
2. 🚫 **No Negative Balance** - Prevent accounting errors
3. 📊 **Accurate Reporting** - Real-time balance tracking
4. ⚡ **User Friendly** - No manual intervention needed
5. 🔍 **Transparent** - Clear notification when auto-switch happens

## Notes

- Smart logic ONLY applies to EXPENSE transactions
- Only switches when account type = 'cash' (Petty Cash)
- Requires Modal Owner account with sufficient balance
- Description auto-updated for audit trail
- Balance updates happen atomically (transaction-safe)

---
Last Updated: February 14, 2026
System: Narayana Hotel Management
