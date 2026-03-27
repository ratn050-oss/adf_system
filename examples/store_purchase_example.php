<?php
/**
 * Example: Store Purchase Invoice
 * This demonstrates how to use the storePurchase function
 */

require_once '../config/config.php';
require_once '../includes/auth.php';
require_once '../includes/procurement_functions.php';

echo "=== PURCHASE INVOICE EXAMPLE ===\n\n";

// Example 1: Simple Purchase Invoice (without PO)
echo "Example 1: Direct Purchase (No PO)\n";
echo str_repeat("-", 80) . "\n";

$invoice_number = 'INV-2026-001';
$supplier_id = 1; // PT Sumber Makmur
$invoice_date = '2026-01-22';

$items = [
    [
        'item_name' => 'Sabun Batangan 100gr',
        'item_description' => 'Sabun untuk kamar hotel',
        'unit_of_measure' => 'pcs',
        'quantity' => 100,
        'unit_price' => 5000,
        'division_id' => 1, // Hotel division
        'notes' => 'Stock untuk bulan Januari'
    ],
    [
        'item_name' => 'Shampoo Sachet',
        'quantity' => 200,
        'unit_price' => 2500,
        'division_id' => 1
    ],
    [
        'item_name' => 'Handuk Putih 50x100cm',
        'quantity' => 50,
        'unit_price' => 35000,
        'division_id' => 1
    ]
];

$options = [
    'received_date' => '2026-01-22',
    'due_date' => '2026-02-21', // Net 30
    'notes' => 'Pembelian rutin bulanan',
    'discount_amount' => 50000,
    'tax_amount' => 0
];

$result = storePurchase($invoice_number, $supplier_id, $invoice_date, $items, $options);

if ($result['success']) {
    echo "✓ SUCCESS!\n";
    echo "Invoice Number: {$result['invoice_number']}\n";
    echo "Purchase ID: {$result['purchase_id']}\n";
    echo "Total Amount: Rp " . number_format($result['total_amount'], 0, ',', '.') . "\n";
    echo "Grand Total: Rp " . number_format($result['grand_total'], 0, ',', '.') . "\n";
    echo "Items Count: {$result['items_count']}\n";
    echo "GL Posted: " . ($result['gl_posted'] ? 'YES' : 'NO') . "\n";
    echo "\nGeneral Ledger Entries:\n";
    foreach ($result['gl_entries'] as $entry) {
        $type = strtoupper($entry['type']);
        echo "  - GL ID {$entry['gl_id']}: {$type} Account {$entry['account']} = Rp " . number_format($entry['amount'], 0, ',', '.') . "\n";
    }
    echo "\n{$result['message']}\n\n";
    
    // Get the full purchase details
    $purchase = getPurchase($result['purchase_id']);
    echo "Purchase Details:\n";
    echo "Supplier: {$purchase['supplier_name']} ({$purchase['supplier_code']})\n";
    echo "Payment Status: {$purchase['payment_status']}\n";
    echo "Created by: {$purchase['created_by_name']}\n";
    echo "\nItems:\n";
    foreach ($purchase['items'] as $item) {
        echo "  {$item['line_number']}. {$item['item_name']}\n";
        echo "     Qty: {$item['quantity']} {$item['unit_of_measure']} × Rp " . number_format($item['unit_price'], 0, ',', '.') . " = Rp " . number_format($item['subtotal'], 0, ',', '.') . "\n";
        echo "     Division: {$item['division_name']} ({$item['division_code']})\n";
    }
    
    echo "\nGL Entries Posted:\n";
    if (isset($purchase['gl_entries'])) {
        foreach ($purchase['gl_entries'] as $gl) {
            echo "  - {$gl['account_code']} {$gl['account_name']}\n";
            if ($gl['debit'] > 0) {
                echo "    DEBIT: Rp " . number_format($gl['debit'], 0, ',', '.') . "\n";
            }
            if ($gl['credit'] > 0) {
                echo "    CREDIT: Rp " . number_format($gl['credit'], 0, ',', '.') . "\n";
            }
        }
    }
    
} else {
    echo "✗ FAILED!\n";
    echo "Error: {$result['message']}\n";
}

echo "\n" . str_repeat("=", 80) . "\n\n";

// Example 2: Purchase Invoice from PO
echo "Example 2: Purchase Invoice linked to PO\n";
echo str_repeat("-", 80) . "\n";

// First, create a PO
$po_result = createPurchaseOrder(2, '2026-01-20', [
    [
        'item_name' => 'Beras Premium 25kg',
        'quantity' => 10,
        'unit_price' => 350000,
        'division_id' => 2 // Restaurant
    ],
    [
        'item_name' => 'Minyak Goreng 2L',
        'quantity' => 20,
        'unit_price' => 45000,
        'division_id' => 2
    ]
], [
    'status' => 'approved',
    'expected_delivery_date' => '2026-01-22'
]);

