const form = document.getElementById("uploadForm");
const input = document.getElementById("files");
const dropzone = document.getElementById("dropzone");
const fileListEl = document.getElementById("fileList");
const resultsEl = document.getElementById("results");
const countChip = document.getElementById("countChip");
const sizeChip = document.getElementById("sizeChip");
const uploadBtn = document.getElementById("uploadBtn");
const clearBtn = document.getElementById("clearBtn");
const progressFill = document.getElementById("progressFill");
const progressText = document.getElementById("progressText");
const progressPercent = document.getElementById("progressPercent");
const toast = document.getElementById("toast");

let selectedFiles = [];

function formatBytes(bytes) {
  if (!bytes || bytes <= 0) return "0 B";
  const units = ["B", "KB", "MB", "GB", "TB"];
  const i = Math.floor(Math.log(bytes) / Math.log(1024));
  return (bytes / Math.pow(1024, i)).toFixed(i === 0 ? 0 : 2) + " " + units[i];
}

function extOf(name) {
  const p = name.split(".");
  return p.length > 1 ? p.pop().toUpperCase() : "FILE";
}

function escapeHtml(str) {
  return String(str).replace(
    /[&<>"']/g,
    (s) =>
      ({
        "&": "&amp;",
        "<": "&lt;",
        ">": "&gt;",
        '"': "&quot;",
        "'": "&#039;",
      })[s],
  );
}

function showToast(text) {
  toast.textContent = text;
  toast.classList.add("show");
  clearTimeout(showToast._t);
  showToast._t = setTimeout(() => toast.classList.remove("show"), 1600);
}

function rebuildInputFiles() {
  const dt = new DataTransfer();
  selectedFiles.forEach((file) => dt.items.add(file));
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

  fileListEl.innerHTML = selectedFiles
    .map(
      (file, idx) => `
        <div class="file-item">
            <div class="file-icon">${escapeHtml(extOf(file.name).slice(0, 4))}</div>
            <div>
                <div class="file-name">${escapeHtml(file.name)}</div>
                <div class="file-meta">
                    <span>Ukuran: ${formatBytes(file.size)}</span>
                    <span>Tipe: ${escapeHtml(file.type || "unknown")}</span>
                </div>
            </div>
            <div>
                <button type="button" class="btn btn-ghost" onclick="removeFile(${idx})">Hapus</button>
            </div>
        </div>
    `,
    )
    .join("");
}

function addFiles(files) {
  const incoming = Array.from(files || []);
  if (!incoming.length) return;

  const map = new Map(
    selectedFiles.map((f) => [`${f.name}__${f.size}__${f.lastModified}`, f]),
  );
  incoming.forEach((file) => {
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
  progressFill.style.width = val + "%";
  progressPercent.textContent = Math.round(val) + "%";
  progressText.textContent = text;
}

function resetProgress() {
  setProgress(
    0,
    selectedFiles.length ? "Siap untuk upload." : "Menunggu file dipilih.",
  );
}

function copyToClipboard(text) {
  navigator.clipboard
    .writeText(text)
    .then(() => showToast("Link berhasil disalin."))
    .catch(() => showToast("Gagal menyalin link."));
}
window.copyToClipboard = copyToClipboard;

function renderResults(payload) {
  if (!payload || !Array.isArray(payload.results) || !payload.results.length) {
    resultsEl.innerHTML = `<div class="empty">Tidak ada hasil untuk ditampilkan.</div>`;
    return;
  }

  resultsEl.innerHTML = payload.results
    .map((item) => {
      const success = item.status === "success";
      const originalName = escapeHtml(item.original_name || "-");
      const finalName = escapeHtml(
        item.final_name || item.original_name || "-",
      );
      const server = escapeHtml(item.server || payload.server || "-");
      const url = item.url ? escapeHtml(item.url) : "";
      const terminal = escapeHtml(item.terminal_output || "");
      const message = escapeHtml(item.message || "");
      const sizeText = escapeHtml(item.size_human || "-");

      return `
            <div class="result-card ${success ? "success" : "error"}">
                <div class="result-top">
                    <div>
                        <div class="result-title">${originalName}</div>
                        <div class="result-meta">
                            Server: <strong>${server}</strong><br>
                            Ukuran: <strong>${sizeText}</strong><br>
                            Nama akhir: <strong>${finalName}</strong>
                            ${message ? `<br>Pesan: <strong>${message}</strong>` : ""}
                        </div>
                    </div>
                    <div class="status-badge ${success ? "success" : "error"}">
                        ${success ? "BERHASIL" : "GAGAL"}
                    </div>
                </div>

                ${
                  success && url
                    ? `
                    <div class="link-box">
                        <input class="link-input" type="text" readonly value="${url}">
                        <button type="button" class="btn btn-primary" onclick="copyToClipboard('${url}')">Copy Link</button>
                        <a class="btn btn-secondary" href="${url}" target="_blank" rel="noopener">Buka</a>
                    </div>
                `
                    : ""
                }

                <details class="log">
                    <summary>Lihat log terminal asli</summary>
                    <pre>${terminal || "(tidak ada log)"}</pre>
                </details>
            </div>
        `;
    })
    .join("");
}

dropzone.addEventListener("dragover", (e) => {
  e.preventDefault();
  dropzone.classList.add("active");
});

dropzone.addEventListener("dragleave", () => {
  dropzone.classList.remove("active");
});

dropzone.addEventListener("drop", (e) => {
  e.preventDefault();
  dropzone.classList.remove("active");
  addFiles(e.dataTransfer.files);
});

input.addEventListener("change", (e) => addFiles(e.target.files));
clearBtn.addEventListener("click", clearAll);

form.addEventListener("submit", function (e) {
  e.preventDefault();

  if (!selectedFiles.length) {
    showToast("Pilih file terlebih dahulu.");
    return;
  }

  const formData = new FormData();
  selectedFiles.forEach((file) => formData.append("files[]", file));
  formData.append("server", document.getElementById("server").value);

  const xhr = new XMLHttpRequest();
  xhr.open("POST", form.action, true);
  xhr.setRequestHeader("X-Requested-With", "XMLHttpRequest");
  xhr.responseType = "json";
  xhr.timeout = 0;

  uploadBtn.disabled = true;
  clearBtn.disabled = true;
  resultsEl.innerHTML = `<div class="empty">Upload sedang berjalan...</div>`;
  setProgress(0, `Mempersiapkan upload ${selectedFiles.length} file...`);

  xhr.upload.addEventListener("progress", function (event) {
    if (event.lengthComputable) {
      const percent = (event.loaded / event.total) * 100;
      setProgress(
        percent,
        `Mengunggah file ke server... ${formatBytes(event.loaded)} / ${formatBytes(event.total)}`,
      );
    }
  });

  xhr.upload.addEventListener("loadstart", function () {
    setProgress(0, "Upload dimulai...");
  });

  xhr.upload.addEventListener("load", function () {
    setProgress(
      100,
      "Transfer file selesai. Server sedang memproses upload remote...",
    );
  });

  xhr.addEventListener("load", function () {
    uploadBtn.disabled = false;
    clearBtn.disabled = false;

    if (xhr.status >= 200 && xhr.status < 300) {
      const payload = xhr.response;
      if (payload && payload.status === "ok") {
        setProgress(
          100,
          `Selesai. ${payload.summary.success} berhasil, ${payload.summary.error} gagal.`,
        );
        renderResults(payload);
      } else {
        setProgress(100, "Proses selesai, tetapi respons tidak valid.");
        resultsEl.innerHTML = `<div class="empty">Respons server tidak valid.</div>`;
      }
    } else {
      setProgress(100, `Server merespons HTTP ${xhr.status}.`);
      resultsEl.innerHTML = `<div class="empty">Server merespons HTTP ${xhr.status}.</div>`;
    }
  });

  xhr.addEventListener("error", function () {
    uploadBtn.disabled = false;
    clearBtn.disabled = false;
    setProgress(0, "Upload gagal karena gangguan jaringan.");
    resultsEl.innerHTML = `<div class="empty">Terjadi error jaringan saat upload.</div>`;
  });

  xhr.addEventListener("timeout", function () {
    uploadBtn.disabled = false;
    clearBtn.disabled = false;
    setProgress(0, "Upload timeout.");
    resultsEl.innerHTML = `<div class="empty">Upload timeout.</div>`;
  });

  xhr.send(formData);
});

renderFileList();
resetProgress();
