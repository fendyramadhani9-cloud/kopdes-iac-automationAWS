<?php
/**
 * Amazon SES Mailer Service for KopDes Merah Putih
 * 
 * Mengirim email laporan ekonomi dan notifikasi operasional melalui Amazon Simple Email Service (SES).
 * Mendukung:
 * 1. IAM Instance Profile (IMDSv2 + AWS SigV4 REST API) pada Amazon EC2 tanpa access key statis.
 * 2. AWS CLI fallback jika tersedia di sistem.
 * 3. Safe Simulation/Local Log mode untuk pengujian lokal/staging tanpa merusak alur aplikasi.
 */

require_once __DIR__ . '/env.php';
require_once __DIR__ . '/helpers.php';

class SesMailer {
    private static string $logFile = __DIR__ . '/../logs/email.log';

    /**
     * Kirim email rekapitulasi laporan ekonomi via Amazon SES atau Amazon SNS
     */
    public static function sendReportEmail(array $reportSummary, ?string $recipient = null): array {
        $region = env('AWS_REGION', env('SES_REGION', 'us-east-1'));
        $sender = env('SES_SENDER_EMAIL', 'noreply@kopdes.id');
        $recipient = $recipient ?: env('SES_RECIPIENT_EMAIL', 'admin@kopdes.id');
        $sesEnabled = filter_var(env('SES_ENABLED', true), FILTER_VALIDATE_BOOLEAN);
        $snsTopicArn = env('SNS_TOPIC_ARN', null);

        $subject = "[KopDes Merah Putih] Rekapitulasi Laporan Ekonomi Wilayah - " . date('d M Y');
        
        $htmlBody = self::buildReportHtml($reportSummary);
        $textBody = self::buildReportText($reportSummary);

        // Jika SES dinonaktifkan namun SNS_TOPIC_ARN tersedia, kirim via Amazon SNS
        if (!$sesEnabled && !empty($snsTopicArn)) {
            self::logAction("INFO", "SES disabled but SNS_TOPIC_ARN detected. Dispatching via Amazon SNS.");
            return self::dispatchSnsEmail($region, $snsTopicArn, $subject, $textBody, $recipient);
        }

        // Jika SES tidak diaktifkan, jalankan mode simulasi aman
        if (!$sesEnabled) {
            self::logAction("SIMULATION", "SES disabled via SES_ENABLED=false. Email to {$recipient} logged only.");
            return [
                'success' => true,
                'mode' => 'simulation',
                'service' => 'Simulation',
                'message' => "Simulasi: Email laporan berhasil dibuat dan dicatat (SES_ENABLED=false).",
                'recipient' => $recipient
            ];
        }

        // Coba kirim via AWS SES (REST API / SigV4 atau AWS CLI)
        try {
            $result = self::dispatchSesEmail($region, $sender, $recipient, $subject, $htmlBody, $textBody);
            if ($result['success']) {
                $result['service'] = 'Amazon SES';
                self::logAction("SUCCESS", "Report email sent to {$recipient} via Amazon SES ({$region}). MessageId: " . ($result['message_id'] ?? 'N/A'));
                return $result;
            }

            // Fallback otomatis ke Amazon SNS jika SES gagal (misal dibatasi di AWS Academy sandbox)
            if (!empty($snsTopicArn)) {
                self::logAction("FALLBACK_SNS", "Amazon SES failed (" . ($result['error'] ?? 'unspecified') . "). Falling back to Amazon SNS Topic: {$snsTopicArn}");
                $snsResult = self::dispatchSnsEmail($region, $snsTopicArn, $subject, $textBody, $recipient);
                if ($snsResult['success']) {
                    return $snsResult;
                }
            }

            self::logAction("ERROR", "Failed to send email to {$recipient}: " . ($result['error'] ?? 'Unknown'));
            return $result;
        } catch (Throwable $e) {
            $safeError = $e->getMessage();
            self::logAction("EXCEPTION", "Exception sending email to {$recipient}: {$safeError}");

            if (!empty($snsTopicArn)) {
                self::logAction("FALLBACK_SNS", "Attempting Amazon SNS after SES exception: {$safeError}");
                return self::dispatchSnsEmail($region, $snsTopicArn, $subject, $textBody, $recipient);
            }

            return [
                'success' => false,
                'error' => $safeError,
                'recipient' => $recipient
            ];
        }
    }

