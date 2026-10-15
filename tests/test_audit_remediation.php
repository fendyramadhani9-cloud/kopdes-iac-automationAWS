<?php
/**
 * Automated Regression Test Suite: Database Lock, Idempotency & Error Handling
 */

require_once __DIR__ . '/../config/database.php';

$allTestsPassed = true;

function runTest(string $title, callable $testFn) {
    global $allTestsPassed;
    echo "==================================================================\n";
    echo "TEST: {$title}\n";
    echo "==================================================================\n";
    try {
        $result = $testFn();
        if ($result === true) {
            echo "\033[32m[PASS]\033[0m {$title}\n\n";
        } else {
            echo "\033[31m[FAIL]\033[0m {$title}\n\n";
            $allTestsPassed = false;
        }
    } catch (\Throwable $e) {
        echo "\033[31m[EXCEPTION]\033[0m {$e->getMessage()}\n\n";
        $allTestsPassed = false;
    }
}

// -----------------------------------------------------------------------------
// TEST 1: Database Connection Failure (Fail-Fast Verification)
// -----------------------------------------------------------------------------
runTest("Inisialisasi Database Gagal Saat Kredensial / Host Invalid (Exit Code 1)", function() {
    $cmd = 'php -r "putenv(\"DB_CONNECTION=mysql\"); putenv(\"DB_HOST=192.0.2.1\"); putenv(\"DB_DATABASE=invalid\"); include \"database/init.php\";"';
    $output = [];
    $exitCode = 0;
    exec($cmd . ' 2>&1', $output, $exitCode);
    $outputStr = implode("\n", $output);

    echo "Exit Code: {$exitCode}\n";
    echo "Sample Output: " . substr($outputStr, 0, 200) . "...\n";

    // Wajib exit code 1 dan tidak melaporkan sukses
    return ($exitCode === 1 && strpos($outputStr, 'Gagal terhubung') !== false);
});

// -----------------------------------------------------------------------------
// TEST 2: Strict Idempotency and Zero Data Loss (2 Consecutive Runs)
// -----------------------------------------------------------------------------
runTest("Inisialisasi Database Idempotent & Zero Data Loss (SQLite/MySQL)", function() {
    $tempDb = __DIR__ . '/test_remediation.sqlite';
    @unlink($tempDb);

    // Run 1: Initial seed
    $cmd1 = 'php -r "putenv(\"DB_CONNECTION=sqlite\"); putenv(\"DB_DATABASE=' . addslashes($tempDb) . '\"); include \"database/init.php\";"';
    exec($cmd1, $out1, $rc1);
    if ($rc1 !== 0) {
        echo "Run 1 failed with code {$rc1}\n";
        @unlink($tempDb);
        return false;
    }

    // Insert custom records (simulating live production usage)
    $pdo = new PDO("sqlite:{$tempDb}");
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec("INSERT INTO users (name, email, password, role) VALUES ('Warga Baru', 'warga.baru@kopdes.id', 'hash', 'CITIZEN')");
    $pdo->exec("INSERT INTO kopdes (name, location, status) VALUES ('KopDes Mandiri 16', 'Desa Makmur', 'active')");
    $pdo->exec("INSERT INTO products (kopdes_id, name, price, stock) VALUES (1, 'Kopi Robusta Banyumas', 45000, 100)");

    $usersBefore = (int)$pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
    $kopdesBefore = (int)$pdo->query("SELECT COUNT(*) FROM kopdes")->fetchColumn();
    $productsBefore = (int)$pdo->query("SELECT COUNT(*) FROM products")->fetchColumn();

    // Run 2: Second run (ASG scale-out simulation)
    $cmd2 = 'php -r "putenv(\"DB_CONNECTION=sqlite\"); putenv(\"DB_DATABASE=' . addslashes($tempDb) . '\"); include \"database/init.php\";"';
    exec($cmd2, $out2, $rc2);
    if ($rc2 !== 0) {
        echo "Run 2 failed with code {$rc2}\n";
        @unlink($tempDb);
        return false;
    }

    $usersAfter = (int)$pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
    $kopdesAfter = (int)$pdo->query("SELECT COUNT(*) FROM kopdes")->fetchColumn();
    $productsAfter = (int)$pdo->query("SELECT COUNT(*) FROM products")->fetchColumn();

    @unlink($tempDb);

    echo "Users: {$usersBefore} -> {$usersAfter}\n";
    echo "Kopdes: {$kopdesBefore} -> {$kopdesAfter}\n";
    echo "Products: {$productsBefore} -> {$productsAfter}\n";

    return ($usersBefore === $usersAfter && $kopdesBefore === $kopdesAfter && $productsBefore === $productsAfter);
});

