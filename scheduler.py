import os
import sys
import time
import json
from datetime import datetime

# Import fungsi dari uploader.py milik Anda
import uploader
from curl_cffi import requests

def load_env(path='.env'):
    env = {}
    try:
        with open(path) as f:
            for line in f:
                if '=' in line and not line.strip().startswith('#'):
                    k, v = line.strip().split('=', 1)
                    env[k.strip()] = v.strip().strip('"\'')
    except: pass
    return env

def send_telegram(msg, bot_token, chat_id):
    if not bot_token or not chat_id: return
    url = f"https://api.telegram.org/bot{bot_token}/sendMessage"
    
    # Telegram punya batas 4096 karakter per bubble chat. 
    # Jika file sangat banyak, kita potong otomatis agar tidak error.
    while len(msg) > 0:
        chunk = msg[:4000]
        msg = msg[4000:]
        try:
            requests.post(url, json={"chat_id": chat_id, "text": chunk, "parse_mode": "HTML"}, impersonate="chrome")
        except Exception as e:
            print(f"Telegram error: {e}")

def process_queue():
    env = load_env()
    QUEUE_DIR = env.get("QUEUE_DIR", "queue_uploads")
    BOT_TOKEN = env.get("TELEGRAM_BOT_TOKEN", "")
    CHAT_ID = env.get("TELEGRAM_CHAT_ID", "")

    if not os.path.exists(QUEUE_DIR): 
        os.makedirs(QUEUE_DIR, exist_ok=True)
        
    # Ambil SEMUA file di antrean
    files = [f for f in os.listdir(QUEUE_DIR) if os.path.isfile(os.path.join(QUEUE_DIR, f))]
    
    if not files:
        return False, "Antrean kosong. Mesin mode hening (standby)..."

    print(f"[{datetime.now()}] 🚀 Memulai BATCH UPLOAD SERENTAK untuk {len(files)} file...")
    
    # Siapkan kerangka laporan untuk 1 bubble chat
    report = [
        "🔄 <b>LAPORAN AUTO-UPLOAD SERENTAK</b>",
        f"📅 Waktu: {datetime.now().strftime('%Y-%m-%d %H:%M')}",
        f"📁 Total File: <b>{len(files)}</b>\n"
    ]

    for target_file in files:
        file_path = os.path.join(QUEUE_DIR, target_file)
        print(f"\n---> Mengeksekusi File: {target_file}")
        
        report.append(f"📄 <b>{target_file}</b>")
        
        # --- 1. UPLOAD KE SFILE ---
        try:
            res_sfile = uploader.upload_to_sfile(file_path, target_file)
            if res_sfile.get("status") in ["success", "exists"]:
                url = res_sfile.get("url", "Tidak ada URL")
                report.append(f" ├ SFILE: ✅ <a href='{url}'>Sukses</a>")
            else:
                err = res_sfile.get("message", "Unknown error")
                report.append(f" ├ SFILE: ❌ Gagal ({err})")
        except Exception as e:
            report.append(f" ├ SFILE: ❌ Error Sistem")

        time.sleep(2) # Jeda napas mesin 2 detik

        # --- 2. UPLOAD KE SIMFILE ---
        try:
            res_simfile = uploader.upload_to_simfile(file_path, target_file)
            if res_simfile.get("status") in ["success", "exists"]:
                url = res_simfile.get("url", "Tidak ada URL")
                report.append(f" └ SIMFILE: ✅ <a href='{url}'>Sukses</a>\n")
            else:
                err = res_simfile.get("message", "Unknown error")
                report.append(f" └ SIMFILE: ❌ Gagal ({err})\n")
        except Exception as e:
            report.append(f" └ SIMFILE: ❌ Error Sistem\n")

    # Kirim 1 Bubble Chat Telegram berisi seluruh laporan
    final_message = "\n".join(report)
    send_telegram(final_message, BOT_TOKEN, CHAT_ID)

    print(f"[{datetime.now()}] ✅ BATCH SELESAI. Laporan terkirim ke Telegram.")
    return True, "Batch serentak selesai dan dilaporkan ke Telegram."

if __name__ == "__main__":
    # Fitur test manual via Web UI
    if len(sys.argv) > 1 and sys.argv[1] == "--once":
        success, msg = process_queue()
        print(json.dumps({"success": success, "message": msg}))
        sys.exit(0)
        
    print(f"🤖 Batch Scheduler aktif. Memantau pengaturan dari web UI...")
    
    last_run_time = 0
    
    while True:
        # Reload env setiap 5 detik agar tombol Stop/Start di Web langsung merespons
        env = load_env()
        status = env.get("SCHEDULE_STATUS", "running")
        interval_min = int(env.get("SCHEDULE_INTERVAL_MINUTES", "60"))
        interval_sec = interval_min * 60

        if status == "running":
            now = time.time()
            if now - last_run_time >= interval_sec:
                process_queue()
                last_run_time = time.time()  # Reset timer
                
        time.sleep(5)