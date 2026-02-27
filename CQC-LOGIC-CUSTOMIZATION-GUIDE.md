# Business Logic Customization System

## Overview

CQC (dan bisnis lain) dapat di-customize tidak hanya tampilannya, tetapi juga **logika bisnisnya** (behavior, fields, calculations, reports, dll).

## File Customization

### 1. Main Config File
**Location:** `config/businesses/cqc.php`

```php
<?php
return [
    'business_id' => 'cqc',
    'name' => 'CQC Enjiniring',
    'business_type' => 'contractor',  // ← Key untuk conditional logic
    'database' => 'adf_cqc',
    
    // Theme (Tampilan)
    'theme' => [
        'color_primary' => '#059669',
        'color_secondary' => '#065f46',
        'icon' => '🏗️'
    ],
    
    // Cashbook Fields (Logika Akuntansi)
    'cashbook_columns' => [
        'project_code' => [...],
        'cost_center' => [...],
        'work_order' => [...]
    ],
    
    // Dashboard Widgets (Logika Tampilan Data)
    'dashboard_widgets' => [
        'show_daily_sales' => true,
        'show_orders' => true,
        'show_revenue' => true,
        'show_pending' => true
    ],
    
    // Custom Fields (Logika Validasi & Data)
    'custom_fields' => [
        'equipment_type' => ['label' => '...'],
        'allocation_id' => ['label' => '...']
    ]
];
```

## Cara Menggunakan di Code

### 1. Helper Functions

File: `includes/business_logic_helper.php` menyediakan functions siap pakai:

```php
require_once 'includes/business_logic_helper.php';

// Get all business logic
$logic = getBusinessLogic(ACTIVE_BUSINESS_ID);

// Check business type
if (isBusinessType('contractor')) {
    // Apply contractor-specific logic
}

// Get field configs
$cashbookFields = getCashbookColumnsForBusiness();
$customFields = getCustomFieldsForBusiness();
$widgets = getDashboardWidgetsForBusiness();

// Check if widget enabled
if (isWidgetEnabled('daily_sales')) {
    // Show widget
}
```

### 2. In Dashboard (index.php)

```php
<?php
require_once 'includes/business_logic_helper.php';

$businessLogic = getBusinessLogic(ACTIVE_BUSINESS_ID);

// Different dashboard logic per type
if ($businessLogic['business_type'] === 'contractor') {
    // Load contractor-specific dashboard
    // Maybe show project_code instead of table_number
    // Different KPI metrics
    include 'templates/dashboard_contractor.php';
} else {
    // Default dashboard
    include 'templates/dashboard_default.php';
}
?>
```

### 3. In Cashbook Form

```php
<?php
require_once 'includes/business_logic_helper.php';

$cashbookFields = getCashbookColumnsForBusiness();

?>
<form>
    <!-- Standard fields -->
    <input name="amount" type="number">
    <input name="description" type="text">
    
    <!-- Dynamic business-specific fields -->
    <?php foreach ($cashbookFields as $fieldName => $fieldConfig): ?>
        <div>
            <label><?php echo $fieldConfig['label']; ?></label>
            <input name="<?php echo $fieldName; ?>" 
                   type="<?php echo $fieldConfig['type']; ?>"
                   <?php echo $fieldConfig['required'] ? 'required' : ''; ?>>
        </div>
    <?php endforeach; ?>
</form>
```

### 4. In Reports

```php
<?php
// For CQC: Include project metrics, cost center analysis
// For Ben's Cafe: Include table analysis, menu performance
// For Narayana: Include room occupancy, booking analysis

$businessType = $businessLogic['business_type'];

if ($businessType === 'contractor') {
    // Contractor-specific report columns/calculations
    $reportQuery = "
        SELECT project_code, cost_center, SUM(amount) as total
        FROM cashbook
        WHERE ...
        GROUP BY project_code, cost_center
    ";
} elseif ($businessType === 'restaurant') {
    // Restaurant-specific
    $reportQuery = "SELECT menu_category, COUNT(*) as orders FROM ...";
}
?>
```

### 5. In Database Queries

```php
<?php
// Add dynamic filtering based on business
if (isBusinessType('contractor')) {
    // Only CQC - add project filtering
    $where .= " AND project_code = ?";
    $params[] = $_GET['project'] ?? null;
}
?>
```

## Configuration Customizer Tool

**Access:** `http://localhost/adf_system/cqc-logic-customizer.php`

Melalui tool ini, Anda bisa:

1. **Set Business Type**
   - contractor, construction, service, hotel, restaurant, etc.
   - Affects conditional logic dalam aplikasi

2. **Define Cashbook Columns**
   - Kolom yang muncul di form cashbook
   - Automatically yang dipake untuk input data CQC
   - Tidak muncul di bisnis lain

3. **Select Dashboard Widgets**
   - Pilih widget mana saja yang ditampilkan
   - Widget lain disembunyikan
   - Setiap bisnis bisa punya widget berbeda

4. **Custom Fields**
   - JSON-based field definitions
   - Validation rules
   - Field types, labels, options
   - Auto-added ke forms dan database

## Example: Complete Contractor Logic

### Setup via Tool:

```
Business Type: contractor
Cashbook Columns: 
  - project_code
  - cost_center
  - work_order
  - equipment_type
  - allocation_id

Dashboard Widgets:
  ✓ Daily Sales
  ✓ Orders/Projects
  ✓ Revenue
  ✓ Cash Flow
  ✓ Expenses
```

### In Code (modules/cashbook/index.php):

```php
<?php
require 'includes/business_logic_helper.php';

if (isBusinessType('contractor')) {
    // Contractor-specific form
    ?>
    <form>
        <input name="amount" type="number">
        <input name="project_code" type="text" placeholder="Project Code" required>
        <input name="work_order" type="text" placeholder="Work Order">
        <select name="equipment_type">
            <option>Excavator</option>
            <option>Crane</option>
        </select>
    </form>
    <?php
} else {
    // Generic form
}
?>
```

## Benefits

✅ **Flexible** - Customize per bisnis tanpa touching database schema
✅ **Maintainable** - All config in one PHP file per business
✅ **Scalable** - Add new business type dalam hitungan menit
✅ **Dynamic** - Change config anytime, no code needed
✅ **Type-Safe** - PHP config, IDE bisa auto-complete
✅ **User-Friendly** - Easy web interface untuk non-developers

## Next Steps

1. **Open:** `cqc-logic-customizer.php`
2. **Set** business type dan fields sesuai kebutuhan
3. **In your code**, gunakan helper functions dari `business_logic_helper.php`
4. **Apply** conditional logic based on business type atau specific fields
5. **Test** - setiap perubahan akan reflect otomatis

## Quick Reference

```php
// At top of file
require_once 'includes/business_logic_helper.php';

// Check business type
if (isBusinessType('contractor')) { ... }

// Get fields/settings
$cashbookFields = getCashbookColumnsForBusiness();
$customFields = getCustomFieldsForBusiness();
$widgets = getDashboardWidgetsForBusiness();

// Check specific widget
if (isWidgetEnabled('daily_sales')) { ... }

// Get full config
$logic = getBusinessLogic();
echo $logic['business_type'];
echo $logic['theme']['color_primary'];
```

---

**Questions?** Check `cqc-logic-customizer.php` for visual examples!
