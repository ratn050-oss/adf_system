# FRONTDESK MODULE — COMPREHENSIVE SQL COLUMN AUDIT

> **Generated:** Read-only audit of every SQL column referenced in frontdesk module and related API files.  
> **Scope:** 19 PHP files + 3 SQL schema files across `modules/frontdesk/`, `api/`, and `public/api/`.

---

## FILES AUDITED

| # | File | Lines |
|---|------|-------|
| 1 | `modules/frontdesk/index.php` | 1061 |
| 2 | `modules/frontdesk/dashboard.php` | 2056 |
| 3 | `modules/frontdesk/reservasi.php` | 2006 |
| 4 | `modules/frontdesk/calendar.php` | 5431 |
| 5 | `modules/frontdesk/edit-booking.php` | 276 |
| 6 | `modules/frontdesk/settings.php` | 1447 |
| 7 | `modules/frontdesk/invoice.php` | 603 |
| 8 | `modules/frontdesk/in-house.php` | 742 |
| 9 | `modules/frontdesk/laporan.php` | 1013 |
| 10 | `modules/frontdesk/breakfast.php` | 1002 |
| 11 | `modules/frontdesk/breakfast-old.php` | 853 |
| 12 | `modules/frontdesk/export-daily-report.php` | 624 |
| 13 | `api/get-booking-details.php` | 98 |
| 14 | `api/add-booking-payment.php` | ~180 |
| 15 | `api/move-booking.php` | ~170 |
| 16 | `api/delete-booking.php` | ~80 |
| 17 | `api/cancel-booking.php` | 334 |
| 18 | `api/update-reservation.php` | 200 |
| 19 | `public/api/create-booking.php` | ~200 |

**Schema sources:** `database/frontdesk_new.sql`, `database/migration-checkin-checkout.sql`, `database/breakfast_menu.sql`, `_missing_tables.sql`, `adf_narayana_hotel_v2.sql`

---

## TABLE 1: `room_types`

**Schema:** `database/frontdesk_new.sql`

| Column | Type | Default | Used In |
|--------|------|---------|---------|
| `id` | INT PK AUTO_INCREMENT | — | reservasi.php, calendar.php, dashboard.php, settings.php, in-house.php |
| `type_name` | VARCHAR(100) NOT NULL | — | reservasi.php, calendar.php, dashboard.php, settings.php, edit-booking.php, invoice.php, in-house.php |
| `description` | TEXT | NULL | settings.php |
| `base_price` | DECIMAL(12,2) NOT NULL | 0 | reservasi.php, calendar.php, dashboard.php, settings.php, in-house.php, api/update-reservation.php, api/move-booking.php, public/api/create-booking.php |
| `max_occupancy` | INT NOT NULL | 2 | settings.php, public/api/create-booking.php |
| `amenities` | TEXT | NULL | settings.php |
| `color_code` | VARCHAR(7) | '#6366f1' | reservasi.php, calendar.php, settings.php |
| `created_at` | TIMESTAMP | CURRENT_TIMESTAMP | settings.php |
| `updated_at` | TIMESTAMP | CURRENT_TIMESTAMP ON UPDATE | settings.php |

**FK references:** `rooms.room_type_id → room_types.id`

---

## TABLE 2: `rooms`

**Schema:** `database/frontdesk_new.sql` + `database/migration-checkin-checkout.sql`

| Column | Type | Default | Used In |
|--------|------|---------|---------|
| `id` | INT PK AUTO_INCREMENT | — | index.php, dashboard.php, reservasi.php, calendar.php, settings.php, in-house.php, laporan.php, public/api/create-booking.php |
| `room_number` | VARCHAR(20) NOT NULL UNIQUE | — | reservasi.php, calendar.php, dashboard.php, settings.php, edit-booking.php, invoice.php, in-house.php, laporan.php, export-daily-report.php, api/add-booking-payment.php, public/api/create-booking.php |
| `room_type_id` | INT NOT NULL | — (FK→room_types.id) | reservasi.php, calendar.php, settings.php, edit-booking.php, invoice.php, in-house.php, laporan.php, api/update-reservation.php, api/move-booking.php, public/api/create-booking.php |
| `floor_number` | INT NOT NULL | 1 | reservasi.php, calendar.php, settings.php, in-house.php |
| `status` | ENUM('available','occupied','cleaning','maintenance','blocked') | 'available' | index.php (UPDATE), dashboard.php (SELECT/UPDATE), reservasi.php, calendar.php, settings.php, laporan.php, public/api/create-booking.php |
| `notes` | TEXT | NULL | settings.php |
| `position_x` | INT | 0 | settings.php (schema only) |
| `position_y` | INT | 0 | settings.php (schema only) |
| `current_guest_id` | INT NULL | NULL (FK→guests.id) | index.php (UPDATE to NULL), dashboard.php (UPDATE to NULL) |
| `created_at` | TIMESTAMP | CURRENT_TIMESTAMP | — |
| `updated_at` | TIMESTAMP | CURRENT_TIMESTAMP ON UPDATE | index.php (UPDATE), dashboard.php (UPDATE) |

