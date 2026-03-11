<?php
function load_env($path)
{
    $env = [];
    if (!file_exists($path)) {
        return $env;
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }

        $pos = strpos($line, '=');
        if ($pos === false) {
            continue;
        }

        $key = trim(substr($line, 0, $pos));
        $val = trim(substr($line, $pos + 1));

        if (
            (str_starts_with($val, '"') && str_ends_with($val, '"')) ||
            (str_starts_with($val, "'") && str_ends_with($val, "'"))
        ) {
            $val = substr($val, 1, -1);
        }

        $env[$key] = $val;
    }

    return $env;
}

function h($v) {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function format_bytes($bytes)
{
    $bytes = (float)$bytes;
    if ($bytes <= 0) return '0 B';
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $pow = floor(log($bytes, 1024));
    $pow = min($pow, count($units) - 1);
    $bytes /= pow(1024, $pow);
    return round($bytes, $pow === 0 ? 0 : 2) . ' ' . $units[$pow];
}

function is_ajax_request()
{
    return (
        !empty($_SERVER['HTTP_X_REQUESTED_WITH']) &&
        strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest'
    );
}

$env = load_env(__DIR__ . '/.env');

$appName = $env['APP_NAME'] ?? 'Auto Uploader Guest';

$appBaseUrl = $env['APP_BASE_URL'] ?? '';
if ($appBaseUrl === '') {
    $protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? "https://" : "http://";
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $appBaseUrl = $protocol . $host;
} else {
    $appBaseUrl = rtrim($appBaseUrl, '/');
}

$indexPath = $env['INDEX_PATH'] ?? '/index.php';
$pythonBin = $env['PYTHON_BIN'] ?? 'python';
$pythonScript = $env['PYTHON_SCRIPT'] ?? 'uploader.py';
$tempDirName = trim($env['TEMP_DIR'] ?? 'temp_uploads', '/\\');
$maxFileSizeMb = (int)($env['MAX_FILE_SIZE_MB'] ?? 512);
$availableServers = array_filter(array_map('trim', explode(',', $env['AVAILABLE_SERVERS'] ?? 'sfile,simfile')));

$indexUrl = $appBaseUrl . $indexPath;
$uploadDir = __DIR__ . DIRECTORY_SEPARATOR . $tempDirName . DIRECTORY_SEPARATOR;
$pythonScriptPath = __DIR__ . DIRECTORY_SEPARATOR . $pythonScript;
$isAjax = is_ajax_request();

if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0777, true);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_FILES['files'])) {
    if ($isAjax) {
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode([
            'status' => 'error',
            'message' => 'Akses tidak valid.'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    echo "Akses tidak valid.";
    exit;
}

$targetServer = trim($_POST['server'] ?? '');
if ($targetServer === '' || !in_array($targetServer, $availableServers, true)) {
    $targetServer = $availableServers[0] ?? 'sfile';
}

$totalFiles = count($_FILES['files']['name'] ?? []);
$results = [];
$successCount = 0;
$errorCount = 0;

for ($i = 0; $i < $totalFiles; $i++) {
    $fileName = $_FILES['files']['name'][$i] ?? 'unknown';
    $fileTmp = $_FILES['files']['tmp_name'][$i] ?? '';
    $fileError = $_FILES['files']['error'][$i] ?? UPLOAD_ERR_NO_FILE;
    $fileSize = (int)($_FILES['files']['size'][$i] ?? 0);

    $item = [
        'original_name' => $fileName,
        'final_name' => $fileName,
        'status' => 'error',
        'server' => $targetServer,
        'url' => null,
        'message' => '',
        'terminal_output' => '',
        'size' => $fileSize,
        'size_human' => format_bytes($fileSize),
    ];

    if ($fileError !== UPLOAD_ERR_OK) {
        $item['message'] = 'Error upload lokal PHP. Kode: ' . $fileError;
        $item['terminal_output'] = 'PHP upload error code: ' . $fileError;
        $results[] = $item;
        $errorCount++;
        continue;
    }

    if ($fileSize > ($maxFileSizeMb * 1024 * 1024)) {
        $item['message'] = 'File melebihi batas ukuran dari konfigurasi aplikasi.';
        $item['terminal_output'] = 'Blocked by MAX_FILE_SIZE_MB in .env';
        $results[] = $item;
        $errorCount++;
        continue;
    }

    $safeBaseName = preg_replace('/[^A-Za-z0-9._-]/', '_', basename($fileName));
    
    // Buat folder unik khusus untuk file ini agar namanya tetap utuh
    $uniqueDir = $uploadDir . uniqid('up_', true) . DIRECTORY_SEPARATOR;
    if (!is_dir($uniqueDir)) {
        mkdir($uniqueDir, 0777, true);
    }
    
    $tempPath = $uniqueDir . $safeBaseName;

    if (!move_uploaded_file($fileTmp, $tempPath)) {
        $item['message'] = 'Gagal memindahkan file ke folder temporary.';
        $item['terminal_output'] = 'move_uploaded_file() returned false';
        $results[] = $item;
        $errorCount++;
        continue;
    }

    if (!file_exists($pythonScriptPath)) {
        $item['message'] = 'Python script uploader tidak ditemukan.';
        $item['terminal_output'] = 'Missing script: ' . $pythonScriptPath;
        if (file_exists($tempPath)) {
            unlink($tempPath);
        }
        $results[] = $item;
        $errorCount++;
        continue;
    }

    $cmd =
        escapeshellcmd($pythonBin) . ' ' .
        escapeshellarg($pythonScriptPath) . ' ' .
        escapeshellarg($tempPath) . ' ' .
        escapeshellarg($targetServer) . ' ' .
        escapeshellarg($fileName) . ' 2>&1';

    $output = shell_exec($cmd);
    $output = is_string($output) ? $output : '';
    $item['terminal_output'] = trim($output) !== '' ? $output : '(tidak ada output terminal)';

    $response = json_decode(trim($output), true);

    if (is_array($response) && ($response['status'] ?? '') === 'success') {
        $item['status'] = 'success';
        $item['final_name'] = $response['final_name'] ?? $fileName;
        $item['url'] = $response['url'] ?? null;
        $item['message'] = 'Upload berhasil.';
        $successCount++;
    } else {
        $item['status'] = 'error';
        $item['final_name'] = $response['final_name'] ?? $fileName;
        $item['url'] = $response['url'] ?? null;
        $item['message'] = is_array($response)
            ? ($response['message'] ?? 'Python script gagal mengembalikan pesan error.')
            : 'Output Python bukan JSON valid.';
        $errorCount++;
    }

    if (file_exists($tempPath)) {
        unlink($tempPath);
    }
    // Hapus juga folder uniknya
    if (isset($uniqueDir) && is_dir($uniqueDir)) {
        rmdir($uniqueDir);
    }

    $results[] = $item;
}

if ($isAjax) {
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode([
        'status' => 'ok',
        'server' => $targetServer,
        'summary' => [
            'total' => $totalFiles,
            'success' => $successCount,
            'error' => $errorCount,
        ],
        'results' => $results,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/* Fallback non-AJAX */
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= h($appName) ?> - Hasil Upload</title>
    <style>
        body{font-family:Arial,sans-serif;background:#0f172a;color:#e5eefc;padding:30px}
        .wrap{max-width:960px;margin:auto}
        .card{background:#111827;border:1px solid rgba(255,255,255,.08);border-radius:16px;padding:18px;margin-bottom:14px}
        .ok{border-left:5px solid #22c55e}
        .err{border-left:5px solid #ef4444}
        a{color:#8ab4ff}
        pre{white-space:pre-wrap;word-break:break-word;background:#0b1220;padding:12px;border-radius:12px;overflow:auto}
        .top{margin-bottom:20px}
        .btn{display:inline-block;padding:10px 14px;background:#1d4ed8;color:#fff;text-decoration:none;border-radius:10px}
    </style>
</head>
<body>
<div class="wrap">
    <div class="top">
        <h2>Hasil Upload</h2>
        <p>Total: <?= h($totalFiles) ?> | Berhasil: <?= h($successCount) ?> | Gagal: <?= h($errorCount) ?></p>
        <a class="btn" href="<?= h($indexUrl) ?>">Kembali</a>
    </div>

    <?php foreach ($results as $row): ?>
        <div class="card <?= $row['status'] === 'success' ? 'ok' : 'err' ?>">
            <strong><?= h($row['original_name']) ?></strong><br>
            Status: <?= h(strtoupper($row['status'])) ?><br>
            Server: <?= h($row['server']) ?><br>
            Ukuran: <?= h($row['size_human']) ?><br>
            Nama akhir: <?= h($row['final_name']) ?><br>
            Pesan: <?= h($row['message']) ?><br>
            <?php if (!empty($row['url'])): ?>
                Link: <a href="<?= h($row['url']) ?>" target="_blank"><?= h($row['url']) ?></a><br>
            <?php endif; ?>

            <details style="margin-top:10px">
                <summary>Lihat log terminal asli</summary>
                <pre><?= h($row['terminal_output']) ?></pre>
            </details>
        </div>
    <?php endforeach; ?>
</div>
</body>
</html>
