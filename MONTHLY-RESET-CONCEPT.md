## 💡 Konsep Sistem Kas Operasional - Monthly Reset

Saya akan jelaskan bagaimana sistem saat ini bekerja dan konsep yang bisa diterapkan untuk reset monthly:

## 🎯 **Sistem Saat Ini (NO Monthly Reset)**

### Current Logic:
- **Modal Owner**: Rp 2.566.712 (Balance bersifat **kumulatif**)
- **Petty Cash**: Rp 266.400 (Balance bersifat **kumulatif**)
- **Total**: `current_balance` terus bertambah/berkurang, **TIDAK reset tiap bulan**

### Yang Terjadi Sekarang:
1. **Owner invest modal** → Modal Owner +Rp 1.000.000
2. **Bulan berikutnya** → Balance tetap Rp 1.000.000 (tidak reset)
3. **Sisa operational** → Tetap tersimpan di `current_balance`

---

## 🔄 **Konsep Monthly Reset (Rekomendasi)**

### Option 1: **Auto-Transfer ke Owner (End of Month)**
```sql
-- Di akhir bulan, sisa operational transfer ke Owner
INSERT INTO cash_account_transactions (
    cash_account_id, amount, transaction_type, 
    description, transaction_date
) VALUES (
    owner_capital_id, 
    petty_cash_balance, 
    'capital_return',
    'Monthly operational return - ' + MONTH_YEAR,
    LAST_DAY(CURRENT_DATE)
);

-- Reset Petty Cash ke 0 untuk bulan baru
UPDATE cash_accounts 
SET current_balance = 0 
WHERE account_type = 'cash' AND business_id = ?;
```

### Option 2: **Monthly Statement Only (Saldo Tetap)**
- Saldo **tidak reset**, hanya buat **monthly report**
- Owner bisa withdraw manual kapan saja
- Balance tetap kumulatif sepanjang tahun

### Option 3: **Manual Reset with Closing Entry**
- End of month: Owner pilih withdraw excess cash
- System create "Monthly Closing" transaction
- Transfer sisa ke Owner account manually

---

## 📊 **Implementasi yang Direkomendasikan**

### Step 1: **Monthly Closing Process**
```php
// End of month process (manual trigger)
function monthlyClosing($businessId) {
    $pettyCashBalance = getCurrentPettyCashBalance($businessId);
    $minimumOperational = 500000; // Rp 500K minimum
    
    $excessCash = $pettyCashBalance - $minimumOperational;
    
    if ($excessCash > 0) {
        // Transfer excess ke Owner
        transferToOwner($excessCash, "Monthly operational return");
        
        // Update Petty Cash balance
        updatePettyCashBalance($minimumOperational);
    }
}
```

### Step 2: **Dashboard Indicator**
- Tambah button "Monthly Closing" (visible untuk Owner role)
- Show monthly profit/loss summary
- Show recommended minimum operational cash

### Step 3: **Report Enhancement**
- Monthly P&L statement
- Cash flow analysis
- Owner capital return tracking

---

## 🤔 **Pertanyaan untuk Owner**

1. **Apakah mau reset otomatis tiap bulan?**
   - Ya: Implement auto-transfer system
   - Tidak: Keep current cumulative system

2. **Berapa minimum cash operational?**
   - Contoh: Rp 500.000 selalu tersisa untuk operasional

3. **Kapan timing reset?**
   - Otomatis tanggal 1 tiap bulan?
   - Manual trigger by Owner?
   - End of month (tanggal 30/31)?

4. **Ke mana sisa operational?**
   - Transfer ke Owner personal account?
   - Keep di Modal Owner balance?
   - Create new "Owner Savings" account?

---

**Sistem saat ini AMAN dan BENAR untuk akuntansi**, hanya perlu enhancement untuk monthly management berdasarkan preferensi bisnis Owner.