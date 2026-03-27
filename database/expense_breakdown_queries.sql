-- ============================================
-- EXPENSE BREAKDOWN BY DIVISION QUERIES
-- Narayana Hotel Management System
-- ============================================

-- Query 1: Basic Total Expenses by Division
-- Join purchases_detail with purchases_header
-- Group by division_id and sum subtotal
SELECT 
    d.id as division_id,
    d.division_code,
    d.division_name,
    COUNT(DISTINCT ph.id) as total_invoices,
    COUNT(pd.id) as total_items,
    SUM(pd.subtotal) as total_expenses
FROM divisions d
LEFT JOIN purchases_detail pd ON d.id = pd.division_id
LEFT JOIN purchases_header ph ON pd.purchase_header_id = ph.id
WHERE ph.id IS NOT NULL  -- Only include divisions with purchases
GROUP BY d.id, d.division_code, d.division_name
ORDER BY total_expenses DESC;

-- ============================================

-- Query 2: Expenses Breakdown with Date Range Filter
SELECT 
    d.id as division_id,
    d.division_code,
    d.division_name,
    COUNT(DISTINCT ph.id) as total_invoices,
    COUNT(pd.id) as total_items,
    SUM(pd.subtotal) as total_expenses,
    MIN(ph.invoice_date) as first_purchase_date,
    MAX(ph.invoice_date) as last_purchase_date
FROM divisions d
LEFT JOIN purchases_detail pd ON d.id = pd.division_id
LEFT JOIN purchases_header ph ON pd.purchase_header_id = ph.id
WHERE ph.invoice_date BETWEEN '2026-01-01' AND '2026-01-31'  -- January 2026
    AND ph.id IS NOT NULL
GROUP BY d.id, d.division_code, d.division_name
ORDER BY total_expenses DESC;

-- ============================================

-- Query 3: Expenses Breakdown with Payment Status
SELECT 
    d.id as division_id,
    d.division_code,
    d.division_name,
    ph.payment_status,
    COUNT(DISTINCT ph.id) as total_invoices,
    SUM(pd.subtotal) as total_expenses,
    SUM(CASE WHEN ph.payment_status = 'paid' THEN pd.subtotal ELSE 0 END) as paid_amount,
    SUM(CASE WHEN ph.payment_status = 'unpaid' THEN pd.subtotal ELSE 0 END) as unpaid_amount,
    SUM(CASE WHEN ph.payment_status = 'partial' THEN pd.subtotal ELSE 0 END) as partial_amount
FROM divisions d
LEFT JOIN purchases_detail pd ON d.id = pd.division_id
LEFT JOIN purchases_header ph ON pd.purchase_header_id = ph.id
WHERE ph.id IS NOT NULL
GROUP BY d.id, d.division_code, d.division_name, ph.payment_status
ORDER BY d.division_name, ph.payment_status;

-- ============================================

-- Query 4: Expenses Breakdown by Division and Supplier
SELECT 
    d.id as division_id,
    d.division_name,
    s.supplier_name,
    COUNT(DISTINCT ph.id) as total_invoices,
    COUNT(pd.id) as total_items,
    SUM(pd.subtotal) as total_expenses
FROM divisions d
LEFT JOIN purchases_detail pd ON d.id = pd.division_id
LEFT JOIN purchases_header ph ON pd.purchase_header_id = ph.id
LEFT JOIN suppliers s ON ph.supplier_id = s.id
WHERE ph.id IS NOT NULL
GROUP BY d.id, d.division_name, s.supplier_name
ORDER BY d.division_name, total_expenses DESC;

-- ============================================

-- Query 5: Monthly Expenses Breakdown by Division
SELECT 
    d.id as division_id,
    d.division_code,
    d.division_name,
    DATE_FORMAT(ph.invoice_date, '%Y-%m') as month_year,
    DATE_FORMAT(ph.invoice_date, '%M %Y') as month_name,
    COUNT(DISTINCT ph.id) as total_invoices,
    COUNT(pd.id) as total_items,
    SUM(pd.subtotal) as total_expenses,
    AVG(pd.subtotal) as avg_item_cost