**Migration note:** `current_guest_id` added by `migration-checkin-checkout.sql`

---

## TABLE 3: `guests`

**Schema:** `database/frontdesk_new.sql`

| Column | Type | Default | Used In |
|--------|------|---------|---------|
| `id` | INT PK AUTO_INCREMENT | — | dashboard.php, in-house.php, api/update-reservation.php (as gid), public/api/create-booking.php (lastInsertId) |
| `guest_name` | VARCHAR(200) NOT NULL | — | reservasi.php, calendar.php, dashboard.php, edit-booking.php, invoice.php, in-house.php, laporan.php, export-daily-report.php, api/get-booking-details.php, api/add-booking-payment.php, api/cancel-booking.php, api/update-reservation.php (UPDATE), public/api/create-booking.php (INSERT) |
| `id_card_type` | ENUM('ktp','passport','sim') | 'ktp' | public/api/create-booking.php (INSERT) |
| `id_card_number` | VARCHAR(50) NOT NULL | — | invoice.php, in-house.php, api/get-booking-details.php, api/update-reservation.php (UPDATE), public/api/create-booking.php (INSERT) |
| `phone` | VARCHAR(20) | NULL | reservasi.php, calendar.php, edit-booking.php, invoice.php, in-house.php, laporan.php, export-daily-report.php, api/get-booking-details.php, api/update-reservation.php (UPDATE), public/api/create-booking.php (INSERT) |
| `email` | VARCHAR(100) | NULL | reservasi.php, edit-booking.php, invoice.php, in-house.php, api/get-booking-details.php, api/update-reservation.php (UPDATE), public/api/create-booking.php (INSERT) |
| `address` | TEXT | NULL | in-house.php |
| `nationality` | VARCHAR(50) | 'Indonesia' | public/api/create-booking.php (INSERT) |
| `created_at` | TIMESTAMP | CURRENT_TIMESTAMP | public/api/create-booking.php (INSERT) |
| `updated_at` | TIMESTAMP | CURRENT_TIMESTAMP ON UPDATE | api/update-reservation.php (UPDATE = NOW()) |

---

## TABLE 4: `bookings`

**Schema:** `database/frontdesk_new.sql` + `database/migration-checkin-checkout.sql`

