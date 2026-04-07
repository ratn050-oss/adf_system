<?php
// Generate VAPID keys using openssl directly
$key = @openssl_pkey_new([
    'curve_name' => 'prime256v1',
    'private_key_type' => OPENSSL_KEYTYPE_EC,
]);

if (!$key) {
    // Try with config path for XAMPP
    $opensslConf = 'C:/xampp/apache/conf/openssl.cnf';
    if (file_exists($opensslConf)) {
        $key = openssl_pkey_new([
            'curve_name' => 'prime256v1',
            'private_key_type' => OPENSSL_KEYTYPE_EC,
            'config' => $opensslConf
        ]);
    }
}

if (!$key) {
    while ($e = openssl_error_string()) echo "OpenSSL Error: $e\n";
    echo "Failed to generate EC key. Trying openssl CLI...\n";
    
    // Use openssl CLI as fallback
    $tmpKey = tempnam(sys_get_temp_dir(), 'vapid');
    exec("openssl ecparam -genkey -name prime256v1 -noout -out " . escapeshellarg($tmpKey) . " 2>&1", $out, $ret);
    if ($ret === 0 && file_exists($tmpKey)) {
        $pem = file_get_contents($tmpKey);
        $key = openssl_pkey_get_private($pem);
        unlink($tmpKey);
    } else {
        @unlink($tmpKey);
        echo "All methods failed.\n";
        exit(1);
    }
}

$details = openssl_pkey_get_details($key);
$x = $details['ec']['x'];
$y = $details['ec']['y'];
$d = $details['ec']['d'];

function base64url_encode($data) {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

$publicKey = base64url_encode(chr(4) . $x . $y);
$privateKey = base64url_encode($d);

echo json_encode([
    'publicKey' => $publicKey,
    'privateKey' => $privateKey
], JSON_PRETTY_PRINT);
