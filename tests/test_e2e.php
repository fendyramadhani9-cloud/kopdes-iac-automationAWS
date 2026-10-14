<?php
/**
 * Automated Test Suite & Health Check untuk KopDes Merah Putih
 * Kompatibel dengan AWS Native (ALB + ASG EC2 + RDS MySQL) dan Local/CI Test.
 * 
 * Jalankan: php tests/test_e2e.php
 */

require_once __DIR__ . '/../config/database.php';

$cookieFile = __DIR__ . '/test_cookie.txt';
if (file_exists($cookieFile)) unlink($cookieFile);

$baseUrl = getenv('TEST_APP_URL') ?: 'http://127.0.0.1:8000';

$totalTests = 0;
$failedTests = 0;
$spawnedKopdesId = null;

function assert_test(string $name, bool $condition): void {
    global $totalTests, $failedTests;
    $totalTests++;
    if ($condition) {
        echo "  [PASS] {$name}\n";
    } else {
        $failedTests++;
        echo "  [FAIL] {$name}\n";
    }
}

function http_request($url, $method = 'GET', $data = [], $headers = []) {
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

    if (!empty($headers)) {
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    }

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return ['code' => $httpCode, 'body' => (string)$response];
}

echo "========================================================\n";
echo "KOPDES MERAH PUTIH - AUTOMATED END-TO-END TEST SUITE\n";
echo "Target Base URL: {$baseUrl}\n";
echo "========================================================\n\n";

// 1. Ambil Form Login & Ekstrak CSRF
echo "=== 1. Ambil Form Login & Ekstrak Token CSRF ===\n";
$res = http_request("{$baseUrl}/index.php?page=login");
assert_test("Halaman login merespon HTTP 200", $res['code'] === 200);

preg_match('/name="csrf_token" value="([^"]+)"/', $res['body'], $matches);
$csrfToken = $matches[1] ?? '';
assert_test("Token CSRF berhasil diekstrak", !empty($csrfToken));

// 2. Login sebagai HEAD_GOV
echo "\n=== 2. Autentikasi Pengguna HEAD_GOV ===\n";
$loginRes = http_request("{$baseUrl}/index.php?page=login", 'POST', [
    'csrf_token' => $csrfToken,
    'email' => 'head@gov.local',
    'password' => 'password123'
]);
assert_test("Login HEAD_GOV sukses dan memuat dashboard", strpos($loginRes['body'], 'Overview Administrator') !== false);

// 3. Verifikasi Elemen Dashboard
echo "\n=== 3. Verifikasi Metrik Dashboard Wilayah ===\n";
preg_match('/Total KopDes.*?class="stat-value">([0-9]+)/s', $loginRes['body'], $kopdesMatches);
$kopdesCount = (int)($kopdesMatches[1] ?? 0);
assert_test("Metrik Total KopDes tampil di UI ({$kopdesCount} unit)", $kopdesCount > 0);

$hasServerBadge = strpos($loginRes['body'], 'Server:') !== false;
assert_test("Indikator Server Node tampil di Topbar", $hasServerBadge);

// 4. Test Fitur + Spawn KopDes via API
echo "\n=== 4. Pengujian Fitur API Spawn KopDes ===\n";
preg_match('/name="csrf_token" value="([^"]+)"/', $loginRes['body'], $dashMatches);
$dashCsrf = $dashMatches[1] ?? $csrfToken;

$spawnRes = http_request("{$baseUrl}/index.php?page=api-spawn-kopdes", 'POST', [
    'csrf_token' => $dashCsrf,
    'name' => 'KopDes Uji Otomatis ' . rand(100, 999),
    'location' => 'Desa Karanganyar',
    'description' => 'Unit uji otomatis sementara untuk verifikasi API spawn.'
], ['X-Requested-With: XMLHttpRequest']);

$json = json_decode($spawnRes['body'], true);
$spawnSuccess = !empty($json['success']) && !empty($json['data']['id']);
assert_test("KopDes baru berhasil di-spawn via API (HTTP 201)", $spawnRes['code'] === 201 && $spawnSuccess);
if ($spawnSuccess) {
    $spawnedKopdesId = (int)$json['data']['id'];
}

// 5. Verifikasi Halaman Infrastructure
echo "\n=== 5. Verifikasi Halaman Monitor Infrastruktur ===\n";
$infraRes = http_request("{$baseUrl}/index.php?page=infrastructure");
assert_test("Halaman infrastruktur merespon HTTP 200", $infraRes['code'] === 200);

$hasLb = strpos($infraRes['body'], 'Application Load Balancer') !== false || strpos($infraRes['body'], 'HAProxy') !== false;
assert_test("Load Balancer terdeteksi (AWS ALB / HAProxy)", $hasLb);

$hasDbConnected = strpos($infraRes['body'], 'CONNECTED') !== false;
assert_test("Database status CONNECTED", $hasDbConnected);

// 6. Test Login CITIZEN
echo "\n=== 6. Pengujian Sesi & Dashboard CITIZEN (Warga) ===\n";
unlink($cookieFile);
$res2 = http_request("{$baseUrl}/index.php?page=login");
preg_match('/name="csrf_token" value="([^"]+)"/', $res2['body'], $matches2);
$csrf2 = $matches2[1] ?? '';

$citRes = http_request("{$baseUrl}/index.php?page=login", 'POST', [
    'csrf_token' => $csrf2,
    'email' => 'citizen@gov.local',
    'password' => 'password123'
]);
assert_test("Login Warga sukses (Halo, Siti Aminah)", strpos($citRes['body'], 'Halo, Siti Aminah') !== false);
assert_test("Katalog produk KopDes tersedia bagi warga", strpos($citRes['body'], 'Koperasi Desa yang Tersedia') !== false);

// 7. Test Login MANAGER
echo "\n=== 7. Pengujian Sesi & Dashboard MANAGER (Pengelola) ===\n";
unlink($cookieFile);
$res3 = http_request("{$baseUrl}/index.php?page=login");
preg_match('/name="csrf_token" value="([^"]+)"/', $res3['body'], $matches3);
$csrf3 = $matches3[1] ?? '';

$mgrRes = http_request("{$baseUrl}/index.php?page=login", 'POST', [
    'csrf_token' => $csrf3,
    'email' => 'manager@gov.local',
    'password' => 'password123'
]);
assert_test("Login Manager sukses dan unit terasosiasi", strpos($mgrRes['body'], 'KopDes Berkah Tani') !== false);

// 8. Pembersihan Data Uji & File Cookie
echo "\n=== 8. Cleanup Data Uji & Sanitasi Lingkungan ===\n";
if (file_exists($cookieFile)) unlink($cookieFile);

if ($spawnedKopdesId !== null) {
    $pdo = Database::getConnection();
    if ($pdo) {
        $delStmt = $pdo->prepare("DELETE FROM kopdes WHERE id = ?");
        $delStmt->execute([$spawnedKopdesId]);
        echo "  [OK] Unit uji sementara (ID: {$spawnedKopdesId}) berhasil dibersihkan dari database.\n";
    }
}

echo "\n========================================================\n";
echo "HASIL AKHIR: Total: {$totalTests} | Lolos: " . ($totalTests - $failedTests) . " | Gagal: {$failedTests}\n";
if ($failedTests === 0) {
    echo "STATUS: SEMUA PENGUJIAN SELESAI DENGAN SUKSES! (PASS)\n";
    echo "========================================================\n";
    exit(0);
} else {
    echo "STATUS: TERDAPAT PENGUJIAN YANG GAGAL! (FAIL)\n";
    echo "========================================================\n";
    exit(1);
}