| Column | Type | Default | Used In |
|--------|------|---------|---------|
| `id` | INT PK AUTO_INCREMENT | — | ALL 19 files |
| `booking_code` | VARCHAR(20) NOT NULL UNIQUE | — | reservasi.php, calendar.php, dashboard.php, invoice.php, in-house.php, laporan.php, export-daily-report.php, api/get-booking-details.php, api/add-booking-payment.php, api/cancel-booking.php, public/api/create-booking.php (INSERT) |
| `guest_id` | INT NOT NULL | — (FK→guests.id) | reservasi.php, calendar.php, dashboard.php, invoice.php, in-house.php, laporan.php, export-daily-report.php, breakfast.php, api/get-booking-details.php, api/add-booking-payment.php, api/cancel-booking.php, api/update-reservation.php, public/api/create-booking.php (INSERT) |
| `room_id` | INT NOT NULL | — (FK→rooms.id) | index.php, reservasi.php, calendar.php, dashboard.php, invoice.php, in-house.php, laporan.php, breakfast.php, api/add-booking-payment.php, api/move-booking.php (UPDATE), api/update-reservation.php, public/api/create-booking.php (INSERT) |
| `check_in_date` | DATE NOT NULL | — | index.php, reservasi.php, calendar.php, dashboard.php, edit-booking.php, invoice.php, in-house.php, laporan.php, export-daily-report.php, breakfast.php, api/get-booking-details.php, api/cancel-booking.php, api/move-booking.php, api/update-reservation.php (UPDATE), public/api/create-booking.php (INSERT) |
| `check_out_date` | DATE NOT NULL | — | index.php, reservasi.php, calendar.php, dashboard.php, edit-booking.php, invoice.php, in-house.php, laporan.php, export-daily-report.php, breakfast.php, api/get-booking-details.php, api/move-booking.php, api/update-reservation.php (UPDATE), public/api/create-booking.php (INSERT) |
| `actual_checkin_time` | DATETIME NULL | NULL | in-house.php (SELECT), laporan.php (SELECT), export-daily-report.php (SELECT) |
| `actual_checkout_time` | DATETIME NULL | NULL | index.php (UPDATE = check_out_date), dashboard.php (UPDATE = check_out_date), in-house.php (SELECT/WHERE) |
| `checked_in_by` | INT NULL | NULL | migration-checkin-checkout.sql (schema only) |
| `checked_out_by` | INT NULL | NULL | migration-checkin-checkout.sql, fix-hosting-cashbook.php (ALTER ADD) |
| `adults` | INT NOT NULL | 1 | reservasi.php, invoice.php, api/get-booking-details.php, api/update-reservation.php (UPDATE), public/api/create-booking.php (INSERT) |
| `children` | INT NOT NULL | 0 | reservasi.php, invoice.php, api/get-booking-details.php, public/api/create-booking.php (INSERT) |
| `room_price` | DECIMAL(12,2) NOT NULL | — | reservasi.php, calendar.php, dashboard.php, edit-booking.php (UPDATE), invoice.php, in-house.php, api/get-booking-details.php, api/move-booking.php (UPDATE), api/update-reservation.php (UPDATE), public/api/create-booking.php (INSERT) |
| `total_nights` | INT NOT NULL | — | reservasi.php, edit-booking.php (UPDATE), api/get-booking-details.php, api/move-booking.php (UPDATE), api/update-reservation.php (UPDATE), public/api/create-booking.php (INSERT) |
| `total_price` | DECIMAL(12,2) NOT NULL | — | reservasi.php, edit-booking.php (UPDATE), invoice.php, api/get-booking-details.php, api/move-booking.php (UPDATE), api/update-reservation.php (UPDATE), public/api/create-booking.php (INSERT) |
| `discount` | DECIMAL(12,2) | 0 | reservasi.php, invoice.php, api/get-booking-details.php, api/move-booking.php, api/update-reservation.php (read only) |
| `final_price` | DECIMAL(12,2) NOT NULL | — | reservasi.php, dashboard.php, edit-booking.php (UPDATE), invoice.php, in-house.php, api/get-booking-details.php, api/add-booking-payment.php, api/move-booking.php (UPDATE), api/update-reservation.php (UPDATE), public/api/create-booking.php (INSERT) |
| `status` | ENUM('pending','confirmed','checked_in','checked_out','cancelled') | 'pending' | index.php (UPDATE), reservasi.php, calendar.php, dashboard.php (UPDATE), in-house.php, laporan.php, breakfast.php, api/get-booking-details.php, api/move-booking.php, api/delete-booking.php, api/cancel-booking.php (UPDATE), public/api/create-booking.php (INSERT/WHERE) |
| `payment_status` | ENUM('unpaid','partial','paid') | 'unpaid' | reservasi.php, calendar.php, dashboard.php, invoice.php, in-house.php, laporan.php, export-daily-report.php, api/get-booking-details.php, api/add-booking-payment.php (UPDATE), public/api/create-booking.php (INSERT) |
| `paid_amount` | DECIMAL(12,2) | 0 | index.php, reservasi.php, dashboard.php, invoice.php, api/get-booking-details.php, api/add-booking-payment.php (UPDATE), api/cancel-booking.php, public/api/create-booking.php (INSERT) |
| `booking_source` | ENUM('walk_in','phone','online','ota') | 'walk_in' | reservasi.php, calendar.php, dashboard.php, invoice.php, in-house.php, api/get-booking-details.php, api/add-booking-payment.php, public/api/create-booking.php (INSERT) |
| `special_request` | TEXT | NULL | reservasi.php, invoice.php, api/get-booking-details.php, api/cancel-booking.php, api/update-reservation.php (UPDATE), public/api/create-booking.php (INSERT) |
| `notes` | TEXT | NULL | public/api/create-booking.php (INSERT) |
| `guest_count` | *(not in schema)* | — | laporan.php (SELECT), export-daily-report.php (SELECT) |
| `created_by` | INT | NULL (FK→users.id) | schema only |
| `created_at` | TIMESTAMP | CURRENT_TIMESTAMP | index.php, dashboard.php, invoice.php, public/api/create-booking.php (INSERT) |
| `updated_at` | TIMESTAMP | CURRENT_TIMESTAMP ON UPDATE | index.php (UPDATE), dashboard.php (UPDATE), edit-booking.php (UPDATE), api/add-booking-payment.php (UPDATE), api/move-booking.php (UPDATE), api/cancel-booking.php (UPDATE), api/update-reservation.php (UPDATE) |

