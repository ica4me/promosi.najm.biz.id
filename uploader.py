import sys
import os
import re
import json
import hashlib
import shutil
from typing import Optional, Dict, Any
from urllib.parse import urljoin

from bs4 import BeautifulSoup
from curl_cffi import CurlMime
from curl_cffi import requests as c_requests


# =========================
# Utilitas umum
# =========================

def result_ok(server: str, url: Optional[str], final_name: str, extra: Optional[dict] = None) -> Dict[str, Any]:
    data = {
        "status": "success",
        "server": server,
        "url": url,
        "final_name": final_name,
    }
    if extra:
        data.update(extra)
    return data


def result_err(message: str, extra: Optional[dict] = None) -> Dict[str, Any]:
    data = {
        "status": "error",
        "message": message,
    }
    if extra:
        data.update(extra)
    return data


def safe_json(resp) -> Optional[dict]:
    try:
        return resp.json()
    except Exception:
        return None


def short_text(text: str, max_len: int = 300) -> str:
    text = text or ""
    text = text.replace("\r", "\\r").replace("\n", "\\n")
    return text[:max_len]


def get_md5(file_path: str) -> str:
    hash_md5 = hashlib.md5()
    with open(file_path, "rb") as f:
        for chunk in iter(lambda: f.read(1024 * 1024), b""):
            hash_md5.update(chunk)
    return hash_md5.hexdigest()


def sanitize_upload_name(name: str) -> str:
    """
    Pakai nama file asli tanpa path.
    Jaga agar nama tetap aman untuk dipakai sebagai filename upload.
    """
    base = os.path.basename(name or "").strip()
    if not base:
        return "file"

    base = base.replace("\x00", "")
    base = base.replace("/", "_").replace("\\", "_")

    if base in (".", ".."):
        return "file"

    return base


def detect_block_page(resp) -> bool:
    status = resp.status_code
    content_type = (resp.headers.get("content-type") or "").lower()
    text = (resp.text or "").lower()

    if status in (403, 429):
        return True

    if "text/html" not in content_type:
        return False

    cloudflare_markers = [
        "attention required",
        "just a moment",
        "checking your browser",
        "enable javascript and cookies",
        "cf-chl-",
        "challenge-platform",
        "/cdn-cgi/challenge-platform/",
        "why do i have to complete a captcha",
        "access denied",
        "error code 1020",
    ]

    return any(marker in text for marker in cloudflare_markers)


def response_debug(resp) -> dict:
    return {
        "http_status": resp.status_code,
        "content_type": resp.headers.get("content-type"),
        "server_header": resp.headers.get("server"),
        "cf_ray": resp.headers.get("cf-ray"),
        "body_preview": short_text(resp.text, 300),
    }


def build_browser_session(base_referer: str):
    session = c_requests.Session(impersonate="chrome")
    session.headers.update({
        "Accept": "*/*",
        "Referer": base_referer,
        "Origin": base_referer.rstrip("/"),
        "User-Agent": (
            "Mozilla/5.0 (Windows NT 10.0; Win64; x64) "
            "AppleWebKit/537.36 (KHTML, like Gecko) "
            "Chrome/136.0.0.0 Safari/537.36"
        ),
    })
    return session


# =========================
# SFILE
# =========================

def build_sfile_url_from_short(file_short: Optional[str]) -> Optional[str]:
    if not file_short:
        return None
    return f"https://sfile.co/{file_short}"


def make_sfile_multipart(flow_params: dict, file_path: str, filename_on_server: str) -> CurlMime:
    mp = CurlMime()
    for k, v in flow_params.items():
        mp.addpart(name=k, data=str(v).encode("utf-8"))

    mp.addpart(
        name="file",
        filename=filename_on_server,
        local_path=file_path,
        content_type="application/octet-stream",
    )
    return mp


