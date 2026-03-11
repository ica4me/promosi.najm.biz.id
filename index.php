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
    <style>
        :root{
            --bg:#0b1020;
            --panel:#121a2b;
            --panel-2:#18233b;
            --line:rgba(255,255,255,.08);
            --text:#eaf2ff;
            --muted:#9fb0d1;
            --primary:#6ea8fe;
            --primary-2:#8b5cf6;
            --success:#22c55e;
            --danger:#ef4444;
            --warning:#f59e0b;
            --shadow:0 20px 50px rgba(0,0,0,.35);
            --radius:20px;
        }

        *{box-sizing:border-box}
        html,body{margin:0;padding:0}
        body{
            font-family: Inter, ui-sans-serif, system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif;
            background:
                radial-gradient(circle at top left, rgba(110,168,254,.14), transparent 30%),
                radial-gradient(circle at top right, rgba(139,92,246,.12), transparent 28%),
                linear-gradient(180deg, #0a0f1d 0%, #0f172a 100%);
            color:var(--text);
            min-height:100vh;
        }

        .wrap{
            width:min(1120px, calc(100% - 32px));
            margin:32px auto;
        }

        .hero{
            display:grid;
            grid-template-columns:1.25fr .75fr;
            gap:20px;
            align-items:stretch;
        }

        .card{
            background:linear-gradient(180deg, rgba(255,255,255,.03), rgba(255,255,255,.015));
            border:1px solid var(--line);
            border-radius:var(--radius);
            box-shadow:var(--shadow);
            backdrop-filter: blur(8px);
        }

        .hero-main{
            padding:28px;
        }

        .hero-side{
            padding:22px;
        }

        .eyebrow{
            display:inline-flex;
            align-items:center;
            gap:8px;
            padding:8px 12px;
            border-radius:999px;
            background:rgba(110,168,254,.12);
            color:#cfe1ff;
            font-size:12px;
            border:1px solid rgba(110,168,254,.22);
        }

        h1{
            margin:16px 0 10px;
            font-size:clamp(28px,4vw,42px);
            line-height:1.08;
            letter-spacing:-.03em;
        }

        .sub{
            color:var(--muted);
            font-size:15px;
            line-height:1.7;
            margin:0;
        }

        .stats{
            margin-top:20px;
            display:grid;
            grid-template-columns:repeat(3,1fr);
            gap:12px;
        }

        .stat{
            background:rgba(255,255,255,.03);
            border:1px solid var(--line);
            border-radius:16px;
            padding:14px;
        }

        .stat .label{
            font-size:12px;
            color:var(--muted);
            margin-bottom:8px;
        }

        .stat .value{
            font-size:18px;
            font-weight:700;
        }

        .stack{
            display:grid;
            gap:20px;
            margin-top:20px;
        }

        .panel{
            padding:22px;
        }

        .toolbar{
            display:flex;
            justify-content:space-between;
            align-items:center;
            gap:14px;
            margin-bottom:18px;
            flex-wrap:wrap;
        }

        .toolbar h2{
            margin:0;
            font-size:20px;
        }

        .muted{
            color:var(--muted);
            font-size:14px;
        }

        .controls{
            display:grid;
            grid-template-columns:1fr 240px;
            gap:16px;
            align-items:start;
        }

        .dropzone{
            position:relative;
            min-height:220px;
            border:1.5px dashed rgba(255,255,255,.18);
            background:
                linear-gradient(180deg, rgba(255,255,255,.02), rgba(255,255,255,.01));
            border-radius:20px;
            display:flex;
            align-items:center;
            justify-content:center;
            text-align:center;
            padding:24px;
            transition:.2s ease;
            overflow:hidden;
        }

        .dropzone.active{
            border-color:rgba(110,168,254,.75);
            background:rgba(110,168,254,.08);
            transform:translateY(-1px);
        }

        .dropzone input[type=file]{
            position:absolute;
            inset:0;
            opacity:0;
            cursor:pointer;
        }

        .drop-inner{
            pointer-events:none;
        }

        .drop-icon{
            font-size:42px;
            margin-bottom:10px;
        }

        .drop-title{
            font-size:20px;
            font-weight:700;
            margin-bottom:8px;
        }

        .drop-desc{
            color:var(--muted);
            font-size:14px;
            line-height:1.6;
        }

        .side-box{
            display:grid;
            gap:14px;
        }

        .field{
            display:grid;
            gap:8px;
        }

        .field label{
            font-size:13px;
            color:#c8d6f3;
            font-weight:600;
        }

        .field select{
            width:100%;
            border:none;
            outline:none;
            border-radius:14px;
            padding:14px 16px;
            background:#0f1729;
            color:var(--text);
            border:1px solid var(--line);
            font-size:15px;
        }

        .field .hint{
            color:var(--muted);
            font-size:12px;
        }

        .buttons{
            display:flex;
            gap:10px;
            flex-wrap:wrap;
        }

        .btn{
            appearance:none;
            border:none;
            outline:none;
            cursor:pointer;
            padding:13px 16px;
            border-radius:14px;
            font-weight:700;
            transition:.2s ease;
            font-size:14px;
        }

        .btn:disabled{
            opacity:.5;
            cursor:not-allowed;
        }

        .btn-primary{
            color:#0b1020;
            background:linear-gradient(135deg, var(--primary), #9dc0ff);
        }

        .btn-secondary{
            color:var(--text);
            background:#11192c;
            border:1px solid var(--line);
        }

        .btn-ghost{
            color:#d8e6ff;
            background:rgba(255,255,255,.04);
            border:1px solid var(--line);
        }

        .btn:hover:not(:disabled){
            transform:translateY(-1px);
            filter:brightness(1.04);
        }

        .file-list{
            display:grid;
            gap:12px;
            margin-top:18px;
        }

        .file-item{
            display:grid;
            grid-template-columns:48px 1fr auto;
            gap:14px;
            align-items:center;
            padding:14px;
            border-radius:16px;
            border:1px solid var(--line);
            background:rgba(255,255,255,.025);
        }

        .file-icon{
            width:48px;
            height:48px;
            border-radius:14px;
            display:grid;
            place-items:center;
            background:linear-gradient(135deg, rgba(110,168,254,.18), rgba(139,92,246,.18));
            color:#d8e7ff;
            font-size:20px;
            font-weight:800;
        }

        .file-name{
            font-weight:700;
            word-break:break-word;
        }

        .file-meta{
            color:var(--muted);
            font-size:13px;
            margin-top:5px;
            display:flex;
            gap:12px;
            flex-wrap:wrap;
        }

        .chip{
            display:inline-flex;
            align-items:center;
            gap:8px;
            padding:8px 10px;
            border:1px solid var(--line);
            background:rgba(255,255,255,.03);
            border-radius:999px;
            font-size:12px;
            color:#d7e6ff;
        }

        .progress-wrap{
            display:grid;
            gap:10px;
        }

        .progress-bar{
            width:100%;
            height:14px;
            border-radius:999px;
            background:#0e1526;
            border:1px solid var(--line);
            overflow:hidden;
        }

        .progress-fill{
            height:100%;
            width:0%;
            background:linear-gradient(90deg, var(--primary), var(--primary-2));
            transition:width .15s linear;
        }

        .progress-meta{
            display:flex;
            justify-content:space-between;
            gap:10px;
            flex-wrap:wrap;
            font-size:13px;
            color:var(--muted);
        }

        .results{
            display:grid;
            gap:14px;
        }

        .result-card{
            border:1px solid var(--line);
            border-radius:18px;
            padding:16px;
            background:rgba(255,255,255,.025);
        }

        .result-card.success{
            border-color:rgba(34,197,94,.25);
            box-shadow: inset 0 0 0 1px rgba(34,197,94,.08);
        }

        .result-card.error{
            border-color:rgba(239,68,68,.22);
            box-shadow: inset 0 0 0 1px rgba(239,68,68,.07);
        }

        .result-top{
            display:flex;
            justify-content:space-between;
            gap:12px;
            align-items:flex-start;
            flex-wrap:wrap;
        }

        .status-badge{
            padding:8px 12px;
            border-radius:999px;
            font-size:12px;
            font-weight:700;
        }

        .status-badge.success{
            background:rgba(34,197,94,.12);
            color:#9ef0ba;
            border:1px solid rgba(34,197,94,.22);
        }

        .status-badge.error{
            background:rgba(239,68,68,.12);
            color:#ffb4b4;
            border:1px solid rgba(239,68,68,.2);
        }

        .result-title{
            font-size:16px;
            font-weight:700;
            margin:0 0 8px;
            word-break:break-word;
        }

        .result-meta{
            color:var(--muted);
            font-size:13px;
            line-height:1.7;
        }

        .link-box{
            display:flex;
            gap:10px;
            flex-wrap:wrap;
            margin-top:14px;
        }

        .link-input{
            flex:1 1 320px;
            min-width:200px;
            padding:13px 14px;
            border-radius:14px;
            border:1px solid var(--line);
            background:#0f1729;
            color:#eaf2ff;
        }

        details.log{
            margin-top:14px;
            border-radius:14px;
            border:1px solid var(--line);
            overflow:hidden;
            background:#0d1424;
        }

        details.log summary{
            cursor:pointer;
            padding:12px 14px;
            color:#d9e7ff;
            font-weight:600;
            user-select:none;
        }

        .log pre{
            margin:0;
            padding:14px;
            color:#d6e5ff;
            background:#09111f;
            border-top:1px solid var(--line);
            overflow:auto;
            font-size:12px;
            line-height:1.6;
            white-space:pre-wrap;
            word-break:break-word;
        }

        .empty{
            padding:18px;
            text-align:center;
            color:var(--muted);
            border:1px dashed var(--line);
            border-radius:16px;
            background:rgba(255,255,255,.02);
        }

        .toast{
            position:fixed;
            right:18px;
            bottom:18px;
            z-index:99;
            background:#11192b;
            color:#eaf2ff;
            border:1px solid var(--line);
            border-radius:14px;
            padding:12px 14px;
            box-shadow:var(--shadow);
            transform:translateY(20px);
            opacity:0;
            transition:.2s ease;
            pointer-events:none;
        }

        .toast.show{
            transform:translateY(0);
            opacity:1;
        }

        @media (max-width: 900px){
            .hero{grid-template-columns:1fr}
            .controls{grid-template-columns:1fr}
            .stats{grid-template-columns:1fr}
        }
    </style>
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
                    <a href="schedule.php" style="color:#6ea8fe; text-decoration:none; font-size:14px; border:1px solid #6ea8fe; padding:6px 12px; border-radius:8px;">⏱️ Menu Schedule</a>
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

    <script>
        const form = document.getElementById('uploadForm');
        const input = document.getElementById('files');
        const dropzone = document.getElementById('dropzone');
        const fileListEl = document.getElementById('fileList');
        const resultsEl = document.getElementById('results');
        const countChip = document.getElementById('countChip');
        const sizeChip = document.getElementById('sizeChip');
        const uploadBtn = document.getElementById('uploadBtn');
        const clearBtn = document.getElementById('clearBtn');
        const progressFill = document.getElementById('progressFill');
        const progressText = document.getElementById('progressText');
        const progressPercent = document.getElementById('progressPercent');
        const toast = document.getElementById('toast');

        let selectedFiles = [];

        function formatBytes(bytes) {
            if (!bytes || bytes <= 0) return '0 B';
            const units = ['B', 'KB', 'MB', 'GB', 'TB'];
            const i = Math.floor(Math.log(bytes) / Math.log(1024));
            return (bytes / Math.pow(1024, i)).toFixed(i === 0 ? 0 : 2) + ' ' + units[i];
        }

        function extOf(name) {
            const p = name.split('.');
            return p.length > 1 ? p.pop().toUpperCase() : 'FILE';
        }

        function escapeHtml(str) {
            return String(str).replace(/[&<>"']/g, s => ({
                '&':'&amp;',
                '<':'&lt;',
                '>':'&gt;',
                '"':'&quot;',
                "'":'&#039;'
            }[s]));
        }

        function showToast(text) {
            toast.textContent = text;
            toast.classList.add('show');
            clearTimeout(showToast._t);
            showToast._t = setTimeout(() => toast.classList.remove('show'), 1600);
        }

        function rebuildInputFiles() {
            const dt = new DataTransfer();
            selectedFiles.forEach(file => dt.items.add(file));
            input.files = dt.files;
        }

        function renderFileList() {
            countChip.textContent = `${selectedFiles.length} file`;
            const total = selectedFiles.reduce((sum, f) => sum + f.size, 0);
            sizeChip.textContent = formatBytes(total);

            if (!selectedFiles.length) {
                fileListEl.innerHTML = `<div class="empty">Belum ada file yang dipilih.</div>`;
                return;
            }

            fileListEl.innerHTML = selectedFiles.map((file, idx) => `
                <div class="file-item">
                    <div class="file-icon">${escapeHtml(extOf(file.name).slice(0,4))}</div>
                    <div>
                        <div class="file-name">${escapeHtml(file.name)}</div>
                        <div class="file-meta">
                            <span>Ukuran: ${formatBytes(file.size)}</span>
                            <span>Tipe: ${escapeHtml(file.type || 'unknown')}</span>
                        </div>
                    </div>
                    <div>
                        <button type="button" class="btn btn-ghost" onclick="removeFile(${idx})">Hapus</button>
                    </div>
                </div>
            `).join('');
        }

        function addFiles(files) {
            const incoming = Array.from(files || []);
            if (!incoming.length) return;

            const map = new Map(selectedFiles.map(f => [`${f.name}__${f.size}__${f.lastModified}`, f]));
            incoming.forEach(file => {
                const key = `${file.name}__${file.size}__${file.lastModified}`;
                if (!map.has(key)) map.set(key, file);
            });

            selectedFiles = Array.from(map.values());
            rebuildInputFiles();
            renderFileList();
            resetProgress();
        }

        function removeFile(index) {
            selectedFiles.splice(index, 1);
            rebuildInputFiles();
            renderFileList();
        }
        window.removeFile = removeFile;

        function clearAll() {
            selectedFiles = [];
            rebuildInputFiles();
            form.reset();
            renderFileList();
            resetProgress();
        }

        function setProgress(percent, text) {
            const val = Math.max(0, Math.min(100, percent));
            progressFill.style.width = val + '%';
            progressPercent.textContent = Math.round(val) + '%';
            progressText.textContent = text;
        }

        function resetProgress() {
            setProgress(0, selectedFiles.length ? 'Siap untuk upload.' : 'Menunggu file dipilih.');
        }

        function copyToClipboard(text) {
            navigator.clipboard.writeText(text)
                .then(() => showToast('Link berhasil disalin.'))
                .catch(() => showToast('Gagal menyalin link.'));
        }
        window.copyToClipboard = copyToClipboard;

        function renderResults(payload) {
            if (!payload || !Array.isArray(payload.results) || !payload.results.length) {
                resultsEl.innerHTML = `<div class="empty">Tidak ada hasil untuk ditampilkan.</div>`;
                return;
            }

            resultsEl.innerHTML = payload.results.map(item => {
                const success = item.status === 'success';
                const originalName = escapeHtml(item.original_name || '-');
                const finalName = escapeHtml(item.final_name || item.original_name || '-');
                const server = escapeHtml(item.server || payload.server || '-');
                const url = item.url ? escapeHtml(item.url) : '';
                const terminal = escapeHtml(item.terminal_output || '');
                const message = escapeHtml(item.message || '');
                const sizeText = escapeHtml(item.size_human || '-');

                return `
                    <div class="result-card ${success ? 'success' : 'error'}">
                        <div class="result-top">
                            <div>
                                <div class="result-title">${originalName}</div>
                                <div class="result-meta">
                                    Server: <strong>${server}</strong><br>
                                    Ukuran: <strong>${sizeText}</strong><br>
                                    Nama akhir: <strong>${finalName}</strong>
                                    ${message ? `<br>Pesan: <strong>${message}</strong>` : ''}
                                </div>
                            </div>
                            <div class="status-badge ${success ? 'success' : 'error'}">
                                ${success ? 'BERHASIL' : 'GAGAL'}
                            </div>
                        </div>

                        ${success && url ? `
                            <div class="link-box">
                                <input class="link-input" type="text" readonly value="${url}">
                                <button type="button" class="btn btn-primary" onclick="copyToClipboard('${url}')">Copy Link</button>
                                <a class="btn btn-secondary" href="${url}" target="_blank" rel="noopener">Buka</a>
                            </div>
                        ` : ''}

                        <details class="log">
                            <summary>Lihat log terminal asli</summary>
                            <pre>${terminal || '(tidak ada log)'}</pre>
                        </details>
                    </div>
                `;
            }).join('');
        }

        dropzone.addEventListener('dragover', e => {
            e.preventDefault();
            dropzone.classList.add('active');
        });

        dropzone.addEventListener('dragleave', () => {
            dropzone.classList.remove('active');
        });

        dropzone.addEventListener('drop', e => {
            e.preventDefault();
            dropzone.classList.remove('active');
            addFiles(e.dataTransfer.files);
        });

        input.addEventListener('change', e => addFiles(e.target.files));
        clearBtn.addEventListener('click', clearAll);

        form.addEventListener('submit', function(e) {
            e.preventDefault();

            if (!selectedFiles.length) {
                showToast('Pilih file terlebih dahulu.');
                return;
            }

            const formData = new FormData();
            selectedFiles.forEach(file => formData.append('files[]', file));
            formData.append('server', document.getElementById('server').value);

            const xhr = new XMLHttpRequest();
            xhr.open('POST', form.action, true);
            xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
            xhr.responseType = 'json';
            xhr.timeout = 0;

            uploadBtn.disabled = true;
            clearBtn.disabled = true;
            resultsEl.innerHTML = `<div class="empty">Upload sedang berjalan...</div>`;
            setProgress(0, `Mempersiapkan upload ${selectedFiles.length} file...`);

            xhr.upload.addEventListener('progress', function(event) {
                if (event.lengthComputable) {
                    const percent = (event.loaded / event.total) * 100;
                    setProgress(percent, `Mengunggah file ke server... ${formatBytes(event.loaded)} / ${formatBytes(event.total)}`);
                }
            });

            xhr.upload.addEventListener('loadstart', function() {
                setProgress(0, 'Upload dimulai...');
            });

            xhr.upload.addEventListener('load', function() {
                setProgress(100, 'Transfer file selesai. Server sedang memproses upload remote...');
            });

            xhr.addEventListener('load', function() {
                uploadBtn.disabled = false;
                clearBtn.disabled = false;

                if (xhr.status >= 200 && xhr.status < 300) {
                    const payload = xhr.response;
                    if (payload && payload.status === 'ok') {
                        setProgress(100, `Selesai. ${payload.summary.success} berhasil, ${payload.summary.error} gagal.`);
                        renderResults(payload);
                    } else {
                        setProgress(100, 'Proses selesai, tetapi respons tidak valid.');
                        resultsEl.innerHTML = `<div class="empty">Respons server tidak valid.</div>`;
                    }
                } else {
                    setProgress(100, `Server merespons HTTP ${xhr.status}.`);
                    resultsEl.innerHTML = `<div class="empty">Server merespons HTTP ${xhr.status}.</div>`;
                }
            });

            xhr.addEventListener('error', function() {
                uploadBtn.disabled = false;
                clearBtn.disabled = false;
                setProgress(0, 'Upload gagal karena gangguan jaringan.');
                resultsEl.innerHTML = `<div class="empty">Terjadi error jaringan saat upload.</div>`;
            });

            xhr.addEventListener('timeout', function() {
                uploadBtn.disabled = false;
                clearBtn.disabled = false;
                setProgress(0, 'Upload timeout.');
                resultsEl.innerHTML = `<div class="empty">Upload timeout.</div>`;
            });

            xhr.send(formData);
        });

        renderFileList();
        resetProgress();
    </script>
</body>
</html>
