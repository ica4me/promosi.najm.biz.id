<?php
function load_env($path) {
    $env = [];
    if (file_exists($path)) {
        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $line = trim($line);
            if ($line !== '' && !str_starts_with($line, '#') && strpos($line, '=') !== false) {
                list($key, $val) = explode('=', $line, 2);
                $env[trim($key)] = trim($val);
            }
        }
    }
    return $env;
}

function update_env($key, $value) {
    $path = __DIR__ . '/.env';
    $lines = file_exists($path) ? file($path, FILE_IGNORE_NEW_LINES) : [];
    $found = false;
    foreach ($lines as &$line) {
        if (str_starts_with(trim($line), $key . '=')) {
            $line = $key . '=' . $value;
            $found = true;
            break;
        }
    }
    if (!$found) $lines[] = $key . '=' . $value;
    file_put_contents($path, implode("\n", $lines) . "\n");
}

$env = load_env(__DIR__ . '/.env');
$queueDir = __DIR__ . '/' . ($env['QUEUE_DIR'] ?? 'queue_uploads') . '/';
if (!is_dir($queueDir)) mkdir($queueDir, 0777, true);

// --- Handle Update Pengaturan Bot ---
if (isset($_POST['update_settings'])) {
    $action = $_POST['update_settings'];
    $newInterval = max(1, (int)$_POST['interval']); // Minimal 1 menit
    
    $newStatus = $env['SCHEDULE_STATUS'] ?? 'running';
    if ($action === 'stop') $newStatus = 'stopped';
    if ($action === 'start') $newStatus = 'running';

    update_env('SCHEDULE_INTERVAL_MINUTES', $newInterval);
    update_env('SCHEDULE_STATUS', $newStatus);
    
    header("Location: schedule.php?msg=Pengaturan+bot+berhasil+diperbarui");
    exit;
}

// Reload env setelah update
$env = load_env(__DIR__ . '/.env');
$currentStatus = $env['SCHEDULE_STATUS'] ?? 'running';
$currentInterval = $env['SCHEDULE_INTERVAL_MINUTES'] ?? '60';

// --- Handle Delete File ---
if (isset($_GET['action']) && $_GET['action'] === 'delete' && !empty($_GET['file'])) {
    $file = basename($_GET['file']);
    if (file_exists($queueDir . $file)) unlink($queueDir . $file);
    header("Location: schedule.php?msg=File+Terhapus");
    exit;
}

// --- Handle Upload ke Queue ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['queue_files'])) {
    foreach ($_FILES['queue_files']['tmp_name'] as $idx => $tmp) {
        if ($_FILES['queue_files']['error'][$idx] === UPLOAD_ERR_OK) {
            $name = basename($_FILES['queue_files']['name'][$idx]);
            $safeName = preg_replace('/[^A-Za-z0-9._-]/', '_', $name);
            move_uploaded_file($tmp, $queueDir . $safeName);
        }
    }
    header("Location: schedule.php?msg=File+berhasil+masuk+antrean");
    exit;
}

// --- Handle Run Now (Test Manual) ---
$runResult = '';
if (isset($_GET['action']) && $_GET['action'] === 'run_now') {
    $pyBin = $env['PYTHON_BIN'] ?? 'python';
    $out = shell_exec(escapeshellcmd($pyBin) . " scheduler.py --once 2>&1");
    $decoded = json_decode($out, true);
    $runResult = $decoded['message'] ?? $out;
}

