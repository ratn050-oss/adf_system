<?php
/**
 * Quick script to fix directory permissions via cPanel cron
 */
$h = 'guangmao.iixcp.rumahweb.net';
$u = 'adfb2574';
$p = '@Nnoc2026';
$base = "https://{$h}:2083";

// Run chmod to fix permissions
$cronCmd = 'chmod 755 /home/adfb2574/public_html ; sleep 75 ; exit 0';

$action = $argv[1] ?? 'add';

if ($action === 'add') {
    echo "Adding cron job to fix directory permissions...\n";
    $url = $base . '/json-api/cpanel?cpanel_jsonapi_apiversion=2&cpanel_jsonapi_module=Cron&cpanel_jsonapi_func=add_line&command=' . urlencode($cronCmd) . '&day=*&hour=*&minute=*&month=*&weekday=*';
} elseif ($action === 'remove') {
    $linenum = $argv[2] ?? '';
    if (!$linenum) {
        echo "Usage: php fix-perms-cron.php remove <linekey>\n";
        exit(1);
    }
    echo "Removing cron job: $linenum\n";
    $url = $base . '/json-api/cpanel?cpanel_jsonapi_apiversion=2&cpanel_jsonapi_module=Cron&cpanel_jsonapi_func=remove_line&linekey=' . $linenum;
} elseif ($action === 'list') {
    echo "Listing cron jobs...\n";
    $url = $base . '/json-api/cpanel?cpanel_jsonapi_apiversion=2&cpanel_jsonapi_module=Cron&cpanel_jsonapi_func=listcron';
} else {
    echo "Usage: php fix-perms-cron.php [add|remove <linekey>|list]\n";
    exit(1);
}

$ctx = stream_context_create([
    'http' => [
        'header' => 'Authorization: Basic ' . base64_encode("{$u}:{$p}"),
        'timeout' => 15,
    ],
    'ssl' => [
        'verify_peer' => false,
        'verify_peer_name' => false,
    ]
]);

$result = @file_get_contents($url, false, $ctx);
if ($result === false) {
    echo "ERROR: Request failed\n";
    exit(1);
}

$data = json_decode($result, true);

// Check if successful
if (isset($data['cpanelresult']['data'][0]['status']) && $data['cpanelresult']['data'][0]['status'] == 1) {
    if ($action === 'add') {
        $linekey = $data['cpanelresult']['data'][0]['linekey'] ?? '';
        echo "✅ Cron job added successfully!\n";
        echo "   Linekey: $linekey\n";
        echo "   Command will run every minute\n";
        echo "\n   To remove after fix: php fix-perms-cron.php remove $linekey\n";
    } else {
        echo "✅ Operation successful!\n";
    }
} else {
    echo "Status: " . json_encode($data, JSON_PRETTY_PRINT) . "\n";
}
?>
