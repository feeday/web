import os
import time
import shutil
import zipfile
import subprocess
import urllib.request
from concurrent.futures import ThreadPoolExecutor, as_completed

# =========================
# 配置
# =========================
input_dir = r"E:\2016"
output_dir = r"E:\2016o"
output_file = "output.mp3"

video_exts = (".mp4", ".mkv", ".mov", ".avi", ".ts", ".flv", ".m4v", ".webm")
os.makedirs(output_dir, exist_ok=True)

# =========================
# FFmpeg 自动检测/下载
# =========================
ffmpeg_root = os.path.join(os.getcwd(), "ffmpeg_bin")
ffmpeg_exe = None
ffprobe_exe = None


def find_local_ffmpeg():
    global ffmpeg_exe, ffprobe_exe

    ffmpeg_exe = shutil.which("ffmpeg")
    ffprobe_exe = shutil.which("ffprobe")

    if ffmpeg_exe and ffprobe_exe:
        return True

    if os.path.exists(ffmpeg_root):
        for root, _, files in os.walk(ffmpeg_root):
            for f in files:
                if f == "ffmpeg.exe":
                    ffmpeg_exe = os.path.join(root, f)
                if f == "ffprobe.exe":
                    ffprobe_exe = os.path.join(root, f)

    return ffmpeg_exe is not None


def download_file(url, path):
    print(f"📥 下载：{url}")
    try:
        urllib.request.urlretrieve(url, path)
        return True
    except:
        return False


def download_ffmpeg():
    print("⚠️ 未检测到 FFmpeg，开始下载...")

    os.makedirs(ffmpeg_root, exist_ok=True)
    zip_path = os.path.join(ffmpeg_root, "ffmpeg.zip")

    urls = [
        "https://ghproxy.com/https://github.com/BtbN/FFmpeg-Builds/releases/download/latest/ffmpeg-master-latest-win64-gpl.zip",
        "https://download.fastgit.org/BtbN/FFmpeg-Builds/releases/download/latest/ffmpeg-master-latest-win64-gpl.zip",
        "https://github.com/BtbN/FFmpeg-Builds/releases/download/latest/ffmpeg-master-latest-win64-gpl.zip"
    ]

    for url in urls:
        if download_file(url, zip_path):
            break
    else:
        raise Exception("FFmpeg 下载失败")

    with zipfile.ZipFile(zip_path, "r") as z:
        z.extractall(ffmpeg_root)

    os.remove(zip_path)


if not find_local_ffmpeg():
    download_ffmpeg()
    find_local_ffmpeg()

print("✅ FFmpeg:", ffmpeg_exe)
print("✅ FFprobe:", ffprobe_exe)

# =========================
# 视频扫描
# =========================
def collect_videos(path):
    files = []
    for root, _, fs in os.walk(path):
        for f in fs:
            if f.lower().endswith(video_exts):
                files.append(os.path.join(root, f))
    return files


videos = collect_videos(input_dir)
videos.sort()

if not videos:
    raise Exception("未找到视频")

print(f"🎬 视频数量: {len(videos)}")

# =========================
# concat list（修复Windows路径问题）
# =========================
list_file = os.path.join(output_dir, "list.txt")

with open(list_file, "w", encoding="utf-8") as f:
    for v in videos:
        f.write(f"file '{v.replace('\\', '/')}'\n")

output_path = os.path.join(output_dir, output_file)

# =========================
# 🚀 并行 ffprobe（真正吃CPU）
# =========================
def get_duration(file):
    cmd = [
        ffprobe_exe,
        "-v", "error",
        "-show_entries", "format=duration",
        "-of", "default=noprint_wrappers=1:nokey=1",
        file
    ]
    try:
        p = subprocess.run(cmd, capture_output=True, text=True)
        return float(p.stdout.strip())
    except:
        return 0


print("⏳ 并行计算时长...")

with ThreadPoolExecutor(max_workers=os.cpu_count() * 2) as ex:
    durations = list(ex.map(get_duration, videos))

total_duration = sum(durations)

print(f"📊 总时长: {total_duration:.2f}s")

# =========================
# 🚀 FFmpeg 真正优化参数
# =========================
cmd = [
    ffmpeg_exe,
    "-y",

    # 🔥 多线程解码（关键）
    "-threads", "0",

    "-f", "concat",
    "-safe", "0",
    "-i", list_file,

    "-vn",

    # 🔥 音频编码优化（真正提速点）
    "-acodec", "libmp3lame",
    "-preset", "fast",
    "-q:a", "2",

    "-progress", "pipe:1",
    output_path
]

print("🚀 开始转换...")

process = subprocess.Popen(
    cmd,
    stdout=subprocess.PIPE,
    stderr=subprocess.STDOUT,
    text=True
)

start = time.time()
current = 0

# =========================
# 进度解析
# =========================
while True:
    line = process.stdout.readline()
    if not line:
        break

    line = line.strip()

    if "out_time_ms" in line:
        try:
            current = int(line.split("=")[1]) / 1_000_000
        except:
            pass

    if total_duration > 0:
        percent = current / total_duration * 100
        speed = current / (time.time() - start + 0.001)
        eta = (total_duration - current) / (speed + 0.001)

        print(
            f"\r📊 {percent:6.2f}% | {current:.1f}s / {total_duration:.1f}s | ETA {eta:.1f}s",
            end=""
        )

process.wait()

print("\n\n✅ 完成输出:", output_path)