FROM divisions d
LEFT JOIN purchases_detail pd ON d.id = pd.division_id
LEFT JOIN purchases_header ph ON pd.purchase_header_id = ph.id
WHERE ph.invoice_date >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
    AND ph.id IS NOT NULL
GROUP BY d.id, d.division_code, d.division_name, DATE_FORMAT(ph.invoice_date, '%Y-%m'), DATE_FORMAT(ph.invoice_date, '%M %Y')
ORDER BY month_year DESC, total_expenses DESC;

-- ============================================

-- Query 6: Expenses Breakdown with Item Category Analysis
SELECT 
    d.id as division_id,
    d.division_name,
    pd.item_name,
    COUNT(pd.id) as purchase_count,
    SUM(pd.quantity) as total_quantity,
    pd.unit_of_measure,
    AVG(pd.unit_price) as avg_unit_price,
    SUM(pd.subtotal) as total_spent
FROM divisions d
LEFT JOIN purchases_detail pd ON d.id = pd.division_id
LEFT JOIN purchases_header ph ON pd.purchase_header_id = ph.id
WHERE ph.id IS NOT NULL
GROUP BY d.id, d.division_name, pd.item_name, pd.unit_of_measure
HAVING total_spent > 0
ORDER BY d.division_name, total_spent DESC;

-- ============================================

-- Query 7: Division Performance Comparison (This Year vs Last Year)
SELECT 
    d.id as division_id,
    d.division_name,
    SUM(CASE 
        WHEN YEAR(ph.invoice_date) = YEAR(CURDATE()) 
        THEN pd.subtotal ELSE 0 
    END) as current_year_expenses,
    SUM(CASE 
        WHEN YEAR(ph.invoice_date) = YEAR(CURDATE()) - 1 
        THEN pd.subtotal ELSE 0 
    END) as last_year_expenses,
    SUM(CASE 
        WHEN YEAR(ph.invoice_date) = YEAR(CURDATE()) 
        THEN pd.subtotal ELSE 0 
    END) - SUM(CASE 
        WHEN YEAR(ph.invoice_date) = YEAR(CURDATE()) - 1 
        THEN pd.subtotal ELSE 0 
    END) as difference,
    ROUND(
        ((SUM(CASE WHEN YEAR(ph.invoice_date) = YEAR(CURDATE()) THEN pd.subtotal ELSE 0 END) - 
          SUM(CASE WHEN YEAR(ph.invoice_date) = YEAR(CURDATE()) - 1 THEN pd.subtotal ELSE 0 END)) / 
         NULLIF(SUM(CASE WHEN YEAR(ph.invoice_date) = YEAR(CURDATE()) - 1 THEN pd.subtotal ELSE 0 END), 0)) * 100, 
    2) as growth_percentage
FROM divisions d
LEFT JOIN purchases_detail pd ON d.id = pd.division_id
LEFT JOIN purchases_header ph ON pd.purchase_header_id = ph.id
WHERE ph.invoice_date >= DATE_SUB(CURDATE(), INTERVAL 2 YEAR)
GROUP BY d.id, d.division_name
ORDER BY current_year_expenses DESC;

-- ============================================

-- Query 8: Top 10 Most Expensive Items by Division
SELECT 
    d.division_name,
    pd.item_name,
    COUNT(pd.id) as times_purchased,
    SUM(pd.quantity) as total_quantity,
    MAX(pd.unit_price) as highest_unit_price,
    MIN(pd.unit_price) as lowest_unit_price,
    AVG(pd.unit_price) as avg_unit_price,
    SUM(pd.subtotal) as total_cost
FROM purchases_detail pd
LEFT JOIN divisions d ON pd.division_id = d.id
LEFT JOIN purchases_header ph ON pd.purchase_header_id = ph.id
WHERE ph.id IS NOT NULL
GROUP BY d.division_name, pd.item_name
ORDER BY total_cost DESC
LIMIT 10;

-- ============================================

-- Query 9: Division Expense Summary with Percentage
SELECT 
    d.id as division_id,
    d.division_code,
    d.division_name,
    COUNT(DISTINCT ph.id) as total_invoices,
    SUM(pd.subtotal) as total_expenses,
    ROUND(
        (SUM(pd.subtotal) / (SELECT SUM(subtotal) FROM purchases_detail)) * 100, 
    2) as percentage_of_total
