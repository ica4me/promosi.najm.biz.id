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

$env = load_env(__DIR__ . '/.env');

$appName = $env['APP_NAME'] ?? 'Auto Uploader Guest';

// Ambil dari .env, jika kosong maka lakukan auto-deteksi Host Header
$appBaseUrl = $env['APP_BASE_URL'] ?? '';
if ($appBaseUrl === '') {
    $protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? "https://" : "http://";
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $appBaseUrl = $protocol . $host;
} else {
    $appBaseUrl = rtrim($appBaseUrl, '/');
}

$indexPath = $env['INDEX_PATH'] ?? '/index.php';
$prosesPath = $env['PROSES_PATH'] ?? '/proses.php';
$prosesUrl = $appBaseUrl . $prosesPath;

$defaultServer = $env['DEFAULT_SERVER'] ?? 'sfile';
$availableServers = array_filter(array_map('trim', explode(',', $env['AVAILABLE_SERVERS'] ?? 'sfile,simfile')));
$maxFileSizeMb = (int)($env['MAX_FILE_SIZE_MB'] ?? 512);

function h($v) {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= h($appName) ?></title>
    <link rel="stylesheet" href="index.css">
</head>
<body>
    <div class="wrap">
        <section class="hero">
            <div class="card hero-main">
                <div class="eyebrow">⚡ Modern Upload Panel</div>
                <h1><?= h($appName) ?></h1>
                <p class="sub">
                    Upload banyak file sekaligus dengan drag &amp; drop, lihat daftar file yang dipilih, pantau progress 0–100%,
                    lalu salin link hasil upload dengan satu klik.
                </p>

                <div class="stats">
                    <div class="stat">
                        <div class="label">Mode</div>
                        <div class="value">Multi Upload</div>
                    </div>
                    <div class="stat">
                        <div class="label">Batas per file</div>
                        <div class="value"><?= h($maxFileSizeMb) ?> MB</div>
                    </div>
                    <div class="stat">
                        <div class="label">Endpoint</div>
                        <div class="value">.env Config</div>
                    </div>
                </div>
            </div>

            <div class="card hero-side">
                <div class="toolbar">
                    <h2>Konfigurasi aktif</h2>
                    <a href="schedule.php" class="schedule-link">⏱️ Menu Schedule</a>
                </div>
                <div class="side-box">
                    <div class="chip">PROSES: <?= h($prosesUrl) ?></div>
                    <div class="chip">DEFAULT: <?= h($defaultServer) ?></div>
                    <div class="chip">SERVER: <?= h(implode(', ', $availableServers)) ?></div>
                </div>
            </div>
        </section>

        <section class="stack">
            <div class="card panel">
                <div class="toolbar">
                    <div>
                        <h2>Pilih file</h2>
                        <div class="muted">Drag &amp; drop atau klik area di bawah. Banyak file sekaligus didukung.</div>
                    </div>
                </div>

                <form id="uploadForm" action="<?= h($prosesUrl) ?>" method="POST" enctype="multipart/form-data">
                    <div class="controls">
                        <div>
                            <div id="dropzone" class="dropzone">
                                <input type="file" name="files[]" id="files" multiple>
                                <div class="drop-inner">
                                    <div class="drop-icon">📦</div>
                                    <div class="drop-title">Tarik file ke sini</div>
                                    <div class="drop-desc">
                                        atau klik untuk memilih file dari perangkat Anda.<br>
                                        Mendukung upload banyak file sekaligus.
                                    </div>
                                </div>
                            </div>

                            <div id="fileList" class="file-list">
                                <div class="empty">Belum ada file yang dipilih.</div>
                            </div>
                        </div>

                        <div class="side-box">
                            <div class="field">
                                <label for="server">Server tujuan</label>
                                <select name="server" id="server" required>
                                    <?php foreach ($availableServers as $sv): ?>
                                        <option value="<?= h($sv) ?>" <?= $sv === $defaultServer ? 'selected' : '' ?>>
                                            <?= h(strtoupper($sv)) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="hint">Bisa diganti lewat file .env.</div>
                            </div>

                            <div class="field">
                                <label>Ringkasan pilihan</label>
                                <div class="chip" id="countChip">0 file</div>
                                <div class="chip" id="sizeChip">0 B</div>
                            </div>

                            <div class="buttons">
                                <button type="submit" class="btn btn-primary" id="uploadBtn">Mulai Upload</button>
                                <button type="button" class="btn btn-secondary" id="clearBtn">Bersihkan</button>
                            </div>
                        </div>
                    </div>
                </form>
            </div>

            <div class="card panel">
                <div class="toolbar">
                    <div>
                        <h2>Progress upload</h2>
                        <div class="muted">Proses upload dari server PHP ke server SFILE atau SIMFILE.</div>
                    </div>
                </div>

                <div class="progress-wrap">
                    <div class="progress-bar">
                        <div id="progressFill" class="progress-fill"></div>
                    </div>
                    <div class="progress-meta">
                        <div id="progressText">Menunggu file dipilih.</div>
                        <div id="progressPercent">0%</div>
                    </div>
                </div>
            </div>

            <div class="card panel">
                <div class="toolbar">
                    <div>
                        <h2>Hasil upload</h2>
                        <div class="muted">Log terminal asli tetap disimpan dan bisa dibuka per file.</div>
                    </div>
                </div>

                <div id="results" class="results">
                    <div class="empty">Hasil upload akan muncul di sini.</div>
                </div>
            </div>
        </section>
    </div>

    <div id="toast" class="toast">Tersalin ke clipboard.</div>

    <script src="index.js"></script>
</body>
</html>