### Schema Conflicts in `bookings`

| Issue | Detail |
|-------|--------|
| `actual_check_in` vs `actual_checkin_time` | Base schema (`frontdesk_new.sql`) defines `actual_check_in DATETIME`. Migration (`migration-checkin-checkout.sql`) adds `actual_checkin_time DATETIME`. PHP code uses **`actual_checkin_time`**. |
| `actual_check_out` vs `actual_checkout_time` | Same conflict. PHP code uses **`actual_checkout_time`**. |
| `booking_source` ENUM mismatch | Schema defines `ENUM('walk_in','phone','online','ota')`. PHP code (dashboard.php sync) references `'agoda'`, `'booking'`, `'tiket'`, `'airbnb'` as string matches — these won't fit the ENUM. |
| `guest_count` column missing | Used in `laporan.php` and `export-daily-report.php` but NOT defined in any CREATE TABLE schema. |

---

## TABLE 5: `booking_payments`

**Schema:** `database/frontdesk_new.sql` + columns added by `fix-hosting-cashbook.php`

| Column | Type | Default | Used In |
|--------|------|---------|---------|
| `id` | INT PK AUTO_INCREMENT | — | dashboard.php, api/add-booking-payment.php (lastInsertId) |
| `booking_id` | INT NOT NULL | — (FK→bookings.id) | index.php, reservasi.php, dashboard.php, invoice.php, in-house.php, api/get-booking-details.php, api/add-booking-payment.php (INSERT), api/delete-booking.php (DELETE) |
| `payment_date` | DATETIME NOT NULL | CURRENT_TIMESTAMP | index.php (WHERE), dashboard.php (SELECT), invoice.php, api/add-booking-payment.php (INSERT) |
| `amount` | DECIMAL(12,2) NOT NULL | — | index.php (SUM), reservasi.php (SUM), dashboard.php, invoice.php, in-house.php (SUM), api/get-booking-details.php (SUM), api/add-booking-payment.php (INSERT) |
| `payment_method` | ENUM('cash','card','transfer','qris','ota') | 'cash' | dashboard.php, invoice.php, api/add-booking-payment.php (INSERT) |
| `reference_number` | VARCHAR(100) | NULL | schema only |
| `notes` | TEXT | NULL | invoice.php |
| `processed_by` | INT | NULL (FK→users.id) | api/add-booking-payment.php (INSERT) |
| `synced_to_cashbook` | TINYINT(1) NOT NULL | 0 | dashboard.php (SELECT/UPDATE) |
| `cashbook_id` | INT(11) | NULL | dashboard.php (SELECT/UPDATE) |
| `created_at` | TIMESTAMP | CURRENT_TIMESTAMP | api/add-booking-payment.php (INSERT) |
| `created_by` | INT | — | api/add-booking-payment.php (INSERT — may be same as processed_by) |

### Schema Note
The base schema `payment_method` ENUM is `('cash','card','transfer','qris','ota')`. Various PHP files also reference `'bank_transfer'`, `'edc'`, `'other'` as payment method strings — these won't fit the base ENUM.

---

## TABLE 6: `breakfast_menus`

**Schema:** `database/breakfast_menu.sql` / `_missing_tables.sql`

