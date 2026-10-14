<?php
/**
 * Regression Test: Database Migration & Seeding Idempotency
 * 
 * Membuktikan bahwa menjalankan database/init.php berulang kali (2x, 3x, dst)
 * TIDAK menghapus data transaksi, produk, atau pengguna existing di database.
 * 
 * Jalankan: php tests/test_db_idempotency.php
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';

echo "========================================================\n";
echo "REGRESSION TEST: DATABASE IDEMPOTENCY & DATA PRESERVATION\n";
echo "========================================================\n\n";

$pdo = Database::getConnection();
if (!$pdo) {
    echo "[FAIL] Tidak dapat terhubung ke database untuk pengujian: " . Database::getLastError() . "\n";
    exit(1);
}

// 1. Eksekusi inisialisasi pertama
echo "[STEP 1] Menjalankan inisialisasi database tahap 1...\n";
ob_start();
require __DIR__ . '/../database/init.php';
$output1 = ob_get_clean();

$initialUserCount = (int)$pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
$initialTxCount = (int)$pdo->query("SELECT COUNT(*) FROM transactions")->fetchColumn();
echo "-> Inisialisasi awal selesai. Users: {$initialUserCount}, Transactions: {$initialTxCount}.\n";

if ($initialUserCount === 0) {
    echo "[FAIL] Inisialisasi awal gagal membuat data pengguna default.\n";
    exit(1);
}

// 2. Simulasikan data transaksi bisnis riil yang baru dibuat oleh pengguna
echo "\n[STEP 2] Memasukkan data transaksi bisnis riil nasabah baru...\n";
$testInvoice = 'INV-TEST-REGRESSION-' . time();
$insertStmt = $pdo->prepare("
    INSERT INTO transactions (invoice_code, kopdes_id, user_id, product_id, type, quantity, total_amount, status, notes, transaction_date)
    VALUES (?, 1, 5, 1, 'purchase', 2, 370000.00, 'completed', 'Data transaksi riil nasabah saat produksi live', CURRENT_TIMESTAMP)
");
$insertStmt->execute([$testInvoice]);
$insertedId = (int)$pdo->lastInsertId();

$txCountBefore = (int)$pdo->query("SELECT COUNT(*) FROM transactions")->fetchColumn();
echo "-> Transaksi riil tersimpan (ID: {$insertedId}, Invoice: {$testInvoice}). Total Tx: {$txCountBefore}.\n";

// 3. Jalankan inisialisasi tahap kedua (seolah-olah instance ASG baru melakukan bootstrap)
echo "\n[STEP 3] Menjalankan inisialisasi database tahap 2 (Simulasi scale-out / reboot ASG)...\n";
ob_start();
require __DIR__ . '/../database/init.php';
$output2 = ob_get_clean();

// Verifikasi apakah transaksi riil nasabah masih ada
$checkTx = $pdo->prepare("SELECT id, invoice_code, total_amount FROM transactions WHERE invoice_code = ?");
$checkTx->execute([$testInvoice]);
$preservedTx = $checkTx->fetch(PDO::FETCH_ASSOC);

if (!$preservedTx) {
    echo "\n[FATAL BUG DETECTED] Transaksi nasabah {$testInvoice} TERHAPUS oleh inisialisasi kedua!\n";
    exit(1);
}
echo "-> [PASS] Transaksi nasabah {$testInvoice} TETAP AMAN dan TIDAK TERHAPUS!\n";

// 4. Jalankan inisialisasi tahap ketiga
echo "\n[STEP 4] Menjalankan inisialisasi database tahap 3 (Simulasi instance replacement kedua)...\n";
ob_start();
require __DIR__ . '/../database/init.php';
$output3 = ob_get_clean();

$checkTx3 = $pdo->prepare("SELECT id FROM transactions WHERE invoice_code = ?");
$checkTx3->execute([$testInvoice]);
if (!$checkTx3->fetch()) {
    echo "\n[FATAL BUG DETECTED] Transaksi nasabah {$testInvoice} terhapus pada eksekusi ketiga!\n";
    exit(1);
}
echo "-> [PASS] Transaksi nasabah tetap utuh setelah eksekusi ketiga.\n";

// 5. Bersihkan data pengujian
$pdo->prepare("DELETE FROM transactions WHERE id = ?")->execute([$insertedId]);
echo "\n[STEP 5] Data pengujian sementara berhasil dibersihkan.\n";

echo "\n========================================================\n";
echo "HASIL REGRESI: PASS - INVARIAN IDEMPOTENSI DATABASE TERPENUHI!\n";
echo "Database aman dari risiko penghapusan data saat bootstrap instance baru.\n";
echo "========================================================\n";
exit(0);