if ($po_result['success']) {
    echo "PO Created: {$po_result['po_number']}\n";
    
    // Get PO details to link items
    $po = getPurchaseOrder($po_result['po_id']);
    
    // Now create purchase invoice linked to this PO
    $invoice_items = [];
    foreach ($po['items'] as $po_item) {
        $invoice_items[] = [
            'po_detail_id' => $po_item['id'],
            'item_name' => $po_item['item_name'],
            'quantity' => $po_item['quantity'],
            'unit_price' => $po_item['unit_price'],
            'division_id' => $po_item['division_id']
        ];
    }
    
    $purchase_result = storePurchase(
        'INV-2026-002',
        2, // CV Cahaya Jaya
        '2026-01-22',
        $invoice_items,
        [
            'po_id' => $po_result['po_id'],
            'due_date' => '2026-02-05', // Net 14
            'notes' => 'Receiving from PO ' . $po_result['po_number']
        ]
    );
    
    if ($purchase_result['success']) {
        echo "✓ Purchase Invoice Created: {$purchase_result['invoice_number']}\n";
        echo "Grand Total: Rp " . number_format($purchase_result['grand_total'], 0, ',', '.') . "\n";
        echo "GL Posted: YES\n";
        echo "\nGL Summary:\n";
        $total_debit = 0;
        $total_credit = 0;
        foreach ($purchase_result['gl_entries'] as $entry) {
            if ($entry['type'] === 'debit') {
                $total_debit += $entry['amount'];
                echo "  DEBIT  {$entry['account']}: Rp " . number_format($entry['amount'], 0, ',', '.') . "\n";
            } else {
                $total_credit += $entry['amount'];
                echo "  CREDIT {$entry['account']}: Rp " . number_format($entry['amount'], 0, ',', '.') . "\n";
            }
        }
        echo "  -----------------------------------------------\n";
        echo "  Total DEBIT : Rp " . number_format($total_debit, 0, ',', '.') . "\n";
        echo "  Total CREDIT: Rp " . number_format($total_credit, 0, ',', '.') . "\n";
        echo "  Balance     : Rp " . number_format($total_debit - $total_credit, 0, ',', '.') . " (should be 0)\n";
    }
}

echo "\n" . str_repeat("=", 80) . "\n\n";

// Example 3: Multi-Division Purchase
echo "Example 3: Multi-Division Purchase (Cost Center Tracking)\n";
echo str_repeat("-", 80) . "\n";

$multi_result = storePurchase(
    'INV-2026-003',
    3, // Toko Serba Ada
    '2026-01-22',
    [
        [
            'item_name' => 'Printer Paper A4',
            'quantity' => 10,
            'unit_price' => 45000,
            'division_id' => 1 // Hotel
        ],
        [
            'item_name' => 'Cleaning Supplies',
            'quantity' => 5,
            'unit_price' => 150000,
            'division_id' => 1 // Hotel
        ],
        [
            'item_name' => 'Kitchen Equipment',
            'quantity' => 2,
            'unit_price' => 500000,
            'division_id' => 2 // Restaurant
        ],
        [
            'item_name' => 'Coffee Beans 1kg',
            'quantity' => 20,
            'unit_price' => 120000,
            'division_id' => 2 // Restaurant
        ]
    ],
    [
        'notes' => 'Multi-division purchase'
    ]
);

if ($multi_result['success']) {
    echo "✓ Multi-Division Purchase Created: {$multi_result['invoice_number']}\n";
    echo "Total Items: {$multi_result['items_count']}\n";
    echo "Grand Total: Rp " . number_format($multi_result['grand_total'], 0, ',', '.') . "\n\n";
    echo "GL Entries by Division:\n";
    foreach ($multi_result['gl_entries'] as $entry) {
        if ($entry['type'] === 'debit') {
            echo "  Division {$entry['division_id']}: DEBIT Rp " . number_format($entry['amount'], 0, ',', '.') . "\n";
        }
    }
    echo "  Accounts Payable: CREDIT Rp " . number_format($multi_result['grand_total'], 0, ',', '.') . "\n";
}

echo "\n" . str_repeat("=", 80) . "\n\n";

// Example 4: List all purchases
echo "Example 4: List All Purchases\n";
echo str_repeat("-", 80) . "\n";

$all_purchases = getPurchases(['gl_posted' => 1], 10, 0);
echo "Total Purchases Posted to GL: " . count($all_purchases) . "\n\n";

foreach ($all_purchases as $p) {
    echo "• {$p['invoice_number']} | {$p['supplier_name']} | " . date('d M Y', strtotime($p['invoice_date'])) . "\n";
    echo "  Total: Rp " . number_format($p['grand_total'], 0, ',', '.') . " | {$p['payment_status']} | {$p['items_count']} items\n";
    if ($p['po_number']) {
        echo "  Linked to PO: {$p['po_number']}\n";
    }
    echo "\n";
}

echo "\n=== END OF EXAMPLES ===\n";
