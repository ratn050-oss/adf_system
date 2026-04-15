<?php
$h = 'guangmao.iixcp.rumahweb.net';
$u = 'adfb2574';
$p = '@Nnoc2026';
$base = "https://{$h}:2083";
$cronCmd = 'cd /home/adfb2574/public_html && git fetch origin && git reset --hard origin/main > /home/adfb2574/git_deploy_log.txt 2>&1';

$action = $argv[1] ?? 'add';

if ($action === 'add') {
    $url = $base . '/json-api/cpanel?cpanel_jsonapi_apiversion=2&cpanel_jsonapi_module=Cron&cpanel_jsonapi_func=add_line&command=' . urlencode($cronCmd) . '&day=*&hour=*&minute=*&month=*&weekday=*';
} elseif ($action === 'list') {
    $url = $base . '/json-api/cpanel?cpanel_jsonapi_apiversion=2&cpanel_jsonapi_module=Cron&cpanel_jsonapi_func=listcron';
} elseif ($action === 'remove') {
    $linenum = $argv[2] ?? '';
    if (!$linenum) {
        echo "Usage: php _deploy_cron.php remove <linekey>\n";
        exit(1);
    }
    $url = $base . '/json-api/cpanel?cpanel_jsonapi_apiversion=2&cpanel_jsonapi_module=Cron&cpanel_jsonapi_func=remove_line&linekey=' . $linenum;
} elseif ($action === 'log') {
    $url = $base . '/json-api/cpanel?cpanel_jsonapi_apiversion=2&cpanel_jsonapi_module=Fileman&cpanel_jsonapi_func=viewfile&dir=%2Fhome%2Fadfb2574&file=git_deploy_log.txt';
} else {
    echo "Usage: php _deploy_cron.php [add|list|remove|log]\n";
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
echo json_encode($data, JSON_PRETTY_PRINT) . "\n";