// Ambil daftar file
$files = array_diff(scandir($queueDir), ['.', '..']);
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Schedule Manager</title>
    <style>
        body{font-family:system-ui,sans-serif;background:#0f172a;color:#eaf2ff;padding:20px}
        .wrap{max-width:900px;margin:auto;background:#1e293b;padding:25px;border-radius:15px;border:1px solid #334155;}
        h2{margin-top:0; color:#6ea8fe;}
        .flex{display:flex; justify-content:space-between; align-items:center; margin-bottom:20px;}
        .btn{padding:10px 16px; border-radius:8px; border:none; cursor:pointer; font-weight:bold; color:#fff; text-decoration:none; display:inline-block; font-size:14px;}
        .btn-blue{background:#3b82f6;} .btn-red{background:#ef4444;} .btn-gray{background:#475569;} .btn-green{background:#10b981;}
        .list-item{display:flex; justify-content:space-between; align-items:center; background:#0f172a; padding:12px 15px; margin-bottom:10px; border-radius:10px; border:1px solid #334155;}
        .alert{background:#065f46; color:#a7f3d0; padding:12px; border-radius:8px; margin-bottom:20px;}
        .terminal{background:#000; color:#0f0; padding:15px; border-radius:8px; font-family:monospace; margin-bottom:20px;}
        .form-upload{border:2px dashed #3b82f6; padding:20px; text-align:center; border-radius:10px; margin-bottom:20px; background:#0f172a;}
        .control-panel{background:#0f172a; border:1px solid #3b82f6; padding:18px; border-radius:10px; margin-bottom:20px;}
        .control-group{display:flex; gap:15px; align-items:center; flex-wrap:wrap;}
        .input-number{width:70px; padding:8px; border-radius:6px; border:1px solid #334155; background:#1e293b; color:#fff; font-weight:bold;}
    </style>
</head>
<body>
    <div class="wrap">
        <div class="flex">
            <h2>⏱️ Schedule Manager</h2>
            <a href="index.php" class="btn btn-gray">Kembali ke Beranda</a>
        </div>

        <?php if(!empty($_GET['msg'])): ?>
            <div class="alert">✅ <?= htmlspecialchars($_GET['msg']) ?></div>
        <?php endif; ?>

        <div class="control-panel">
            <h3 style="margin-top:0; color:#94a3b8; font-size:16px;">⚙️ Control Panel Mesin Bot</h3>
            <form method="POST" class="control-group">
                <div style="flex-grow: 1;">
                    <strong>Status:</strong> 
                    <?php if($currentStatus === 'running'): ?>
                        <span style="color:#34d399; font-weight:bold; letter-spacing:1px;">🟢 RUNNING</span>
                    <?php else: ?>
                        <span style="color:#f87171; font-weight:bold; letter-spacing:1px;">🔴 STOPPED</span>
                    <?php endif; ?>
                </div>
                
                <div style="display:flex; align-items:center; gap:10px;">
                    <label>Interval:</label>
                    <input type="number" name="interval" class="input-number" value="<?= htmlspecialchars($currentInterval) ?>" min="1">
                    <span>Menit</span>
                </div>

                <?php if($currentStatus === 'running'): ?>
                    <button type="submit" name="update_settings" value="stop" class="btn btn-red">⏹️ Stop Bot</button>
                <?php else: ?>
                    <button type="submit" name="update_settings" value="start" class="btn btn-green">▶️ Start Bot</button>
                <?php endif; ?>
                
                <button type="submit" name="update_settings" value="save" class="btn btn-blue">💾 Simpan Interval</button>
            </form>
        </div>

        <?php if($runResult): ?>
            <div class="terminal">🤖 Output Mesin: <br><br><?= htmlspecialchars($runResult) ?></div>
        <?php endif; ?>

        <form class="form-upload" method="POST" enctype="multipart/form-data">
            <p>Pilih file untuk dimasukkan ke dalam antrean (Bot akan menyapu semua file ini)</p>
            <input type="file" name="queue_files[]" multiple required style="margin-bottom:15px; color:#94a3b8;">
            <button type="submit" class="btn btn-blue">Tambahkan ke Antrean</button>
        </form>

        <div class="flex">
            <h3>Daftar Antrean (<?= count($files) ?> file)</h3>
            <a href="?action=run_now" class="btn btn-blue" onclick="return confirm('Mulai eksekusi BATCH sekarang? Laporan akan masuk Telegram.')">🚀 Test Manual Sekaran</a>
        </div>

        <?php if(empty($files)): ?>
            <p style="text-align:center; color:#94a3b8; padding:20px; border:1px dashed #334155; border-radius:10px;">Antrean kosong. Menunggu file baru.</p>
        <?php else: ?>
            <?php foreach($files as $file): ?>
                <div class="list-item">
                    <div>
                        <strong><?= htmlspecialchars($file) ?></strong>
                    </div>
                    <a href="?action=delete&file=<?= urlencode($file) ?>" class="btn btn-red" onclick="return confirm('Hapus file ini dari antrean?')">Hapus</a>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</body>
</html>