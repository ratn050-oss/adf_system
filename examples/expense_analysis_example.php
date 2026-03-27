<?php
/**
 * Example: Expense Analysis by Division
 * Demonstrates SQL queries joining purchases_detail and purchases_header
 */

require_once '../config/config.php';
require_once '../includes/auth.php';
require_once '../includes/expense_analysis_functions.php';

echo "=== EXPENSE BREAKDOWN BY DIVISION ===\n\n";

// Example 1: Basic Expenses by Division
echo "Example 1: Total Expenses by Division\n";
echo str_repeat("-", 80) . "\n";

$expenses = getExpensesByDivision();

echo sprintf("%-5s %-15s %-25s %15s %10s %10s\n", 
    "ID", "Code", "Division Name", "Total Expenses", "Invoices", "Items");
echo str_repeat("-", 80) . "\n";

foreach ($expenses as $expense) {
    echo sprintf("%-5d %-15s %-25s %15s %10d %10d\n",
        $expense['division_id'],
        $expense['division_code'],
        $expense['division_name'],
        'Rp ' . number_format($expense['total_expenses'], 0, ',', '.'),
        $expense['total_invoices'],
        $expense['total_items']
    );
    echo sprintf("%70s %s%%\n", '', $expense['percentage_of_total']);
}

$total = array_sum(array_column($expenses, 'total_expenses'));
echo str_repeat("-", 80) . "\n";
echo sprintf("%45s %15s\n", "GRAND TOTAL:", 'Rp ' . number_format($total, 0, ',', '.'));

echo "\n" . str_repeat("=", 80) . "\n\n";

// Example 2: Monthly Expenses Trend
echo "Example 2: Monthly Expenses by Division (Last 6 Months)\n";
echo str_repeat("-", 80) . "\n";

$monthly = getMonthlyExpensesByDivision(6);

// Group by month
$by_month = [];
foreach ($monthly as $row) {
    $month = $row['month_name'];
    if (!isset($by_month[$month])) {
        $by_month[$month] = [];
    }
    $by_month[$month][] = $row;
}

foreach ($by_month as $month => $divisions) {
    echo "\n{$month}:\n";
    foreach ($divisions as $div) {
        echo sprintf("  %-20s: Rp %15s (%d invoices, %d items)\n",
            $div['division_name'],
            number_format($div['total_expenses'], 0, ',', '.'),
            $div['total_invoices'],
            $div['total_items']
        );
    }
}

echo "\n" . str_repeat("=", 80) . "\n\n";

// Example 3: Expenses by Division and Supplier
echo "Example 3: Expenses Breakdown by Division and Supplier\n";
echo str_repeat("-", 80) . "\n";

$by_supplier = getExpensesByDivisionAndSupplier([
    'date_from' => date('Y-m-01'),
    'date_to' => date('Y-m-t')
]);

$current_division = '';
foreach ($by_supplier as $row) {
    if ($current_division !== $row['division_name']) {
        $current_division = $row['division_name'];
        echo "\n{$current_division}:\n";
    }
    echo sprintf("  - %-30s: Rp %15s (%d invoices)\n",
        $row['supplier_name'],
        number_format($row['total_expenses'], 0, ',', '.'),
        $row['total_invoices']
    );
}

echo "\n" . str_repeat("=", 80) . "\n\n";

// Example 4: Top Items by Division
echo "Example 4: Top 5 Items for Division Hotel (ID: 1)\n";
echo str_repeat("-", 80) . "\n";

$top_items = getTopItemsByDivision(1, 5, [
    'date_from' => date('Y-01-01'),
    'date_to' => date('Y-12-31')
]);

echo sprintf("%-30s %10s %15s %15s\n", 
    "Item Name", "Quantity", "Avg Price", "Total Spent");
echo str_repeat("-", 80) . "\n";