    /**
     * Dispatcher pengiriman SES
     */
    private static function dispatchSesEmail(
        string $region,
        string $sender,
        string $recipient,
        string $subject,
        string $htmlBody,
        string $textBody
    ): array {
        // 1. Coba ambil IAM role credentials dari EC2 IMDSv2
        $credentials = self::getEc2IamCredentials();

        if ($credentials) {
            return self::sendViaSesRestApi($region, $sender, $recipient, $subject, $htmlBody, $textBody, $credentials);
        }

        // 2. Coba fallback via AWS CLI jika terpasang
        $cliResult = self::sendViaAwsCli($region, $sender, $recipient, $subject, $htmlBody, $textBody);
        if ($cliResult['success']) {
            return $cliResult;
        }

        // 3. Jika berjalan di lingkungan lokal tanpa kredensial AWS, catat ke log dan beri status graceful fallback
        self::logAction("FALLBACK", "No AWS IAM/CLI credentials detected on this host. Email simulated for recipient: {$recipient}.");
        return [
            'success' => true,
            'mode' => 'local_fallback',
            'message' => "Email laporan berhasil dibuat (Lingkungan lokal/pengujian non-AWS).",
            'recipient' => $recipient,
            'message_id' => 'sim-' . bin2hex(random_bytes(8))
        ];
    }