def choose_sfile_upload_candidate(session, original_file_path: str, original_upload_name: Optional[str] = None) -> Dict[str, Any]:
    """
    Menentukan nama akhir yang akan dipakai TANPA PERNAH mengubah isi file.

    Aturan:
    - Jika file identik (duplicate_hash) sudah ada di server, jangan ubah file.
      Kembalikan URL file existing bila tersedia.
    - Jika hanya bentrok nama, ganti nama menjadi _v1, _v2, dst tanpa mengubah file.
    """
    original_base = sanitize_upload_name(original_upload_name or os.path.basename(original_file_path))
    name, ext = os.path.splitext(original_base)

    current_display_name = original_base
    current_file_path = original_file_path
    version = 1
    max_attempts = 50

    for _ in range(max_attempts):
        file_hash = get_md5(current_file_path)

        check_data = {
            "intent": "check-hash",
            "file_hash": file_hash,
            "file_name": current_display_name,
        }

        try:
            resp = session.post(
                "https://sfile.co/upload/resume_v1_guest.php",
                data=check_data,
                timeout=30,
            )
        except Exception as e:
            return result_err(f"Gagal check-hash Sfile: {str(e)}")

        if detect_block_page(resp):
            return result_err(
                "Request check-hash diblokir oleh server/WAF.",
                extra=response_debug(resp)
            )

        resp_json = safe_json(resp)
        txt = (resp.text or "").lower()

        duplicate_hash = False
        duplicate_name = False

        if isinstance(resp_json, dict):
            reason = str(resp_json.get("reason", "")).lower()
            message = str(resp_json.get("message", "")).lower()

            if reason == "duplicate_hash" or "duplicate_hash" in message or "already exists" in message:
                duplicate_hash = True

            if reason in ("duplicate_name", "file_name_exists", "name_exists"):
                duplicate_name = True
            elif "name already exists" in message or "filename already exists" in message:
                duplicate_name = True
        else:
            if "duplicate_hash" in txt:
                duplicate_hash = True
            if "name already exists" in txt or "filename already exists" in txt:
                duplicate_name = True

        # File identik sudah ada. Jangan ubah isi file.
        if duplicate_hash:
            existing_url = None
            existing_name = current_display_name

            if isinstance(resp_json, dict):
                existing_url = build_sfile_url_from_short(resp_json.get("file_short"))
                existing_name = resp_json.get("file_name") or current_display_name

            return {
                "status": "exists",
                "file_path": current_file_path,
                "final_name": existing_name,
                "file_hash": file_hash,
                "url": existing_url,
                "message": "File identik sudah ada di server. Isi file tidak diubah agar tetap asli.",
                "response_json": resp_json if isinstance(resp_json, dict) else None,
            }

        # Bentrok nama saja. Ganti nama, tapi file tetap asli.
        if duplicate_name:
            current_display_name = f"{name}_v{version}{ext}"
            version += 1
            continue

        if resp.status_code != 200:
            extra = response_debug(resp)
            if isinstance(resp_json, dict):
                extra["response_json"] = resp_json
            return result_err(
                f"Check-hash gagal. HTTP {resp.status_code}.",
                extra=extra
            )

        return {
            "status": "success",
            "file_path": current_file_path,
            "final_name": current_display_name,
            "file_hash": file_hash,
        }

    return result_err("Gagal menentukan kandidat upload Sfile setelah beberapa percobaan.")


