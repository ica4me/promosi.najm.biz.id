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

// --- Handle Delete All Files ---
if (isset($_GET['action']) && $_GET['action'] === 'delete_all') {
    $allFiles = array_diff(scandir($queueDir), ['.', '..']);
    foreach ($allFiles as $f) {
        if (is_file($queueDir . $f)) {
            unlink($queueDir . $f);
        }
    }
    header("Location: schedule.php?msg=Semua+file+antrean+berhasil+dihapus");
    exit;
}

// --- Handle Upload ke Queue ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['queue_files'])) {
    foreach ($_FILES['queue_files']['tmp_name'] as $idx => $tmp) {
        if ($_FILES['queue_files']['error'][$idx] === UPLOAD_ERR_OK) {
            // NAMA FILE TIDAK DIUBAH/DIFORMAT - SESUAI UPLOAD ASLI USER
            $name = basename($_FILES['queue_files']['name'][$idx]);
            move_uploaded_file($tmp, $queueDir . $name);
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
// Re-index array
$files = array_values($files);
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Schedule Manager - Auto Uploader</title>
    <style>
        :root {
            --bg: #0a0f1d;
            --panel: #121a2b;
            --card: #18233b;
            --line: rgba(255, 255, 255, 0.08);
            --text: #eaf2ff;
            --muted: #9fb0d1;
            --primary: #3b82f6;
            --success: #10b981;
            --danger: #ef4444;
            --radius: 14px;
        }

        body {
            font-family: Inter, ui-sans-serif, system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif;
            background: linear-gradient(180deg, var(--bg) 0%, #0f172a 100%);
            color: var(--text);
            margin: 0;
            padding: 20px;
            min-height: 100vh;
        }

        .wrap {
            max-width: 900px;
            margin: auto;
        }

        /* Header */
        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 25px;
            flex-wrap: wrap;
            gap: 15px;
        }

        .header h2 {
            margin: 0;
            color: #6ea8fe;
            font-size: 26px;
        }

        /* Cards & Panels */
        .card {
            background: var(--card);
            border: 1px solid var(--line);
            border-radius: var(--radius);
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.2);
        }

        .card-title {
            margin-top: 0;
            margin-bottom: 15px;
            color: #cbd5e1;
            font-size: 18px;
            border-bottom: 1px solid var(--line);
            padding-bottom: 10px;
        }

        /* Buttons */
        .btn {
            padding: 10px 18px;
            border-radius: 8px;
            border: none;
            cursor: pointer;
            font-weight: 600;
            color: #fff;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 14px;
            transition: all 0.2s;
            gap: 8px;
        }

        .btn:hover { filter: brightness(1.1); transform: translateY(-1px); }
        .btn-blue { background: var(--primary); }
        .btn-red { background: var(--danger); }
        .btn-gray { background: #475569; }
        .btn-green { background: var(--success); }
        .btn-outline { background: transparent; border: 1px solid var(--danger); color: #fca5a5; }
        .btn-outline:hover { background: rgba(239, 68, 68, 0.1); }

        /* Alerts & Terminal */
        .alert {
            background: rgba(16, 185, 129, 0.1);
            color: #a7f3d0;
            padding: 14px;
            border-radius: 8px;
            margin-bottom: 20px;
            border: 1px solid rgba(16, 185, 129, 0.2);
        }

        .terminal {
            background: #000;
            color: #4ade80;
            padding: 15px;
            border-radius: 8px;
            font-family: monospace;
            margin-bottom: 20px;
            border: 1px solid #333;
            overflow-x: auto;
        }

        /* Forms & Inputs */
        .control-group {
            display: flex;
            gap: 15px;
            align-items: center;
            flex-wrap: wrap;
        }

        .input-number {
            width: 80px;
            padding: 10px;
            border-radius: 6px;
            border: 1px solid #334155;
            background: #0f172a;
            color: #fff;
            font-weight: bold;
            font-size: 14px;
            text-align: center;
        }

        .form-upload {
            border: 2px dashed #3b82f6;
            padding: 30px 20px;
            text-align: center;
            border-radius: var(--radius);
            background: rgba(59, 130, 246, 0.05);
            transition: background 0.2s;
        }
        .form-upload:hover {
            background: rgba(59, 130, 246, 0.1);
        }

        input[type="file"] {
            margin-bottom: 15px;
            color: var(--muted);
            font-size: 14px;
            width: 100%;
            max-width: 300px;
        }

        /* List Items */
        .list-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
            flex-wrap: wrap;
            gap: 15px;
        }

        .list-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: rgba(255,255,255, 0.03);
            padding: 12px 16px;
            margin-bottom: 10px;
            border-radius: 10px;
            border: 1px solid var(--line);
            gap: 15px;
        }

        .filename {
            font-weight: 600;
            word-break: break-all;
            color: #e2e8f0;
        }

        /* Mobile Responsiveness */
        @media (max-width: 600px) {
            .header h2 { font-size: 22px; }
            .btn { width: 100%; text-align: center; }
            .control-group { flex-direction: column; align-items: stretch; }
            .input-group { display: flex; justify-content: space-between; align-items: center; }
            .list-item { flex-direction: column; align-items: flex-start; }
            .list-item .btn { width: auto; align-self: flex-end; }
        }
    </style>
</head>
<body>
    <div class="wrap">
        <div class="header">
            <h2>⏱️ Schedule Manager</h2>
            <a href="index.php" class="btn btn-gray">⬅ Kembali ke Beranda</a>
        </div>

        <?php if(!empty($_GET['msg'])): ?>
            <div class="alert">✅ <?= htmlspecialchars($_GET['msg']) ?></div>
        <?php endif; ?>

        <div class="card">
            <h3 class="card-title">⚙️ Control Panel Mesin Bot</h3>
            <form method="POST" class="control-group">
                <div style="flex-grow: 1; font-size: 15px;">
                    <strong>Status Bot:</strong> 
                    <?php if($currentStatus === 'running'): ?>
                        <span style="color:#34d399; font-weight:bold; letter-spacing:1px; background: rgba(52, 211, 153, 0.1); padding: 4px 8px; border-radius: 4px;">🟢 RUNNING</span>
                    <?php else: ?>
                        <span style="color:#f87171; font-weight:bold; letter-spacing:1px; background: rgba(248, 113, 113, 0.1); padding: 4px 8px; border-radius: 4px;">🔴 STOPPED</span>
                    <?php endif; ?>
                </div>
                
                <div class="input-group" style="display:flex; align-items:center; gap:10px;">
                    <label>Jeda (Interval):</label>
                    <input type="number" name="interval" class="input-number" value="<?= htmlspecialchars($currentInterval) ?>" min="1">
                    <span style="color: var(--muted);">Menit</span>
                </div>

                <div style="display: flex; gap: 10px; flex-wrap: wrap;">
                    <?php if($currentStatus === 'running'): ?>
                        <button type="submit" name="update_settings" value="stop" class="btn btn-red">⏹️ Stop Bot</button>
                    <?php else: ?>
                        <button type="submit" name="update_settings" value="start" class="btn btn-green">▶️ Start Bot</button>
                    <?php endif; ?>
                    <button type="submit" name="update_settings" value="save" class="btn btn-blue">💾 Simpan Info</button>
                </div>
            </form>
        </div>

        <?php if($runResult): ?>
            <div class="terminal">🤖 Output Mesin: <br><br><?= htmlspecialchars($runResult) ?></div>
        <?php endif; ?>

        <div class="card">
            <h3 class="card-title">📁 Tambah File ke Antrean</h3>
            <form class="form-upload" method="POST" enctype="multipart/form-data">
                <p style="color: var(--muted); margin-top: 0;">Pilih file untuk dimasukkan ke dalam antrean (Bot akan mengunggah file ini otomatis)</p>
                <input type="file" name="queue_files[]" multiple required>
                <br>
                <button type="submit" class="btn btn-blue">➕ Tambahkan ke Antrean</button>
            </form>
        </div>

        <div class="card">
            <div class="list-header">
                <h3 class="card-title" style="border: none; margin: 0; padding: 0;">📋 Daftar Antrean (<?= count($files) ?> file)</h3>
                
                <div style="display: flex; gap: 10px; flex-wrap: wrap;">
                    <?php if(!empty($files)): ?>
                        <a href="?action=delete_all" class="btn btn-outline" onclick="return confirm('⚠️ Yakin ingin menghapus SEMUA file dari antrean?')">🗑️ Hapus Semua</a>
                    <?php endif; ?>
                    <a href="?action=run_now" class="btn btn-blue" onclick="return confirm('🚀 Mulai eksekusi BATCH sekarang? Laporan akan masuk Telegram.')">🚀 Test Manual Sekaran</a>
                </div>
            </div>

            <?php if(empty($files)): ?>
                <div style="text-align:center; color:var(--muted); padding:30px 20px; border:1px dashed var(--line); border-radius:10px; background: rgba(255,255,255,0.01);">
                    📦 Antrean kosong. Menunggu file baru untuk diunggah.
                </div>
            <?php else: ?>
                <?php foreach($files as $file): ?>
                    <div class="list-item">
                        <div class="filename">
                            📄 <?= htmlspecialchars($file) ?>
                        </div>
                        <a href="?action=delete&file=<?= urlencode($file) ?>" class="btn btn-red" style="padding: 6px 12px; font-size: 13px;" onclick="return confirm('Hapus file ini dari antrean?')">Hapus</a>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>