FROM divisions d
LEFT JOIN purchases_detail pd ON d.id = pd.division_id
LEFT JOIN purchases_header ph ON pd.purchase_header_id = ph.id
WHERE ph.id IS NOT NULL
GROUP BY d.id, d.division_code, d.division_name
ORDER BY total_expenses DESC;

-- ============================================

-- Query 10: Detailed Expense Report (All fields)
SELECT 
    ph.invoice_number,
    ph.invoice_date,
    s.supplier_name,
    d.division_code,
    d.division_name,
    pd.line_number,
    pd.item_name,
    pd.item_description,
    pd.quantity,
    pd.unit_of_measure,
    pd.unit_price,
    pd.subtotal,
    ph.discount_amount,
    ph.tax_amount,
    ph.grand_total,
    ph.payment_status,
    ph.gl_posted,
    u.full_name as created_by
FROM purchases_detail pd
LEFT JOIN purchases_header ph ON pd.purchase_header_id = ph.id
LEFT JOIN divisions d ON pd.division_id = d.id
LEFT JOIN suppliers s ON ph.supplier_id = s.id
LEFT JOIN users u ON ph.created_by = u.user_id
WHERE ph.invoice_date BETWEEN '2026-01-01' AND '2026-12-31'
ORDER BY ph.invoice_date DESC, ph.invoice_number, pd.line_number;

-- ============================================
-- CREATE VIEW FOR EASY ACCESS
-- ============================================

-- View: Expenses by Division Summary
CREATE OR REPLACE VIEW v_expenses_by_division AS
SELECT 
    d.id as division_id,
    d.division_code,
    d.division_name,
    COUNT(DISTINCT ph.id) as total_invoices,
    COUNT(pd.id) as total_items,
    SUM(pd.quantity) as total_quantity_purchased,
    SUM(pd.subtotal) as total_expenses,
    AVG(pd.subtotal) as avg_item_cost,
    MIN(ph.invoice_date) as first_purchase,
    MAX(ph.invoice_date) as last_purchase
FROM divisions d
LEFT JOIN purchases_detail pd ON d.id = pd.division_id
LEFT JOIN purchases_header ph ON pd.purchase_header_id = ph.id
WHERE ph.id IS NOT NULL
GROUP BY d.id, d.division_code, d.division_name;

-- View: Monthly Expenses Trend by Division
CREATE OR REPLACE VIEW v_monthly_expenses_by_division AS
SELECT 
    d.id as division_id,
    d.division_code,
    d.division_name,
    YEAR(ph.invoice_date) as year,
    MONTH(ph.invoice_date) as month,
    DATE_FORMAT(ph.invoice_date, '%Y-%m') as month_year,
    DATE_FORMAT(ph.invoice_date, '%M %Y') as month_name,
    COUNT(DISTINCT ph.id) as total_invoices,
    COUNT(pd.id) as total_items,
    SUM(pd.subtotal) as total_expenses
FROM divisions d
LEFT JOIN purchases_detail pd ON d.id = pd.division_id
LEFT JOIN purchases_header ph ON pd.purchase_header_id = ph.id
WHERE ph.id IS NOT NULL
GROUP BY d.id, d.division_code, d.division_name, YEAR(ph.invoice_date), MONTH(ph.invoice_date), DATE_FORMAT(ph.invoice_date, '%Y-%m'), DATE_FORMAT(ph.invoice_date, '%M %Y');

-- ============================================
-- USAGE EXAMPLES
-- ============================================

/*
-- Example 1: Get total expenses for all divisions
SELECT * FROM v_expenses_by_division;

-- Example 2: Get expenses for specific division
SELECT * FROM v_expenses_by_division WHERE division_id = 1;

-- Example 3: Get monthly trend for last 6 months
SELECT * FROM v_monthly_expenses_by_division 
WHERE month_year >= DATE_FORMAT(DATE_SUB(CURDATE(), INTERVAL 6 MONTH), '%Y-%m')
ORDER BY month_year DESC;

-- Example 4: Compare divisions this month
SELECT 
    division_name,
    total_expenses,
    ROUND((total_expenses / SUM(total_expenses) OVER()) * 100, 2) as percentage
FROM v_expenses_by_division
ORDER BY total_expenses DESC;
*/