def upload_to_sfile(file_path: str, original_upload_name: Optional[str] = None) -> Dict[str, Any]:
    session = build_browser_session("https://sfile.co/")

    try:
        home_resp = session.get("https://sfile.co/", timeout=30)
    except Exception as e:
        return result_err(f"Gagal inisiasi session Sfile: {str(e)}")

    if detect_block_page(home_resp):
        return result_err(
            "Halaman awal Sfile berisi challenge/block page.",
            extra=response_debug(home_resp)
        )

    picked = choose_sfile_upload_candidate(session, file_path, original_upload_name=original_upload_name)

    if picked.get("status") == "exists":
        existing_url = picked.get("url")
        if existing_url:
            return result_ok(
                server="sfile",
                url=existing_url,
                final_name=picked.get("final_name") or sanitize_upload_name(original_upload_name or os.path.basename(file_path)),
                extra={
                    "deduplicated": True,
                    "message": picked.get("message"),
                }
            )

        return result_err(
            "File identik sudah ada di server, tetapi URL existing tidak tersedia dari respons server.",
            extra={"response_json": picked.get("response_json")}
        )

    if picked.get("status") != "success":
        return picked

    current_file_path = picked["file_path"]
    current_name = picked["final_name"]
    file_hash = picked["file_hash"]

    file_size = os.path.getsize(current_file_path)
    clean_name = re.sub(r"[^a-zA-Z0-9]", "", current_name) or "file"
    flow_identifier = f"{file_size}-{clean_name}"

    flow_params = {
        "des": "",
        "file_hash": file_hash,
        "desired_name": current_name,
        "flowChunkNumber": "1",
        "flowChunkSize": "1048576000",
        "flowCurrentChunkSize": str(file_size),
        "flowTotalSize": str(file_size),
        "flowIdentifier": flow_identifier,
        "flowFilename": current_name,
        "flowRelativePath": current_name,
        "flowTotalChunks": "1",
    }

    try:
        session.get(
            "https://sfile.co/upload/resume_v1_guest.php",
            params=flow_params,
            timeout=30,
        )
    except Exception:
        pass

    mp = make_sfile_multipart(flow_params, current_file_path, current_name)
    try:
        upload_resp = session.post(
            "https://sfile.co/upload/resume_v1_guest.php",
            multipart=mp,
            timeout=120,
        )
    finally:
        mp.close()

    if detect_block_page(upload_resp):
        return result_err(
            "Upload Sfile diblokir oleh server/WAF.",
            extra=response_debug(upload_resp)
        )

    if upload_resp.status_code != 200:
        return result_err(
            f"Upload Sfile gagal. HTTP {upload_resp.status_code}.",
            extra=response_debug(upload_resp)
        )

    res_json = safe_json(upload_resp)
    if not res_json:
        return result_err(
            "Upload Sfile tidak mengembalikan JSON yang valid.",
            extra=response_debug(upload_resp)
        )

    if res_json.get("status") == "success":
        url = (
            res_json.get("share_url")
            or res_json.get("file", {}).get("download_url")
            or res_json.get("url")
        )
        if not url:
            return result_err(
                "Upload Sfile sukses parsial, tetapi URL file tidak ditemukan.",
                extra={"response_json": res_json}
            )

        return result_ok(
            server="sfile",
            url=url,
            final_name=current_name,
            extra={"http_status": upload_resp.status_code}
        )

    return result_err(
        "Upload Sfile gagal menurut respons server.",
        extra={"response_json": res_json}
    )


# =========================
# SIMFILE
# =========================

def extract_simfile_url_from_message(message: str) -> Optional[str]:
    if not message:
        return None

    normalized = message.replace("\\/", "/")

    m = re.search(r"""value=['"](https?://simfile\.co/[^'"]+)['"]""", normalized, re.I)
    if m:
        return m.group(1)

    m = re.search(r"""href=['"]([^'"]+)['"]""", normalized, re.I)
    if m:
        href = m.group(1).strip()
        if href:
            return urljoin("https://simfile.co/", href)

    m = re.search(r"""https?://simfile\.co/[^\s'"<>]+""", normalized, re.I)
    if m:
        return m.group(0)

    try:
        soup = BeautifulSoup(normalized, "html.parser")

        inp = soup.find("input", attrs={"name": "upload_link"})
        if inp and inp.get("value"):
            return inp.get("value").strip()

        a = soup.find("a", href=True)
        if a and a.get("href"):
            return urljoin("https://simfile.co/", a["href"].strip())
    except Exception:
        pass

    return None


