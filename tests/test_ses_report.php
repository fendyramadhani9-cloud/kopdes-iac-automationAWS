<?php
/**
 * Test Suite: Amazon SNS Economic Report Notification Integration
 * Menguji alur publikasi notifikasi laporan rekapitulasi ekonomi via Amazon SNS
 * serta mekanisme mode simulasi lokal.
 * 
 * Jalankan: php tests/test_ses_report.php
 */

$baseUrl = getenv('TEST_APP_URL') ?: 'http://127.0.0.1:8000';
$cookieFile = __DIR__ . '/ses_test_cookie.txt';
if (file_exists($cookieFile)) unlink($cookieFile);

$totalTests = 0;
$failedTests = 0;

function assert_report_test(string $name, bool $condition): void {
    global $totalTests, $failedTests;
    $totalTests++;
    if ($condition) {
        echo "  [PASS] {$name}\n";
    } else {
        $failedTests++;
        echo "  [FAIL] {$name}\n";
    }
}

function http_req($url, $method = 'GET', $data = []) {
    global $cookieFile;
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieFile);
    curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);

    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
    }
    $res = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['code' => $code, 'body' => (string)$res];
}

echo "========================================================\n";
echo "TEST SUITE: AMAZON SNS REPORT NOTIFICATION INTEGRATION\n";
echo "Target Base URL: {$baseUrl}\n";
echo "========================================================\n\n";

// 1. Login sebagai HEAD_GOV
echo "=== 1. Login sebagai HEAD_GOV ===\n";
$loginPage = http_req("{$baseUrl}/index.php?page=login");
preg_match('/name="csrf_token" value="([^"]+)"/', $loginPage['body'], $m);
$csrf = $m[1] ?? '';

$loginRes = http_req("{$baseUrl}/index.php?page=login", 'POST', [
    'csrf_token' => $csrf,
    'email' => 'head@gov.local',
    'password' => 'password123'
]);
assert_report_test("Autentikasi HEAD_GOV berhasil (HTTP 200)", $loginRes['code'] === 200);

// 2. Akses Halaman Reports & Periksa Tombol Kirim Notifikasi (SNS)
echo "\n=== 2. Akses Halaman Laporan Ekonomi & Tombol SNS ===\n";
$reportPage = http_req("{$baseUrl}/index.php?page=reports");
$hasSnsBtn = strpos($reportPage['body'], 'Kirim via Email (SNS)') !== false || strpos($reportPage['body'], 'Kirim via Email') !== false;
assert_report_test("Tombol pemicu notifikasi SNS hadir di UI", $hasSnsBtn);

// 3. Trigger Pengiriman Rekapitulasi via Amazon SNS
echo "\n=== 3. Eksekusi Pengiriman Notifikasi via Amazon SNS ===\n";
preg_match('/name="csrf_token" value="([^"]+)"/', $reportPage['body'], $m2);
$reportCsrf = $m2[1] ?? '';

$postRes = http_req("{$baseUrl}/index.php?page=reports", 'POST', [
    'csrf_token' => $reportCsrf,
    'action' => 'send_email'
]);

assert_report_test("Pengiriman merespon HTTP 200/302 tanpa crash", in_array($postRes['code'], [200, 302]));

$hasValidNotice = strpos($postRes['body'], 'berhasil') !== false ||
                  strpos($postRes['body'], 'Mode Simulasi') !== false ||
                  strpos($postRes['body'], 'alert-success') !== false ||
                  strpos($postRes['body'], 'alert-info') !== false;
assert_report_test("Flash message notifikasi berhasil ditampilkan", $hasValidNotice);

$hasNoPhpErrors = strpos($postRes['body'], 'Fatal error') === false && strpos($postRes['body'], 'Parse error') === false;
assert_report_test("Bebas dari PHP Fatal/Parse Error", $hasNoPhpErrors);

// 4. Periksa File Log Pengiriman Email
echo "\n=== 4. Pemeriksaan Catatan Audit Log Email ===\n";
$logPath = __DIR__ . '/../logs/email.log';
$logExists = file_exists($logPath);
assert_report_test("File log notifikasi logs/email.log tercatat", $logExists);

if ($logExists) {
    $logContent = file_get_contents($logPath);
    echo "  [INFO] Cuplikan log terakhir:\n  " . trim(substr($logContent, -250)) . "\n";
}

// Cleanup
if (file_exists($cookieFile)) unlink($cookieFile);

echo "\n========================================================\n";
echo "HASIL AKHIR: Total: {$totalTests} | Lolos: " . ($totalTests - $failedTests) . " | Gagal: {$failedTests}\n";
if ($failedTests === 0) {
    echo "STATUS: PENGUJIAN NOTIFIKASI SNS SELESAI DENGAN SUKSES! (PASS)\n";
    echo "========================================================\n";
    exit(0);
} else {
    echo "STATUS: PENGUJIAN NOTIFIKASI SNS GAGAL! (FAIL)\n";
    echo "========================================================\n";
    exit(1);
}
