<?php
define('APP_ACCESS', true);
require_once 'config/config.php';
require_once 'config/database.php';
require_once 'includes/auth.php';

$auth = new Auth();
$auth->requireLogin();

$db = Database::getInstance();
$pdo = $db->getConnection();
$today = date('Y-m-d');

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html><head><title>Debug Breakfast Orders</title>
<style>
body{font-family:monospace;background:#1a1a2e;color:#e0e0e0;padding:20px;font-size:13px}
h2{color:#f59e0b;border-bottom:1px solid #333;padding-bottom:8px}
table{border-collapse:collapse;width:100%;margin-bottom:30px}
th,td{border:1px solid #333;padding:6px 10px;text-align:left;font-size:12px}
th{background:#2a2a4a;color:#f59e0b}
tr:nth-child(even){background:#1e1e3a}
.dup{background:#3a1a1a !important;color:#ff6b6b}
.ok{color:#10b981}
.warn{color:#f59e0b}
.err{color:#ef4444}
pre{background:#0d0d1a;padding:10px;border-radius:6px;overflow-x:auto;max-height:200px;font-size:11px}
.action-btn{padding:8px 16px;background:#ef4444;color:white;border:none;border-radius:6px;cursor:pointer;font-weight:bold;font-size:13px;margin:5px}
.action-btn.green{background:#10b981}
</style>
</head><body>
<h1>🔍 Debug Breakfast Orders — <?php echo $today; ?></h1>

<?php
// ===================== ACTION: Force cleanup =====================
if (isset($_GET['force_cleanup'])) {
    echo "<h2>⚡ FORCE CLEANUP RESULT</h2>";
    
    // Step 1: Get all orders for today
    $all = $pdo->prepare("SELECT id, guest_name, breakfast_date, breakfast_time, room_number, 
        LENGTH(menu_items) as mlen, created_at FROM breakfast_orders WHERE breakfast_date = ? ORDER BY id");
    $all->execute([$today]);
    $rows = $all->fetchAll(PDO::FETCH_ASSOC);
    
    // Group by guest+date+time (ignore room_number differences too)
    $groups = [];
    foreach ($rows as $r) {
        $key = $r['guest_name'] . '|' . $r['breakfast_time'];
        $groups[$key][] = $r;
    }
    
    $deleteIds = [];
    foreach ($groups as $key => $items) {
        if (count($items) > 1) {
            // Keep the last one (highest ID), delete rest
            $keepId = 0;
            foreach ($items as $item) {
                if ($item['id'] > $keepId) $keepId = $item['id'];
            }
            foreach ($items as $item) {
                if ($item['id'] != $keepId) {
                    $deleteIds[] = $item['id'];
                }
            }
            echo "<p class='warn'>Group '$key': " . count($items) . " orders. Keep ID=$keepId, Delete IDs=" . implode(',', array_column(array_filter($items, fn($i) => $i['id'] != $keepId), 'id')) . "</p>";
        }
    }
    
    if (count($deleteIds) > 0) {
        $placeholders = implode(',', array_fill(0, count($deleteIds), '?'));
        $del = $pdo->prepare("DELETE FROM breakfast_orders WHERE id IN ($placeholders)");
        $del->execute($deleteIds);
        echo "<p class='ok'>✅ Deleted " . count($deleteIds) . " duplicate orders (IDs: " . implode(', ', $deleteIds) . ")</p>";
    } else {
        echo "<p class='ok'>No duplicates found to delete.</p>";
    }
    
    echo "<p><a href='debug-breakfast.php' style='color:#6366f1'>← Back to debug</a></p>";
    echo "</body></html>";
    exit;
}

// ===================== 1. RAW DATA =====================
echo "<h2>1. Raw Data — All Orders Today ($today)</h2>";

$stmt = $pdo->prepare("SELECT id, booking_id, guest_name, room_number, total_pax, 
    breakfast_time, breakfast_date, location, menu_items, 
    total_price, order_status, submit_token, created_by, created_at
    FROM breakfast_orders WHERE breakfast_date = ? ORDER BY id");
$stmt->execute([$today]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "<p>Total orders today: <strong>" . count($rows) . "</strong></p>";

if (count($rows) > 0) {
    echo "<table><tr><th>ID</th><th>guest_name</th><th>room_number</th><th>time</th><th>pax</th><th>menu_items (first 80 chars)</th><th>price</th><th>status</th><th>submit_token</th><th>created_at</th></tr>";
    foreach ($rows as $r) {
        echo "<tr><td>{$r['id']}</td><td>" . htmlspecialchars($r['guest_name']) . "</td><td>" . htmlspecialchars($r['room_number']) . "</td>";
        echo "<td>{$r['breakfast_time']}</td><td>{$r['total_pax']}</td>";
        echo "<td><code>" . htmlspecialchars(substr($r['menu_items'], 0, 80)) . "</code></td>";
        echo "<td>{$r['total_price']}</td><td>{$r['order_status']}</td>";
        echo "<td>" . htmlspecialchars(substr($r['submit_token'] ?? 'NULL', 0, 20)) . "</td>";
        echo "<td>{$r['created_at']}</td></tr>";
    }
    echo "</table>";
}

// ===================== 2. DUPLICATE ANALYSIS =====================
echo "<h2>2. Duplicate Analysis</h2>";

// 2a. Group by guest+date+time+room (current cleanup logic)
echo "<h3>2a. Group by guest_name + time + room_number</h3>";
$dup1 = $pdo->prepare("
    SELECT guest_name, breakfast_time, room_number, COUNT(*) as cnt, 
           GROUP_CONCAT(id ORDER BY id) as all_ids, MAX(id) as keep_id
    FROM breakfast_orders WHERE breakfast_date = ?
    GROUP BY guest_name, breakfast_time, room_number
    HAVING COUNT(*) > 1
");
$dup1->execute([$today]);
$dups1 = $dup1->fetchAll(PDO::FETCH_ASSOC);
echo "<p>Duplicate groups: <strong>" . count($dups1) . "</strong></p>";
if (count($dups1) > 0) {
    echo "<table><tr><th>guest_name</th><th>time</th><th>room_number</th><th>count</th><th>IDs</th><th>Keep ID</th></tr>";
    foreach ($dups1 as $d) {
        echo "<tr class='dup'><td>" . htmlspecialchars($d['guest_name']) . "</td><td>{$d['breakfast_time']}</td><td>" . htmlspecialchars($d['room_number']) . "</td><td>{$d['cnt']}</td><td>{$d['all_ids']}</td><td>{$d['keep_id']}</td></tr>";
    }
    echo "</table>";
} else {
    echo "<p class='warn'>⚠️ No duplicates found with this grouping!</p>";
}

// 2b. Group by guest+date+time ONLY (more relaxed)
echo "<h3>2b. Group by guest_name + time ONLY (ignoring room_number)</h3>";
$dup2 = $pdo->prepare("
    SELECT guest_name, breakfast_time, COUNT(*) as cnt, 
           GROUP_CONCAT(id ORDER BY id) as all_ids, MAX(id) as keep_id,
           GROUP_CONCAT(room_number ORDER BY id SEPARATOR ' | ') as rooms
    FROM breakfast_orders WHERE breakfast_date = ?
    GROUP BY guest_name, breakfast_time
    HAVING COUNT(*) > 1
");
$dup2->execute([$today]);
$dups2 = $dup2->fetchAll(PDO::FETCH_ASSOC);
echo "<p>Duplicate groups: <strong>" . count($dups2) . "</strong></p>";
if (count($dups2) > 0) {
    echo "<table><tr><th>guest_name</th><th>time</th><th>count</th><th>IDs</th><th>Keep ID</th><th>room_numbers</th></tr>";
    foreach ($dups2 as $d) {
        echo "<tr class='dup'><td>" . htmlspecialchars($d['guest_name']) . "</td><td>{$d['breakfast_time']}</td><td>{$d['cnt']}</td><td>{$d['all_ids']}</td><td>{$d['keep_id']}</td><td>" . htmlspecialchars($d['rooms']) . "</td></tr>";
    }
    echo "</table>";
} else {
    echo "<p class='warn'>⚠️ No duplicates found even with relaxed grouping!</p>";
}

// ===================== 3. FIELD COMPARISON =====================
echo "<h2>3. Byte-Level Field Comparison (first 2 rows)</h2>";
if (count($rows) >= 2) {
    $a = $rows[0];
    $b = $rows[1];
    
    $fields = ['guest_name', 'room_number', 'breakfast_time', 'breakfast_date', 'menu_items'];
    echo "<table><tr><th>Field</th><th>ID {$a['id']} value</th><th>ID {$b['id']} value</th><th>Equal?</th><th>HEX comparison</th></tr>";
    foreach ($fields as $f) {
        $valA = $a[$f] ?? '';
        $valB = $b[$f] ?? '';
        $equal = ($valA === $valB) ? '<span class="ok">YES ✓</span>' : '<span class="err">NO ✗</span>';
        
        $hexNote = '';
        if ($valA !== $valB && $f !== 'menu_items') {
            $hexA = bin2hex($valA);
            $hexB = bin2hex($valB);
            $hexNote = "A=" . substr($hexA, 0, 40) . "<br>B=" . substr($hexB, 0, 40);
        } elseif ($valA !== $valB && $f === 'menu_items') {
            // Find first difference position
            $minLen = min(strlen($valA), strlen($valB));
            $diffPos = -1;
            for ($i = 0; $i < $minLen; $i++) {
                if ($valA[$i] !== $valB[$i]) { $diffPos = $i; break; }
            }
            if ($diffPos >= 0) {
                $hexNote = "First diff at pos $diffPos:<br>A[...]='" . htmlspecialchars(substr($valA, max(0,$diffPos-5), 20)) . "'<br>B[...]='" . htmlspecialchars(substr($valB, max(0,$diffPos-5), 20)) . "'";
            } else {
                $hexNote = "Lengths differ: A=" . strlen($valA) . " B=" . strlen($valB);
            }
        }
        
        $dispA = htmlspecialchars($f === 'menu_items' ? substr($valA, 0, 60) . '...' : $valA);
        $dispB = htmlspecialchars($f === 'menu_items' ? substr($valB, 0, 60) . '...' : $valB);
        echo "<tr><td><strong>$f</strong></td><td><code>$dispA</code></td><td><code>$dispB</code></td><td>$equal</td><td>$hexNote</td></tr>";
    }
    echo "</table>";
} else {
    echo "<p>Need at least 2 rows to compare.</p>";
}

// ===================== 4. TABLE SCHEMA =====================
echo "<h2>4. Table Schema — breakfast_orders</h2>";
try {
    $cols = $pdo->query("SHOW COLUMNS FROM breakfast_orders")->fetchAll(PDO::FETCH_ASSOC);
    echo "<table><tr><th>Field</th><th>Type</th><th>Null</th><th>Key</th><th>Default</th></tr>";
    foreach ($cols as $c) {
        $highlight = ($c['Field'] === 'submit_token') ? ' class="warn"' : '';
        echo "<tr$highlight><td>{$c['Field']}</td><td>{$c['Type']}</td><td>{$c['Null']}</td><td>{$c['Key']}</td><td>{$c['Default']}</td></tr>";
    }
    echo "</table>";
} catch (Exception $e) {
    echo "<p class='err'>Error: " . htmlspecialchars($e->getMessage()) . "</p>";
}

// ===================== 5. INDEXES =====================
echo "<h2>5. Indexes on breakfast_orders</h2>";
try {
    $idx = $pdo->query("SHOW INDEX FROM breakfast_orders")->fetchAll(PDO::FETCH_ASSOC);
    echo "<table><tr><th>Key_name</th><th>Column</th><th>Non_unique</th><th>Seq</th></tr>";
    foreach ($idx as $i) {
        echo "<tr><td>{$i['Key_name']}</td><td>{$i['Column_name']}</td><td>{$i['Non_unique']}</td><td>{$i['Seq_in_index']}</td></tr>";
    }
    echo "</table>";
} catch (Exception $e) {
    echo "<p class='err'>Error: " . htmlspecialchars($e->getMessage()) . "</p>";
}

// ===================== 6. ACTION BUTTONS =====================
echo "<h2>6. Actions</h2>";
echo "<p><a href='debug-breakfast.php?force_cleanup=1' class='action-btn' onclick=\"return confirm('Hapus semua duplikat hari ini? (berdasarkan guest+time, KEEP newest)')\">🧹 Force Cleanup Duplicates</a></p>";
echo "<p style='color:#999;font-size:11px;margin-top:20px'>⚠️ Hapus file ini setelah selesai debug: <code>debug-breakfast.php</code></p>";
?>
</body></html>