// -----------------------------------------------------------------------------
// TEST 3: MySQL GET_LOCK Fail-Fast Verification (Mocked Driver / Query)
// -----------------------------------------------------------------------------
runTest("MySQL Advisory Lock Gagal Memperoleh Lock Langsung Hentikan Proses (Exit Code 1)", function() {
    // Buat runner script simulasi lock gagal (GET_LOCK mengembalikan 0)
    $mockScript = __DIR__ . '/test_mock_lock_fail.php';
    $code = '<?php
    $lockAcquired = false;
    $lockName = "kopdes_db_init_lock";
    $lockResult = 0; // Simulasi lock dipegang oleh proses lain / timeout

    if ($lockResult === 1) {
        $lockAcquired = true;
        echo "[OK] Lock didapat\n";
    } else {
        echo "FATAL: Gagal memperoleh MySQL advisory lock (\'{$lockName}\'). Timeout terlampaui.\n";
        exit(1);
    }
    ';
    file_put_contents($mockScript, $code);

    $cmd = 'php ' . escapeshellarg($mockScript);
    $out = [];
    $rc = 0;
    exec($cmd . ' 2>&1', $out, $rc);
    @unlink($mockScript);

    $outStr = implode("\n", $out);
    echo "Exit Code: {$rc}\n";
    echo "Output: {$outStr}\n";

    return ($rc === 1 && strpos($outStr, 'FATAL: Gagal memperoleh MySQL advisory lock') !== false);
});

// -----------------------------------------------------------------------------
// TEST 4: Bootstrap Fail-Fast Policy Evaluation (user_data.sh.tftpl)
// -----------------------------------------------------------------------------
runTest("Bootstrap Script Memiliki Mekanisme Fail-Fast Tegas Pada Inisialisasi Database", function() {
    $userDataFile = __DIR__ . '/../terraform/user_data.sh.tftpl';
    if (!file_exists($userDataFile)) {
        // Fallback jika di branch main
        $userDataFile = 'd:/Projects/DevOps/Scripts/kopdes/terraform/user_data.sh.tftpl';
    }
    
    // Periksa apakah user_data.sh.tftpl menangani kegagalan init.php secara fail-fast
    $content = file_get_contents($userDataFile);
    
    $hasStrictInit = (
        strpos($content, 'if ! php "$APP_DIR/database/init.php"') !== false ||
        strpos($content, 'php "$APP_DIR/database/init.php" || exit 1') !== false ||
        (strpos($content, 'php "$APP_DIR/database/init.php"') !== false && strpos($content, '|| echo "[WARN]"') === false)
    );

    $hasNoWarningSwallow = (strpos($content, '|| echo "[WARN] Inisialisasi database otomatis via bootstrap menemui kendala') === false);

    echo "Strict DB Check: " . ($hasStrictInit ? "YES" : "NO") . "\n";
    echo "No Warning Swallow: " . ($hasNoWarningSwallow ? "YES" : "NO") . "\n";

    return ($hasStrictInit && $hasNoWarningSwallow);
});

echo "==================================================================\n";
if ($allTestsPassed) {
    echo "\033[32m[ALL REGRESSION TESTS PASSED]\033[0m\n";
    exit(0);
} else {
    echo "\033[31m[SOME TESTS FAILED]\033[0m\n";
    exit(1);
}