def upload_to_simfile(file_path: str, original_upload_name: Optional[str] = None) -> Dict[str, Any]:
    session = build_browser_session("https://simfile.co/")

    try:
        home_resp = session.get("https://simfile.co/", timeout=30)
    except Exception as e:
        return result_err(f"Gagal akses Simfile: {str(e)}")

    if detect_block_page(home_resp):
        return result_err(
            "Halaman awal Simfile berisi challenge/block page.",
            extra=response_debug(home_resp)
        )

    soup = BeautifulSoup(home_resp.text, "html.parser")
    token_input = soup.find("input", {"name": "_token"})
    if not token_input or not token_input.get("value"):
        return result_err(
            "Token CSRF Simfile tidak ditemukan.",
            extra={"body_preview": short_text(home_resp.text, 500)}
        )

    csrf_token = token_input["value"]
    base_name = sanitize_upload_name(original_upload_name or os.path.basename(file_path))
    name_part, ext_part = os.path.splitext(base_name)

    current_name = base_name
    version = 1
    max_attempts = 15

    for attempt in range(max_attempts):
        temp_dir = os.path.dirname(file_path)
        
        # Buat folder sementara yang unik, agar nama file di dalamnya tetap murni
        unique_subdir = os.path.join(temp_dir, f"simfile_tmp_{os.urandom(4).hex()}")
        os.makedirs(unique_subdir, exist_ok=True)
        
        # File di disk sekarang memiliki nama murni sesuai current_name (contoh: test.hc atau test_v1.hc)
        target_temp_path = os.path.join(unique_subdir, current_name)

        try:
            try:
                os.link(file_path, target_temp_path)
            except Exception:
                shutil.copy2(file_path, target_temp_path)

            mp = CurlMime()
            mp.addpart(name="_token", data=csrf_token.encode("utf-8"))
            mp.addpart(name="description", data=b"")
            mp.addpart(name="filePassword", data=b"")
            mp.addpart(name="submit", data=b"")
            mp.addpart(
                name="media",
                filename=current_name,
                local_path=target_temp_path,
                content_type="application/octet-stream",
            )

            try:
                upload_resp = session.post(
                    "https://simfile.co/upload/",
                    multipart=mp,
                    timeout=120,
                )
            finally:
                mp.close()
        finally:
            # Bersihkan file dan folder sementara yang unik
            if os.path.exists(target_temp_path):
                try:
                    os.remove(target_temp_path)
                except Exception:
                    pass
            if os.path.exists(unique_subdir):
                try:
                    os.rmdir(unique_subdir)
                except Exception:
                    pass

        if detect_block_page(upload_resp):
            return result_err(
                "Upload Simfile diblokir oleh server/WAF.",
                extra=response_debug(upload_resp)
            )

        # Deteksi duplikat nama pada response (JSON / teks mentah)
        res_json = safe_json(upload_resp)
        txt_resp = (upload_resp.text or "").lower()

        is_duplicate = False
        if res_json and isinstance(res_json, dict):
            msg = str(res_json.get("message", "")).lower()
            if "already exist" in msg or "already exists" in msg or "duplicate" in msg or "already been uploaded" in msg:
                is_duplicate = True
        else:
            if "already exist" in txt_resp or "already exists" in txt_resp or "duplicate" in txt_resp or "already been uploaded" in txt_resp:
                is_duplicate = True

        # Jika duplikat, buat versi baru (_v1, _v2, dst.) dan ulangi loop
        if is_duplicate:
            current_name = f"{name_part}_v{version}{ext_part}"
            version += 1
            continue

        if upload_resp.status_code != 200:
            return result_err(
                f"Upload Simfile gagal. HTTP {upload_resp.status_code}.",
                extra=response_debug(upload_resp)
            )

        if not res_json:
            return result_err(
                "Upload Simfile tidak mengembalikan JSON yang valid.",
                extra=response_debug(upload_resp)
            )

        message = str(res_json.get("message", ""))
        extracted_url = extract_simfile_url_from_message(message)

        if extracted_url:
            return result_ok(
                server="simfile",
                url=extracted_url,
                final_name=current_name,
                extra={"http_status": upload_resp.status_code}
            )

        possible_url = (
            res_json.get("url")
            or res_json.get("download_url")
            or res_json.get("share_url")
        )
        if possible_url:
            return result_ok(
                server="simfile",
                url=possible_url,
                final_name=current_name,
                extra={"http_status": upload_resp.status_code}
            )

        return result_err(
            "Gagal menemukan URL file di respons Simfile.",
            extra={"response_json": res_json}
        )

    return result_err(f"Gagal upload ke Simfile setelah {max_attempts} percobaan karena duplikasi nama.")


# =========================
# MAIN
# =========================

def main():
    if len(sys.argv) < 3:
        print(json.dumps(
            result_err("Argumen kurang. Gunakan: python uploader.py <file_path> <sfile|simfile> [original_name]"),
            ensure_ascii=False
        ))
        sys.exit(1)

    file_path = sys.argv[1]
    target_server = sys.argv[2].strip().lower()
    original_name = sys.argv[3] if len(sys.argv) >= 4 else None

    if not os.path.exists(file_path):
        print(json.dumps(result_err("File tidak ditemukan."), ensure_ascii=False))
        sys.exit(1)

    if not os.path.isfile(file_path):
        print(json.dumps(result_err("Path yang diberikan bukan file."), ensure_ascii=False))
        sys.exit(1)

    try:
        if target_server == "sfile":
            result = upload_to_sfile(file_path, original_upload_name=original_name)
        elif target_server == "simfile":
            result = upload_to_simfile(file_path, original_upload_name=original_name)
        else:
            result = result_err("Server tidak valid. Gunakan 'sfile' atau 'simfile'.")
    except KeyboardInterrupt:
        result = result_err("Dibatalkan oleh pengguna.")
    except Exception as e:
        result = result_err(f"Unexpected error: {str(e)}")

    print(json.dumps(result, ensure_ascii=False))


if __name__ == "__main__":
    main()
