<?php
/**
 * CQC Pay Termin Invoice
 * Process payment for termin invoice
 */
define('APP_ACCESS', true);
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';
require_once '../../includes/functions.php';

$auth = new Auth();
$auth->requireLogin();

require_once '../cqc-projects/db-helper.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index-cqc.php');
    exit;
}

try {
    $pdo = getCQCDatabaseConnection(); // CQC database (adf_cqc)
    
    $invoice_id = (int)$_POST['invoice_id'];
    $payment_method = $_POST['payment_method'] ?? 'transfer';
    $notes = trim($_POST['notes'] ?? '');
    $currentUser = $auth->getCurrentUser();
    
    // Get invoice info
    $stmt = $pdo->prepare("
        SELECT ti.*, p.project_code, p.project_name, p.client_name 
        FROM cqc_termin_invoices ti
        LEFT JOIN cqc_projects p ON ti.project_id = p.id
        WHERE ti.id = ?
    ");
    $stmt->execute([$invoice_id]);
    $invoice = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$invoice) {
        throw new Exception("Faktur tidak ditemukan.");
    }
    
    if ($invoice['payment_status'] === 'paid') {
        throw new Exception("Faktur sudah lunas.");
    }
    
    // Update invoice status
    $stmt = $pdo->prepare("
        UPDATE cqc_termin_invoices 
        SET payment_status = 'paid',
            paid_amount = total_amount,
            payment_date = CURDATE(),
            payment_method = :payment_method,
            payment_notes = :notes,
            updated_at = NOW()
        WHERE id = :id
    ");
    $stmt->execute([
        'payment_method' => $payment_method,
        'notes' => $notes,
        'id' => $invoice_id
    ]);
    
    // Record in CQC cashbook (same database as CQC projects)
    $description = "[CQC_PROJECT:{$invoice['project_id']}] [{$invoice['project_code']}] Pembayaran {$invoice['invoice_number']} - Termin {$invoice['termin_number']} ({$invoice['percentage']}%) - {$invoice['client_name']}";
    
    // Get or create CQC division and category in CQC database
    $stmtDiv = $pdo->query("SELECT id FROM divisions WHERE LOWER(division_name) LIKE '%cqc%' OR LOWER(division_code) = 'cqc' LIMIT 1");
    $cqcDivision = $stmtDiv->fetch(PDO::FETCH_ASSOC);
    if (!$cqcDivision) {
        $pdo->exec("INSERT INTO divisions (division_name, division_code, is_active) VALUES ('CQC Projects', 'CQC', 1)");
        $divisionId = $pdo->lastInsertId();
    } else {
        $divisionId = $cqcDivision['id'];
    }
    
    // Get or create income category
    $stmtCat = $pdo->query("SELECT id FROM categories WHERE category_type = 'income' LIMIT 1");
    $incomeCategory = $stmtCat->fetch(PDO::FETCH_ASSOC);
    if (!$incomeCategory) {
        $pdo->exec("INSERT INTO categories (category_name, category_type, division_id, is_active) VALUES ('Pembayaran Proyek', 'income', {$divisionId}, 1)");
        $categoryId = $pdo->lastInsertId();
    } else {
        $categoryId = $incomeCategory['id'];
    }
    
    // Insert to CQC cash_book (adf_cqc database)
    $stmtCashbook = $pdo->prepare("
        INSERT INTO cash_book 
        (transaction_date, transaction_time, division_id, category_id, transaction_type, amount, description, payment_method, source_type, is_editable, created_by)
        VALUES (CURDATE(), CURTIME(), ?, ?, 'income', ?, ?, ?, 'invoice_payment', 0, ?)
    ");
    $stmtCashbook->execute([
        $divisionId,
        $categoryId,
        $invoice['total_amount'],
        $description,
        $payment_method,
        $currentUser['id']
    ]);
    
    $_SESSION['success'] = "Pembayaran {$invoice['invoice_number']} berhasil dicatat. Total: Rp " . number_format($invoice['total_amount'], 0, ',', '.');
    header('Location: index-cqc.php');
    exit;
    
} catch (Exception $e) {
    $_SESSION['error'] = $e->getMessage();
    header('Location: index-cqc.php');
    exit;
}