foreach ($top_items as $item) {
    echo sprintf("%-30s %10.2f %15s %15s\n",
        substr($item['item_name'], 0, 28),
        $item['total_quantity'],
        'Rp ' . number_format($item['avg_unit_price'], 0, ',', '.'),
        'Rp ' . number_format($item['total_spent'], 0, ',', '.')
    );
    echo sprintf("  (Purchased %d times, Price range: Rp %s - Rp %s)\n",
        $item['purchase_count'],
        number_format($item['lowest_unit_price'], 0, ',', '.'),
        number_format($item['highest_unit_price'], 0, ',', '.')
    );
}

echo "\n" . str_repeat("=", 80) . "\n\n";

// Example 5: Month-over-Month Comparison
echo "Example 5: Expense Comparison (This Month vs Last Month)\n";
echo str_repeat("-", 80) . "\n";

$comparison = getExpenseComparison('month');

echo sprintf("%-25s %18s %18s %18s %10s\n", 
    "Division", "Current Month", "Previous Month", "Difference", "Growth %");
echo str_repeat("-", 80) . "\n";

foreach ($comparison as $comp) {
    $diff_sign = $comp['difference'] >= 0 ? '+' : '';
    $growth_sign = $comp['growth_percentage'] >= 0 ? '+' : '';
    
    echo sprintf("%-25s %18s %18s %18s %9s%%\n",
        $comp['division_name'],
        'Rp ' . number_format($comp['current_period_expenses'], 0, ',', '.'),
        'Rp ' . number_format($comp['previous_period_expenses'], 0, ',', '.'),
        $diff_sign . 'Rp ' . number_format($comp['difference'], 0, ',', '.'),
        $growth_sign . $comp['growth_percentage']
    );
}

echo "\n" . str_repeat("=", 80) . "\n\n";

// Example 6: Detailed Expense Report for Specific Division
echo "Example 6: Detailed Expense Report - Hotel Division\n";
echo str_repeat("-", 80) . "\n";

$details = getDivisionExpenseDetails(1, [
    'date_from' => date('Y-m-01'),
    'date_to' => date('Y-m-t')
]);

echo "Total Records: " . count($details) . "\n\n";

if (count($details) > 0) {
    echo sprintf("%-15s %-12s %-25s %-30s %15s\n", 
        "Invoice", "Date", "Supplier", "Item", "Amount");
    echo str_repeat("-", 80) . "\n";
    
    $division_total = 0;
    foreach (array_slice($details, 0, 10) as $detail) { // Show first 10
        echo sprintf("%-15s %-12s %-25s %-30s %15s\n",
            substr($detail['invoice_number'], 0, 13),
            date('d M Y', strtotime($detail['invoice_date'])),
            substr($detail['supplier_name'], 0, 23),
            substr($detail['item_name'], 0, 28),
            'Rp ' . number_format($detail['subtotal'], 0, ',', '.')
        );
        $division_total += $detail['subtotal'];
    }
    
    if (count($details) > 10) {
        echo "... and " . (count($details) - 10) . " more items\n";
    }
    
    $total_all = array_sum(array_column($details, 'subtotal'));
    echo str_repeat("-", 80) . "\n";
    echo sprintf("%65s %15s\n", "TOTAL:", 'Rp ' . number_format($total_all, 0, ',', '.'));
}

echo "\n" . str_repeat("=", 80) . "\n\n";

// Example 7: Expenses with Date Filter
echo "Example 7: January 2026 Expenses by Division\n";
echo str_repeat("-", 80) . "\n";

$jan_expenses = getExpensesByDivision([
    'date_from' => '2026-01-01',
    'date_to' => '2026-01-31'
]);

foreach ($jan_expenses as $exp) {
    echo sprintf("%-30s: Rp %15s (%.1f%% of total)\n",
        $exp['division_name'],
        number_format($exp['total_expenses'], 0, ',', '.'),
        $exp['percentage_of_total']
    );
}

echo "\n=== END OF EXAMPLES ===\n";