| Column | Type | Default | Used In |
|--------|------|---------|---------|
| `id` | INT PK AUTO_INCREMENT | — | settings.php, breakfast.php, breakfast-old.php |
| `menu_name` | VARCHAR(100) NOT NULL | — | settings.php, breakfast.php, breakfast-old.php |
| `description` | TEXT | NULL | settings.php, breakfast.php |
| `category` | ENUM('western','indonesian','japanese','asian','drinks','beverages','extras') | — | settings.php, breakfast.php |
| `price` | DECIMAL(10,2) | 0.00 | settings.php, breakfast.php, breakfast-old.php |
| `is_free` | TINYINT(1) | 1 | settings.php, breakfast.php, breakfast-old.php |
| `is_available` | TINYINT(1) | 1 | settings.php, breakfast.php, breakfast-old.php |
| `image_url` | VARCHAR(255) | NULL | settings.php, breakfast.php |
| `created_at` | TIMESTAMP | CURRENT_TIMESTAMP | settings.php, breakfast.php |
| `updated_at` | TIMESTAMP | CURRENT_TIMESTAMP ON UPDATE | settings.php, breakfast.php |

### Schema Conflict
`breakfast_menu.sql` defines category as `ENUM('western','indonesian','asian','drinks','beverages','extras')` (no 'japanese').  
`_missing_tables.sql` / `adf_narayana_hotel_v2.sql` defines it as `ENUM('western','indonesian','japanese','asian','drinks','beverages','extras')` (includes 'japanese').

---

## TABLE 7: `breakfast_orders`

**Schema:** `_missing_tables.sql` / `adf_narayana_hotel_v2.sql`

| Column | Type | Default | Used In |
|--------|------|---------|---------|
| `id` | INT PK AUTO_INCREMENT | — | breakfast.php, breakfast-old.php |
| `booking_id` | INT | NULL | breakfast.php, breakfast-old.php, laporan.php, api/delete-booking.php (DELETE) |
| `guest_name` | VARCHAR(100) NOT NULL | — | breakfast.php (INSERT/SELECT) |
| `room_number` | VARCHAR(20) | NULL | breakfast.php (INSERT/SELECT) |
| `total_pax` | INT NOT NULL | — | breakfast.php (INSERT/SELECT) |
| `breakfast_time` | TIME NOT NULL | — | breakfast.php, laporan.php |
| `breakfast_date` | DATE NOT NULL | — | breakfast.php, laporan.php |
| `location` | ENUM('restaurant','room_service') | 'restaurant' | breakfast.php (INSERT/SELECT) |
| `menu_items` | TEXT (JSON) | NULL | breakfast.php, laporan.php |
| `special_requests` | TEXT | NULL | breakfast.php (INSERT/SELECT) |
| `total_price` | DECIMAL(10,2) | 0.00 | breakfast.php (INSERT/SELECT) |
| `order_status` | ENUM('pending','preparing','served','completed','cancelled') | 'pending' | breakfast.php (SELECT/UPDATE) |
| `created_by` | INT NOT NULL | — (FK→users.id) | breakfast.php (INSERT) |
| `created_at` | TIMESTAMP | CURRENT_TIMESTAMP | breakfast.php |
| `updated_at` | TIMESTAMP | CURRENT_TIMESTAMP ON UPDATE | breakfast.php |

---

## TABLE 8: `breakfast_log`

**Schema:** `adf_narayana_hotel_v2.sql` / `_missing_tables.sql`

| Column | Type | Default | Used In |
|--------|------|---------|---------|
| `id` | INT PK AUTO_INCREMENT | — | breakfast-old.php |
| `booking_id` | INT NOT NULL | — (FK→bookings.id) | breakfast-old.php |
| `guest_id` | INT NOT NULL | — (FK→guests.id) | breakfast-old.php |
| `menu_id` | INT | NULL (FK→breakfast_menus.id) | Added by `breakfast_menu.sql` ALTER |
| `quantity` | INT | 1 | Added by `breakfast_menu.sql` ALTER |
| `date` | DATE NOT NULL | — | breakfast-old.php |
| `status` | ENUM('taken','not_taken','skipped') | 'taken' | breakfast-old.php |
| `marked_by` | INT NOT NULL | — (FK→users.id) | breakfast-old.php |
| `marked_at` | TIMESTAMP | CURRENT_TIMESTAMP | — |
| `notes` | VARCHAR(255) | NULL | breakfast-old.php |

