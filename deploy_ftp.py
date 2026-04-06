"""Deploy BF10 site via FTP. Uses Python ftplib (curl breaks with € in password)."""
import ftplib
import os
import sys

HOST = "51.77.134.32"
USER = "sacosbf10"
PASS = "$\u20acrv1saco2026"  # $€rv1saco2026
REMOTE_ROOT = "/public_html"
LOCAL_ROOT = os.path.dirname(os.path.abspath(__file__))

# Files/dirs to deploy (relative to LOCAL_ROOT)
TARGETS = sys.argv[1:] if len(sys.argv) > 1 else [
    "index.html",
    "js/pedido.js",
    "api/orders.php",
    "api/install.php",
    "api/migrate_nif.php",
]

SKIP_EXT = {".psd", ".log"}


def ensure_remote_dir(ftp, path):
    parts = [p for p in path.split("/") if p]
    current = ""
    for p in parts:
        current += "/" + p
        try:
            ftp.cwd(current)
        except ftplib.error_perm:
            try:
                ftp.mkd(current)
                print(f"  mkdir {current}")
            except ftplib.error_perm as e:
                print(f"  (dir exists or error) {current}: {e}")


def upload_file(ftp, local_path, remote_path):
    remote_dir = "/".join(remote_path.split("/")[:-1])
    ensure_remote_dir(ftp, remote_dir)
    with open(local_path, "rb") as f:
        ftp.storbinary(f"STOR {remote_path}", f)
    print(f"  UP {remote_path}")


def upload_target(ftp, target):
    local_path = os.path.join(LOCAL_ROOT, target.replace("/", os.sep))
    remote_path = f"{REMOTE_ROOT}/{target}".replace("\\", "/")

    if not os.path.exists(local_path):
        print(f"  MISSING {local_path}")
        return

    if os.path.isfile(local_path):
        upload_file(ftp, local_path, remote_path)
    else:
        for root, dirs, files in os.walk(local_path):
            for name in files:
                ext = os.path.splitext(name)[1].lower()
                if ext in SKIP_EXT:
                    continue
                lp = os.path.join(root, name)
                rel = os.path.relpath(lp, LOCAL_ROOT).replace("\\", "/")
                rp = f"{REMOTE_ROOT}/{rel}"
                upload_file(ftp, lp, rp)


def main():
    print(f"Connecting to {HOST}...")
    ftp = ftplib.FTP(HOST, timeout=60)
    ftp.login(USER, PASS)
    ftp.encoding = "utf-8"
    print(f"Connected. Deploying {len(TARGETS)} target(s):")
    for t in TARGETS:
        print(f"  - {t}")
    print()

    for t in TARGETS:
        upload_target(ftp, t)

    ftp.quit()
    print("\nDeploy complete.")


if __name__ == "__main__":
    main()
