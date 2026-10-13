<?php
/**
 * Test Suite: Amazon SES Report Email Integration
 */

$baseUrl = 'http://127.0.0.1:8000';
$cookieFile = __DIR__ . '/ses_test_cookie.txt';
if (file_exists($cookieFile)) unlink($cookieFile);

function http_req($url, $method = 'GET', $data = []) {
    global $cookieFile;
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieFile);
    curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
    }
    $res = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['code' => $code, 'body' => $res];
}

echo "=== 1. Login sebagai HEAD_GOV ===\n";
$loginPage = http_req("{$baseUrl}/index.php?page=login");
preg_match('/name="csrf_token" value="([^"]+)"/', $loginPage['body'], $m);
$csrf = $m[1] ?? '';

$loginRes = http_req("{$baseUrl}/index.php?page=login", 'POST', [
    'csrf_token' => $csrf,
    'email' => 'head@gov.local',
    'password' => 'password123'
]);

echo "Login status code: {$loginRes['code']}\n";

echo "\n=== 2. Akses Halaman Reports & Periksa Tombol Kirim SES ===\n";
$reportPage = http_req("{$baseUrl}/index.php?page=reports");
$hasSesBtn = strpos($reportPage['body'], 'Kirim via Email (SES)') !== false;
echo "Tombol 'Kirim via Email (SES)' hadir di UI: " . ($hasSesBtn ? "YA [OK]" : "TIDAK [FAIL]") . "\n";

echo "\n=== 3. Trigger Pengiriman Rekapitulasi via Amazon SES ===\n";
preg_match('/name="csrf_token" value="([^"]+)"/', $reportPage['body'], $m2);
$reportCsrf = $m2[1] ?? '';

$postRes = http_req("{$baseUrl}/index.php?page=reports", 'POST', [
    'csrf_token' => $reportCsrf,
    'action' => 'send_email'
]);

echo "HTTP Code setelah kirim email: {$postRes['code']}\n";
$hasSuccessNotice = strpos($postRes['body'], 'berhasil dikirim') !== false || strpos($postRes['body'], 'alert-success') !== false;
$hasNoPhpErrors = strpos($postRes['body'], 'Fatal error') === false;

echo "Flash notice sukses tampil di UI: " . ($hasSuccessNotice ? "YA [OK]" : "TIDAK [FAIL]") . "\n";
echo "Bebas dari Fatal Error: " . ($hasNoPhpErrors ? "YA [OK]" : "TIDAK [FAIL]") . "\n";

echo "\n=== 4. Periksa File Log Pengiriman Email ===\n";
$logPath = __DIR__ . '/../logs/email.log';
if (file_exists($logPath)) {
    $logContent = file_get_contents($logPath);
    echo "Isi log email:\n" . trim(substr($logContent, -300)) . "\n";
    echo "File log tercatat: YA [OK]\n";
} else {
    echo "File log email: TIDAK DITEMUKAN [FAIL]\n";
}

if (file_exists($cookieFile)) unlink($cookieFile);
echo "\n=== TES INTEGRASI AMAZON SES SELESAI ===\n";