**Alternate schema** (fix-business-setup.php — older/simpler version):
`room_number VARCHAR(10)`, `guest_name VARCHAR(100)`, `breakfast_date DATE`, `pax INT`

---

## TABLE 9: `cash_book` *(referenced from frontdesk module)*

| Column | Type | Used In |
|--------|------|---------|
| `id` | INT PK | dashboard.php |
| `transaction_date` | DATE | index.php (WHERE), dashboard.php (INSERT), api/cancel-booking.php (INSERT) |
| `transaction_time` | TIME | dashboard.php (INSERT), api/cancel-booking.php (INSERT) |
| `division_id` | INT | dashboard.php (INSERT), api/cancel-booking.php (INSERT) |
| `category_id` | INT | dashboard.php (INSERT), api/cancel-booking.php (INSERT) |
| `description` | TEXT | dashboard.php (INSERT), api/cancel-booking.php (INSERT) |
| `transaction_type` | VARCHAR | index.php (WHERE), dashboard.php (INSERT), api/cancel-booking.php (INSERT) |
| `amount` | DECIMAL | index.php (SUM), dashboard.php (INSERT), api/cancel-booking.php (INSERT) |
| `payment_method` | VARCHAR | dashboard.php (INSERT), api/cancel-booking.php (INSERT) |
| `cash_account_id` | INT | index.php (WHERE), dashboard.php (INSERT), api/cancel-booking.php (INSERT) |
| `created_by` | INT | dashboard.php (INSERT), api/cancel-booking.php (INSERT) |
| `created_at` | TIMESTAMP | dashboard.php (INSERT), api/cancel-booking.php (INSERT) |

---

## TABLE 10: `cash_accounts` *(referenced from frontdesk module)*

| Column | Type | Used In |
|--------|------|---------|
| `id` | INT PK | index.php, dashboard.php, api/cancel-booking.php |
| `account_name` | VARCHAR | dashboard.php (SELECT), api/cancel-booking.php (SELECT) |
| `current_balance` | DECIMAL | dashboard.php (SELECT/UPDATE), api/cancel-booking.php (SELECT/UPDATE) |
| `business_id` | INT | index.php (WHERE), dashboard.php (WHERE) |
| `account_type` | VARCHAR | index.php (WHERE), dashboard.php (WHERE), api/cancel-booking.php (WHERE) |
| `is_active` | TINYINT | dashboard.php (WHERE) |
| `is_default_account` | TINYINT | dashboard.php (WHERE/fallback) |

---

## TABLE 11: `cash_account_transactions` *(referenced from frontdesk module)*

| Column | Type | Used In |
|--------|------|---------|
| `cash_account_id` | INT | dashboard.php (INSERT) |
| `transaction_id` | INT | dashboard.php (INSERT) |
| `transaction_date` | DATE | dashboard.php (INSERT) |
| `description` | VARCHAR/TEXT | dashboard.php (INSERT) |
| `amount` | DECIMAL | dashboard.php (INSERT) |
| `transaction_type` | VARCHAR | dashboard.php (INSERT) |
| `reference_number` | VARCHAR | dashboard.php (INSERT) |
| `created_by` | INT | dashboard.php (INSERT) |
| `created_at` | TIMESTAMP | dashboard.php (INSERT) |

---

## TABLE 12: `divisions` *(referenced from frontdesk module)*

| Column | Type | Used In |
|--------|------|---------|
| `id` | INT PK | dashboard.php (SELECT WHERE division_name), api/cancel-booking.php |
| `division_name` | VARCHAR | dashboard.php (WHERE = 'Front Desk'), api/cancel-booking.php |

---

## TABLE 13: `categories` *(referenced from frontdesk module)*

| Column | Type | Used In |
|--------|------|---------|
| `id` | INT PK | dashboard.php, api/cancel-booking.php |
| `category_name` | VARCHAR | dashboard.php (WHERE), api/cancel-booking.php (WHERE/INSERT) |
| `category_type` | VARCHAR | dashboard.php (WHERE), api/cancel-booking.php (WHERE/INSERT) |
| `branch_id` | INT | api/cancel-booking.php (INSERT) |
| `division_id` | INT | api/cancel-booking.php (INSERT) |
| `description` | TEXT | api/cancel-booking.php (INSERT) |
| `is_active` | TINYINT | api/cancel-booking.php (INSERT) |
| `created_at` | TIMESTAMP | api/cancel-booking.php (INSERT) |

