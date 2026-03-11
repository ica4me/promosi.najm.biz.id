# Web Bantu Upload script Najm

# Auto Uploader Sfile & Simfile (Docker Version)

Aplikasi promosi-najm pintar yang mengkombinasikan PHP (Web UI) dan Python (`curl_cffi`) untuk mengunggah file berukuran besar (hingga 512MB) ke Sfile dan Simfile. Dilengkapi dengan **Bot Scheduler** di belakang layar untuk _looping_ otomatis dan notifikasi ke Telegram.

Semua komponen (Nginx, PHP-FPM, Python, Scheduler) sudah dibungkus rapi dalam **Docker Container** sehingga sangat mudah di-_deploy_ di VPS Debian/Ubuntu manapun.

---

## 🚀 1. Persiapan Server (Root Access)

Jalankan perintah di bawah ini secara berurutan di terminal VPS Anda untuk memastikan koneksi server lancar, mengupdate sistem, dan menginstal Docker.

### Fix DNS & Update Sistem

Agar server tidak mengalami _timeout_ saat mengunduh dari repositori atau Docker Hub:

```bash
echo "nameserver 8.8.8.8" > /etc/resolv.conf
echo "nameserver 1.1.1.1" >> /etc/resolv.conf

apt-get update && apt-get install curl git -y
```

### Install Docker & Konfigurasi Daemon

```bash
# Download dan eksekusi script instalasi Docker
curl -fsSL https://get.docker.com -o get-docker.sh
sh get-docker.sh

# Paksa Docker menggunakan DNS Google/Cloudflare agar proses build lancar
echo '{ "dns": ["8.8.8.8", "1.1.1.1"] }' > /etc/docker/daemon.json

# Terapkan perubahan dan restart Docker
systemctl restart docker
```

### Download Project & Setup

```bash
# Clone repository ke dalam folder promosi-najm
git clone https://github.com/ica4me/promosi.najm.biz.id.git promosi-najm

# Masuk ke folder proyek
cd promosi-najm

# Gandakan file example.env menjadi .env
cp example.env .env
```

### Menjalankan Aplikasi (Run / Start)

```bash
docker compose up -d --build

# Melihat log web/PHP
docker compose logs app -f

# Melihat log bot scheduler otomatis
docker compose logs scheduler -f

docker compose stop

# Mematikan dan menghapus container beserta volumenya
docker compose down -v

# Menghapus image yang dibuat untuk proyek ini (opsional untuk clean-up total)
docker compose down --rmi all
```
