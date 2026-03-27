<?php
/**
 * Example: Create Purchase Order
 * This demonstrates how to use the createPurchaseOrder function
 */

require_once '../config/config.php';
require_once '../includes/auth.php';
require_once '../includes/procurement_functions.php';

// Example 1: Basic Purchase Order
$supplier_id = 1; // PT Sumber Makmur
$po_date = '2026-01-22';

$items = [
    [
        'item_name' => 'Sabun Hotel 100gr',
        'item_description' => 'Sabun batang untuk kamar hotel',
        'unit_of_measure' => 'pcs',
        'quantity' => 100,
        'unit_price' => 5000,
        'division_id' => 1, // Hotel division
        'notes' => 'Request dari Housekeeping'
    ],
    [
        'item_name' => 'Handuk Putih 50x100cm',
        'quantity' => 50,
        'unit_price' => 35000,
        'division_id' => 1
    ],
    [
        'item_name' => 'Shampoo Sachet',
        'quantity' => 200,
        'unit_price' => 2500,
        'division_id' => 1
    ]
];

$options = [
    'expected_delivery_date' => '2026-01-25',
    'status' => 'draft',
    'notes' => 'Pesanan rutin bulanan untuk perlengkapan kamar',
    'discount_amount' => 50000,
    'tax_amount' => 0
];

$result = createPurchaseOrder($supplier_id, $po_date, $items, $options);

if ($result['success']) {
    echo "SUCCESS!\n";
    echo "PO Number: {$result['po_number']}\n";
    echo "PO ID: {$result['po_id']}\n";
    echo "Total Amount: Rp " . number_format($result['total_amount'], 0, ',', '.') . "\n";
    echo "Grand Total: Rp " . number_format($result['grand_total'], 0, ',', '.') . "\n";
    echo "Items Count: {$result['items_count']}\n";
    echo "Message: {$result['message']}\n\n";
    
    // Get the created PO
    $po = getPurchaseOrder($result['po_id']);
    
    echo "PO Details:\n";
    echo "Supplier: {$po['supplier_name']} ({$po['supplier_code']})\n";
    echo "Status: {$po['status']}\n";
    echo "Created by: {$po['created_by_name']}\n";
    echo "\nItems:\n";
    foreach ($po['items'] as $item) {
        echo "- {$item['item_name']} | Qty: {$item['quantity']} {$item['unit_of_measure']} | Price: Rp " . number_format($item['unit_price'], 0, ',', '.') . " | Subtotal: Rp " . number_format($item['subtotal'], 0, ',', '.') . " | Division: {$item['division_name']}\n";
    }
    
} else {
    echo "FAILED!\n";
    echo "Error: {$result['message']}\n";
}

echo "\n" . str_repeat("=", 80) . "\n\n";

// Example 2: Create and Submit PO
$result2 = createPurchaseOrder(2, '2026-01-22', [
    [
        'item_name' => 'Beras Premium 25kg',
        'quantity' => 10,
        'unit_price' => 350000,
        'division_id' => 2, // Restaurant division
    ],
    [
        'item_name' => 'Minyak Goreng 2L',
        'quantity' => 20,
        'unit_price' => 45000,
        'division_id' => 2
    ]
], [
    'status' => 'submitted',
    'expected_delivery_date' => '2026-01-24',
    'notes' => 'Urgent - Stock menipis'
]);

if ($result2['success']) {
    echo "Second PO created: {$result2['po_number']}\n";
    echo "Grand Total: Rp " . number_format($result2['grand_total'], 0, ',', '.') . "\n";
}

// Example 3: Get all POs
echo "\nAll Purchase Orders:\n";
$all_pos = getPurchaseOrders(['status' => 'draft'], 10, 0);
foreach ($all_pos as $po) {
    echo "- {$po['po_number']} | {$po['supplier_name']} | Rp " . number_format($po['grand_total'], 0, ',', '.') . " | {$po['status']} | {$po['items_count']} items\n";
}

// Example 4: Approve PO
if ($result['success']) {
    echo "\nApproving PO {$result['po_number']}...\n";
    $approve_result = updatePurchaseOrderStatus($result['po_id'], 'approved', 1);
    echo $approve_result['message'] . "\n";
}

// Example 5: Try to delete a non-draft PO (should fail)
if ($result['success']) {
    echo "\nAttempting to delete approved PO (should fail)...\n";
    $delete_result = deletePurchaseOrder($result['po_id']);
    echo $delete_result['message'] . "\n";
}