---

## TABLE 14: `settings` *(referenced from frontdesk module)*

| Column | Type | Used In |
|--------|------|---------|
| `setting_key` | VARCHAR | calendar.php (WHERE), settings.php (SELECT/INSERT/UPDATE), invoice.php (WHERE) |
| `setting_value` | TEXT | calendar.php (SELECT), settings.php (SELECT/INSERT/UPDATE), invoice.php (SELECT) |
| `setting_type` | VARCHAR | settings.php (SELECT) |

---

## TABLE 15: `users` *(referenced from frontdesk module)*

| Column | Type | Used In |
|--------|------|---------|
| `id` | INT PK | dashboard.php (Auth), breakfast.php, api/cancel-booking.php (Auth) |
| `is_active` | TINYINT | breakfast.php (WHERE) |

---

## TABLE 16: `businesses` *(referenced from frontdesk module)*

| Column | Type | Used In |
|--------|------|---------|
| `id` | INT PK | invoice.php |
| `business_name` | VARCHAR | invoice.php |

---

## SUMMARY: ALL KNOWN SCHEMA CONFLICTS & MISSING COLUMNS

| # | Issue | Severity | Detail |
|---|-------|----------|--------|
| 1 | **`actual_check_in` vs `actual_checkin_time`** | HIGH | Base schema defines `actual_check_in`, migration adds `actual_checkin_time`. PHP code uses `actual_checkin_time`. If both exist, `actual_check_in` is dead. |
| 2 | **`actual_check_out` vs `actual_checkout_time`** | HIGH | Same as above. PHP uses `actual_checkout_time`. |
| 3 | **`guest_count` missing from schema** | MEDIUM | `laporan.php` L132, `export-daily-report.php` L67 reference `b.guest_count` — column does NOT exist in any CREATE TABLE. Queries silently return NULL. |
| 4 | **`booking_source` ENUM too narrow** | MEDIUM | Schema ENUM is `('walk_in','phone','online','ota')`. Code references `'agoda'`, `'booking'`, `'tiket'`, `'airbnb'` in dashboard.php sync logic. These values can't be stored. |
| 5 | **`payment_method` ENUM too narrow** | MEDIUM | booking_payments schema ENUM is `('cash','card','transfer','qris','ota')`. Code references `'bank_transfer'`, `'edc'`, `'other'`. |
| 6 | **`breakfast_menus.category` ENUM mismatch** | LOW | `breakfast_menu.sql` lacks `'japanese'`; `_missing_tables.sql` includes it. |
| 7 | **`synced_to_cashbook` / `cashbook_id` not in base schema** | LOW | Added via ALTER in fix-hosting-cashbook.php. Must be applied after initial setup. |
| 8 | **`checked_in_by` / `checked_out_by` not in base schema** | LOW | Added via migration-checkin-checkout.sql. Referenced in fix-hosting-cashbook.php but not in any frontdesk PHP file. |
| 9 | **`created_by` on booking_payments** | LOW | `api/add-booking-payment.php` inserts `created_by` but base schema only has `processed_by`. May be the same column with different name, or a missing column. |

---

## COLUMN USAGE HEATMAP (top columns by file count)

| Column | Table | Files Using It |
|--------|-------|---------------|
| `bookings.id` | bookings | 19 |
| `bookings.status` | bookings | 16 |
| `bookings.check_in_date` | bookings | 15 |
| `bookings.check_out_date` | bookings | 14 |
| `bookings.guest_id` | bookings | 13 |
| `bookings.room_id` | bookings | 12 |
| `guests.guest_name` | guests | 12 |
| `rooms.room_number` | rooms | 11 |
| `bookings.booking_code` | bookings | 11 |
| `bookings.final_price` | bookings | 10 |
| `bookings.room_price` | bookings | 10 |
| `bookings.payment_status` | bookings | 10 |
| `guests.phone` | guests | 10 |
| `rooms.room_type_id` | rooms | 10 |
| `bookings.paid_amount` | bookings | 8 |
| `room_types.base_price` | room_types | 8 |
| `booking_payments.amount` | booking_payments | 7 |
| `booking_payments.booking_id` | booking_payments | 7 |
| `room_types.type_name` | room_types | 7 |