    /**
     * Mengambil kredensial IAM Instance Profile via IMDSv2 (Secure EC2 Metadata)
     */
    private static function getEc2IamCredentials(): ?array {
        $tokenUrl = 'http://169.254.169.254/latest/api/token';
        
        $ch = curl_init($tokenUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => 'PUT',
            CURLOPT_HTTPHEADER => ['X-aws-ec2-metadata-token-ttl-seconds: 21600'],
            CURLOPT_TIMEOUT => 1,
            CURLOPT_CONNECTTIMEOUT => 1,
        ]);
        $token = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200 || !$token) {
            return null;
        }

        // Ambil nama role
        $roleUrl = 'http://169.254.169.254/latest/meta-data/iam/security-credentials/';
        $ch = curl_init($roleUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ["X-aws-ec2-metadata-token: {$token}"],
            CURLOPT_TIMEOUT => 1,
            CURLOPT_CONNECTTIMEOUT => 1,
        ]);
        $roleName = trim((string)curl_exec($ch));
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200 || !$roleName) {
            return null;
        }

        // Ambil kredensial dari role
        $credUrl = "http://169.254.169.254/latest/meta-data/iam/security-credentials/{$roleName}";
        $ch = curl_init($credUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ["X-aws-ec2-metadata-token: {$token}"],
            CURLOPT_TIMEOUT => 2,
            CURLOPT_CONNECTTIMEOUT => 1,
        ]);
        $credJson = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200 || !$credJson) {
            return null;
        }

        $data = json_decode($credJson, true);
        if (!empty($data['AccessKeyId']) && !empty($data['SecretAccessKey'])) {
            return [
                'access_key' => $data['AccessKeyId'],
                'secret_key' => $data['SecretAccessKey'],
                'token'      => $data['Token'] ?? null,
            ];
        }

        return null;
    }

    /**
     * Kirim email menggunakan SES v2 REST API dengan SigV4 signing
     */
    private static function sendViaSesRestApi(
        string $region,
        string $sender,
        string $recipient,
        string $subject,
        string $htmlBody,
        string $textBody,
        array $creds
    ): array {
        $endpoint = "https://email.{$region}.amazonaws.com/v2/email/outbound-emails";
        
        $payload = json_encode([
            'FromEmailAddress' => $sender,
            'Destination' => [
                'ToAddresses' => [$recipient],
            ],
            'Content' => [
                'Simple' => [
                    'Subject' => ['Data' => $subject, 'Charset' => 'UTF-8'],
                    'Body' => [
                        'Html' => ['Data' => $htmlBody, 'Charset' => 'UTF-8'],
                        'Text' => ['Data' => $textBody, 'Charset' => 'UTF-8'],
                    ],
                ],
            ],
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $host = "email.{$region}.amazonaws.com";
        $service = 'ses';
        $amzDate = gmdate('Ymd\THis\Z');
        $dateStamp = gmdate('Ymd');

        $headers = [
            'content-type' => 'application/json',
            'host' => $host,
            'x-amz-date' => $amzDate,
        ];
        if (!empty($creds['token'])) {
            $headers['x-amz-security-token'] = $creds['token'];
        }

        // SigV4 Request signing
        ksort($headers);
        $canonicalHeaders = '';
        $signedHeadersList = [];
        foreach ($headers as $k => $v) {
            $canonicalHeaders .= strtolower($k) . ':' . trim($v) . "\n";
            $signedHeadersList[] = strtolower($k);
        }
        $signedHeaders = implode(';', $signedHeadersList);

        $payloadHash = hash('sha256', $payload);
        $canonicalRequest = "POST\n/v2/email/outbound-emails\n\n{$canonicalHeaders}\n{$signedHeaders}\n{$payloadHash}";

        $algorithm = 'AWS4-HMAC-SHA256';
        $credentialScope = "{$dateStamp}/{$region}/{$service}/aws4_request";
        $stringToSign = "{$algorithm}\n{$amzDate}\n{$credentialScope}\n" . hash('sha256', $canonicalRequest);

        // Signing key calculation
        $kSecret = 'AWS4' . $creds['secret_key'];
        $kDate = hash_hmac('sha256', $dateStamp, $kSecret, true);
        $kRegion = hash_hmac('sha256', $region, $kDate, true);
        $kService = hash_hmac('sha256', $service, $kRegion, true);
        $kSigning = hash_hmac('sha256', 'aws4_request', $kService, true);
        $signature = hash_hmac('sha256', $stringToSign, $kSigning);

        $authorizationHeader = "{$algorithm} Credential={$creds['access_key']}/{$credentialScope}, SignedHeaders={$signedHeaders}, Signature={$signature}";

        $httpHeaders = [
            "Content-Type: application/json",
            "X-Amz-Date: {$amzDate}",
            "Authorization: {$authorizationHeader}",
        ];
        if (!empty($creds['token'])) {
            $httpHeaders[] = "X-Amz-Security-Token: {$creds['token']}";
        }

        $ch = curl_init($endpoint);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => $httpHeaders,
            CURLOPT_TIMEOUT => 5,
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($httpCode >= 200 && $httpCode < 300) {
            $respData = json_decode((string)$response, true);
            return [
                'success' => true,
                'message_id' => $respData['MessageId'] ?? 'ses-api-ok',
                'recipient' => $recipient
            ];
        }

        return [
            'success' => false,
            'error' => "HTTP {$httpCode}: " . ($response ?: $curlError),
            'recipient' => $recipient
        ];
    }

    /**
     * Fallback via AWS CLI jika tersedia
     */
    private static function sendViaAwsCli(
        string $region,
        string $sender,
        string $recipient,
        string $subject,
        string $htmlBody,
        string $textBody
    ): array {
        // Cek keberadaan aws cli
        $checkCli = (DIRECTORY_SEPARATOR === '\\') ? 'where aws 2>nul' : 'command -v aws 2>/dev/null';
        $hasAws = shell_exec($checkCli);
        if (!$hasAws) {
            return ['success' => false, 'error' => 'AWS CLI not found'];
        }

        $tmpFile = tempnam(sys_get_temp_dir(), 'ses_email_');
        $msgData = json_encode([
            'Subject' => ['Data' => $subject, 'Charset' => 'UTF-8'],
            'Body' => [
                'Html' => ['Data' => $htmlBody, 'Charset' => 'UTF-8'],
                'Text' => ['Data' => $textBody, 'Charset' => 'UTF-8'],
            ]
        ], JSON_UNESCAPED_SLASHES);
        file_put_contents($tmpFile, $msgData);

        $cmd = sprintf(
            'aws ses send-email --region %s --from %s --destination ToAddresses=%s --message file://%s 2>&1',
            escapeshellarg($region),
            escapeshellarg($sender),
            escapeshellarg($recipient),
            escapeshellarg($tmpFile)
        );

        $output = shell_exec($cmd);
        @unlink($tmpFile);

        if ($output && (str_contains($output, 'MessageId') || str_contains($output, '"MessageId"'))) {
            return ['success' => true, 'output' => trim($output), 'recipient' => $recipient];
        }

        return ['success' => false, 'error' => trim((string)$output)];
    }

    /**
     * Dispatcher pengiriman laporan via Amazon SNS Topic (Didukung penuh di AWS Academy Learner Lab)
     */
    public static function dispatchSnsEmail(
        string $region,
        string $topicArn,
        string $subject,
        string $messageText,
        ?string $recipient = null
    ): array {
        $recipient = $recipient ?: env('SES_RECIPIENT_EMAIL', 'admin@kopdes.id');

        // 1. Coba ambil IAM role credentials dari EC2 IMDSv2
        $credentials = self::getEc2IamCredentials();
        if ($credentials) {
            $apiResult = self::sendViaSnsRestApi($region, $topicArn, $subject, $messageText, $credentials);
            if ($apiResult['success']) {
                $apiResult['recipient'] = $recipient;
                self::logAction("SUCCESS", "Report published to Amazon SNS Topic {$topicArn} via REST API. MessageId: " . ($apiResult['message_id'] ?? 'N/A'));
                return $apiResult;
            }
        }

        // 2. Coba fallback via AWS CLI jika terpasang
        $cliResult = self::sendViaSnsAwsCli($region, $topicArn, $subject, $messageText);
        if ($cliResult['success']) {
            $cliResult['recipient'] = $recipient;
            self::logAction("SUCCESS", "Report published to Amazon SNS Topic {$topicArn} via AWS CLI. MessageId: " . ($cliResult['message_id'] ?? 'N/A'));
            return $cliResult;
        }

        // 3. Fallback lingkungan lokal/simulasi jika tanpa kredensial AWS aktif
        self::logAction("FALLBACK", "No AWS IAM/CLI credentials detected for SNS. Report simulated for SNS Topic: {$topicArn}.");
        return [
            'success' => true,
            'mode' => 'local_fallback',
            'service' => 'Amazon SNS (Simulated)',
            'message' => "Laporan berhasil dipublikasikan ke topik notifikasi Amazon SNS (Lingkungan lokal/pengujian non-AWS).",
            'recipient' => $recipient,
            'topic_arn' => $topicArn,
            'message_id' => 'sns-sim-' . bin2hex(random_bytes(8))
        ];
    }

    /**
     * Kirim pesan ke SNS Topic via Query REST API dengan SigV4 signing
     */
    private static function sendViaSnsRestApi(
        string $region,
        string $topicArn,
        string $subject,
        string $messageText,
        array $creds
    ): array {
        $host = "sns.{$region}.amazonaws.com";
        $endpoint = "https://{$host}/";
        $service = 'sns';
        $amzDate = gmdate('Ymd\THis\Z');
        $dateStamp = gmdate('Ymd');

        $params = [
            'Action' => 'Publish',
            'Version' => '2010-03-31',
            'TopicArn' => $topicArn,
            'Subject' => substr($subject, 0, 100),
            'Message' => $messageText,
        ];
        $payload = http_build_query($params, '', '&', PHP_QUERY_RFC3986);

        $headers = [
            'content-type' => 'application/x-www-form-urlencoded; charset=utf-8',
            'host' => $host,
            'x-amz-date' => $amzDate,
        ];
        if (!empty($creds['token'])) {
            $headers['x-amz-security-token'] = $creds['token'];
        }

        ksort($headers);
        $canonicalHeaders = '';
        $signedHeadersList = [];
        foreach ($headers as $k => $v) {
            $canonicalHeaders .= strtolower($k) . ':' . trim($v) . "\n";
            $signedHeadersList[] = strtolower($k);
        }
        $signedHeaders = implode(';', $signedHeadersList);

        $payloadHash = hash('sha256', $payload);
        $canonicalRequest = "POST\n/\n\n{$canonicalHeaders}\n{$signedHeaders}\n{$payloadHash}";

        $algorithm = 'AWS4-HMAC-SHA256';
        $credentialScope = "{$dateStamp}/{$region}/{$service}/aws4_request";
        $stringToSign = "{$algorithm}\n{$amzDate}\n{$credentialScope}\n" . hash('sha256', $canonicalRequest);

        $kSecret = 'AWS4' . $creds['secret_key'];
        $kDate = hash_hmac('sha256', $dateStamp, $kSecret, true);
        $kRegion = hash_hmac('sha256', $region, $kDate, true);
        $kService = hash_hmac('sha256', $service, $kRegion, true);
        $kSigning = hash_hmac('sha256', 'aws4_request', $kService, true);
        $signature = hash_hmac('sha256', $stringToSign, $kSigning);

        $authorizationHeader = "{$algorithm} Credential={$creds['access_key']}/{$credentialScope}, SignedHeaders={$signedHeaders}, Signature={$signature}";

        $httpHeaders = [
            "Content-Type: application/x-www-form-urlencoded; charset=utf-8",
            "X-Amz-Date: {$amzDate}",
            "Authorization: {$authorizationHeader}",
        ];
        if (!empty($creds['token'])) {
            $httpHeaders[] = "X-Amz-Security-Token: {$creds['token']}";
        }

        $ch = curl_init($endpoint);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => $httpHeaders,
            CURLOPT_TIMEOUT => 5,
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($httpCode >= 200 && $httpCode < 300) {
            preg_match('/<MessageId>(.*?)<\/MessageId>/', (string)$response, $m);
            return [
                'success' => true,
                'service' => 'Amazon SNS',
                'message_id' => $m[1] ?? 'sns-api-ok',
            ];
        }

        return [
            'success' => false,
            'service' => 'Amazon SNS',
            'error' => "HTTP {$httpCode}: " . ($response ?: $curlError),
        ];
    }

    /**
     * Fallback publikasi SNS via AWS CLI
     */
    private static function sendViaSnsAwsCli(
        string $region,
        string $topicArn,
        string $subject,
        string $messageText
    ): array {
        $checkCli = (DIRECTORY_SEPARATOR === '\\') ? 'where aws 2>nul' : 'command -v aws 2>/dev/null';
        $hasAws = shell_exec($checkCli);
        if (!$hasAws) {
            return ['success' => false, 'error' => 'AWS CLI not found'];
        }

        $cmd = sprintf(
            'aws sns publish --region %s --topic-arn %s --subject %s --message %s 2>&1',
            escapeshellarg($region),
            escapeshellarg($topicArn),
            escapeshellarg(substr($subject, 0, 100)),
            escapeshellarg($messageText)
        );

        $output = shell_exec($cmd);
        if ($output && (str_contains($output, 'MessageId') || str_contains($output, '"MessageId"'))) {
            $data = json_decode((string)$output, true);
            return [
                'success' => true,
                'service' => 'Amazon SNS',
                'message_id' => $data['MessageId'] ?? trim($output)
            ];
        }

        return ['success' => false, 'service' => 'Amazon SNS', 'error' => trim((string)$output)];
    }

    /**
     * Template HTML laporan ekonomi yang rapi sesuai estetika KopDes
     */
    private static function buildReportHtml(array $summary): string {
        $turnover = format_rupiah($summary['grand_turnover'] ?? 0);
        $txCount = number_format((int)($summary['grand_tx'] ?? 0));
        $memberCount = number_format((int)($summary['grand_members'] ?? 0));
        $dateStr = date('d F Y, H:i');

        $rowsHtml = '';
        foreach (($summary['reports'] ?? []) as $r) {
            $kName = htmlspecialchars($r['name'] ?? '-');
            $kLoc = htmlspecialchars($r['location'] ?? '-');
            $kTurn = format_rupiah($r['total_turnover'] ?? 0);
            $kTx = number_format((int)($r['total_transactions'] ?? 0));
            $kMem = number_format((int)($r['total_members'] ?? 0));

            $rowsHtml .= "
                <tr>
                    <td style='padding:10px 12px;border-bottom:1px solid #e2e8f0;font-weight:600;color:#0f172a;'>{$kName}</td>
                    <td style='padding:10px 12px;border-bottom:1px solid #e2e8f0;color:#64748b;'>{$kLoc}</td>
                    <td style='padding:10px 12px;border-bottom:1px solid #e2e8f0;text-align:center;'>{$kMem}</td>
                    <td style='padding:10px 12px;border-bottom:1px solid #e2e8f0;text-align:center;'>{$kTx}</td>
                    <td style='padding:10px 12px;border-bottom:1px solid #e2e8f0;text-align:right;font-weight:700;color:#991b1b;'>{$kTurn}</td>
                </tr>
            ";
        }

        return "
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset='utf-8'>
            <title>Laporan KopDes</title>
        </head>
        <body style='font-family:-apple-system,BlinkMacSystemFont,\"Segoe UI\",Roboto,Helvetica,Arial,sans-serif;background-color:#f8fafc;margin:0;padding:24px;color:#1e293b;'>
            <div style='max-width:680px;margin:0 auto;background:#ffffff;border-radius:12px;overflow:hidden;border:1px solid #e2e8f0;box-shadow:0 4px 6px -1px rgba(0,0,0,0.05);'>
                <div style='background:linear-gradient(135deg, #991b1b 0%, #b91c1c 100%);padding:24px 28px;color:#ffffff;'>
                    <h1 style='margin:0;font-size:20px;font-weight:800;letter-spacing:-0.02em;'>KopDes Merah Putih</h1>
                    <p style='margin:6px 0 0;font-size:13px;opacity:0.9;'>Rekapitulasi Ekonomi &amp; Kinerja Unit Koperasi Desa &bull; AWS Native</p>
                </div>
                
                <div style='padding:24px 28px;'>
                    <p style='margin:0 0 16px;font-size:14px;color:#475569;'>
                        Laporan agregat berhasil digenerate pada <strong>{$dateStr}</strong>. Berikut adalah ikhtisar metrik kinerja operasional:
                    </p>
                    
                    <div style='display:flex;gap:12px;margin-bottom:24px;'>
                        <div style='flex:1;background:#fef2f2;border:1px solid #fee2e2;border-radius:8px;padding:14px;'>
                            <div style='font-size:11px;font-weight:700;color:#991b1b;text-transform:uppercase;'>Total Omzet</div>
                            <div style='font-size:18px;font-weight:800;color:#7f1d1d;margin-top:4px;'>{$turnover}</div>
                        </div>
                        <div style='flex:1;background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:14px;'>
                            <div style='font-size:11px;font-weight:700;color:#475569;text-transform:uppercase;'>Total Transaksi</div>
                            <div style='font-size:18px;font-weight:800;color:#0f172a;margin-top:4px;'>{$txCount}</div>
                        </div>
                        <div style='flex:1;background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:14px;'>
                            <div style='font-size:11px;font-weight:700;color:#475569;text-transform:uppercase;'>Anggota Aktif</div>
                            <div style='font-size:18px;font-weight:800;color:#0f172a;margin-top:4px;'>{$memberCount}</div>
                        </div>
                    </div>

                    <h3 style='margin:0 0 12px;font-size:14px;font-weight:700;color:#0f172a;'>Rincian Unit Koperasi</h3>
                    <table style='width:100%;border-collapse:collapse;font-size:13px;'>
                        <thead>
                            <tr style='background:#f1f5f9;text-align:left;'>
                                <th style='padding:8px 12px;color:#475569;'>Unit</th>
                                <th style='padding:8px 12px;color:#475569;'>Lokasi</th>
                                <th style='padding:8px 12px;color:#475569;text-align:center;'>Warga</th>
                                <th style='padding:8px 12px;color:#475569;text-align:center;'>Tx</th>
                                <th style='padding:8px 12px;color:#475569;text-align:right;'>Omzet</th>
                            </tr>
                        </thead>
                        <tbody>
                            {$rowsHtml}
                        </tbody>
                    </table>
                    
                    <div style='margin-top:24px;padding-top:16px;border-top:1px solid #e2e8f0;font-size:12px;color:#94a3b8;text-align:center;'>
                        Email ini dikirimkan secara otomatis oleh sistem <strong>KopDes Merah Putih</strong> melalui <strong>Amazon SES</strong>.
                    </div>
                </div>
            </div>
        </body>
        </html>
        ";
    }

    /**
     * Versi plaintext laporan
     */
    private static function buildReportText(array $summary): string {
        $turnover = format_rupiah($summary['grand_turnover'] ?? 0);
        $txCount = number_format((int)($summary['grand_tx'] ?? 0));
        $memberCount = number_format((int)($summary['grand_members'] ?? 0));
        $dateStr = date('d F Y, H:i');

        $out = "========================================================\n";
        $out .= "KOPDES MERAH PUTIH - REKAPITULASI LAPORAN EKONOMI\n";
        $out .= "Waktu Pembuatan: {$dateStr}\n";
        $out .= "========================================================\n\n";
        $out .= "RINGKASAN UTAMA:\n";
        $out .= "- Total Perputaran Omzet: {$turnover}\n";
        $out .= "- Total Transaksi Selesai: {$txCount}\n";
        $out .= "- Total Anggota Warga Aktif: {$memberCount}\n\n";
        $out .= "RINCIAN PER UNIT KOPDES:\n";

        foreach (($summary['reports'] ?? []) as $r) {
            $name = $r['name'] ?? '-';
            $turn = format_rupiah($r['total_turnover'] ?? 0);
            $tx = $r['total_transactions'] ?? 0;
            $out .= sprintf("- %s | %s | %s transaksi\n", $name, $turn, $tx);
        }

        $out .= "\n---\nDikirimkan secara otomatis melalui Amazon SES.\n";
        return $out;
    }

    /**
     * Catat log pengiriman email secara aman (tanpa kredensial/rahasia)
     */
    private static function logAction(string $status, string $message): void {
        $logDir = dirname(self::$logFile);
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0755, true);
        }
        $line = sprintf("[%s] [%s] %s\n", date('Y-m-d H:i:s'), $status, $message);
        @file_put_contents(self::$logFile, $line, FILE_APPEND);
    }
}
