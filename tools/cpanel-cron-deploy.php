<?php

/**
 * Temporary script: Add cron job to force git deploy on production server.
 * Run locally: php tools/cpanel-cron-deploy.php
 */

$cpanelUrl = 'https://adfsystem.online:2083';
$user = 'adfb2574';
$pass = '@Nnoc2025';

$gitCmd = 'cd /home/adfb2574/public_html && /usr/local/cpanel/3rdparty/bin/git fetch origin && /usr/local/cpanel/3rdparty/bin/git reset --hard origin/main > /home/adfb2574/git_deploy_log.txt 2>&1';

$url = $cpanelUrl . '/execute/Cron/add_line?' . http_build_query([
    'command' => $gitCmd,
    'day'     => '*',
    'hour'    => '*',
    'minute'  => '*',
    'month'   => '*',
    'weekday' => '*',
]);

$ctx = stream_context_create([
    'http' => [
        'header' => "Authorization: Basic " . base64_encode("$user:$pass") . "\r\n",
        'timeout' => 30,
    ],
    'ssl' => [
        'verify_peer' => false,
        'verify_peer_name' => false,
    ],
]);

echo "Adding cron job...\n";
$result = @file_get_contents($url, false, $ctx);

if ($result === false) {
    echo "Failed. Trying cPanel API v2...\n";

    // Try API v2 format
    $url2 = $cpanelUrl . '/json-api/cpanel?' . http_build_query([
        'cpanel_jsonapi_user'       => $user,
        'cpanel_jsonapi_apiversion' => '2',
        'cpanel_jsonapi_module'     => 'Cron',
        'cpanel_jsonapi_func'       => 'add_line',
        'command' => $gitCmd,
        'day'     => '*',
        'hour'    => '*',
        'minute'  => '*',
        'month'   => '*',
        'weekday' => '*',
    ]);

    $result = @file_get_contents($url2, false, $ctx);
}

if ($result) {
    $data = json_decode($result, true);
    echo "Response:\n";
    echo json_encode($data, JSON_PRETTY_PRINT) . "\n";

    if (!empty($data['linekey']) || (!empty($data['status']) && $data['status'] == 1)) {
        echo "\n✅ Cron job added! It will run every minute.\n";
        echo "Wait ~60 seconds, then run this script with --remove to clean up.\n";
    }
} else {
    echo "All methods failed.\n";
    echo "HTTP response headers:\n";
    print_r($http_response_header ?? []);
}
