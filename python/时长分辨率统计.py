import os
import re
import csv
import json
import subprocess
from pathlib import Path
from concurrent.futures import ThreadPoolExecutor, as_completed
from PIL import Image
from tqdm import tqdm

ROOT = r"G:\Submit"
OUT_CSV = r"G:\Submit\sf3.csv"

MAX_WORKERS = min(32, (os.cpu_count() or 4) * 4)

VIDEO_EXT = {
    ".mp4", ".mov", ".mkv", ".avi", ".flv", ".wmv", ".webm", ".m4v",
    ".ts", ".mts", ".m2ts", ".3gp"
}

AUDIO_EXT = {
    ".mp3", ".wav", ".flac", ".aac", ".m4a", ".ogg", ".wma", ".opus", ".aiff"
}

IMAGE_EXT = {
    ".jpg", ".jpeg", ".png", ".webp", ".bmp", ".gif", ".tif", ".tiff", ".heic"
}

# 允许：中文、英文、数字、下划线、短横线、点
SAFE_NAME_RE = re.compile(r"^[\u4e00-\u9fa5A-Za-z0-9._-]+$")


def sec_to_hms(sec):
    if sec is None:
        return ""
    sec = int(round(sec))
    h = sec // 3600
    m = sec % 3600 // 60
    s = sec % 60
    return f"{h:02d}:{m:02d}:{s:02d}"


def delete_db_files_recursively(target_directory):
    base_path = Path(target_directory)

    if not base_path.exists():
        print(f"目录不存在：{base_path}")
        return

    db_files = [
        p for p in base_path.rglob("*")
        if p.is_file() and p.suffix.lower() == ".db"
    ]

    if not db_files:
        print("未找到 .db 文件。")
        return

    print(f"找到 {len(db_files)} 个 .db 文件，准备删除...")

    for db_file in db_files:
        try:
            db_file.unlink()
            print(f"已删除: {db_file}")
        except PermissionError:
            print(f"无法删除，被占用或无权限: {db_file}")
        except Exception as e:
            print(f"删除失败: {db_file}，原因：{e}")


def check_single_name(name, label):
    """
    检查单个文件名或文件夹名
    """
    notes = []

    if re.search(r"\s", name):
        notes.append(f"{label}含空格")

    if not SAFE_NAME_RE.match(name):
        notes.append(f"{label}含特殊符号")

    return notes


def check_path_remark(path: Path):
    """
    检查文件名和文件夹名
    """
    notes = []

    # 检查文件名
    notes.extend(check_single_name(path.name, "文件名"))

    # 检查文件夹目录中的每一级文件夹名
    for part in path.parent.parts:
        # 跳过盘符，例如 G:\
        if part == path.anchor:
            continue

        part_notes = check_single_name(part, "文件夹名")
        if part_notes:
            notes.extend([f"{x}({part})" for x in part_notes])

    # 去重，但保持顺序
    result = []
    for n in notes:
        if n not in result:
            result.append(n)

    return "；".join(result)


def scan_files(root):
    root = Path(root)

    for dirpath, _, filenames in os.walk(root):
        for name in filenames:
            p = Path(dirpath) / name
            ext = p.suffix.lower()

            if ext in VIDEO_EXT or ext in AUDIO_EXT or ext in IMAGE_EXT:
                yield p


def ffprobe_media(path: Path):
    remark = check_path_remark(path)

    cmd = [
        "ffprobe",
        "-v", "error",
        "-print_format", "json",
        "-show_entries",
        "format=duration:stream=codec_type,width,height",
        str(path)
    ]

    try:
        r = subprocess.run(
            cmd,
            stdout=subprocess.PIPE,
            stderr=subprocess.PIPE,
            text=True,
            timeout=30
        )
        r.check_returncode()

        data = json.loads(r.stdout)

        duration = data.get("format", {}).get("duration")
        duration = float(duration) if duration else None

        media_type = "音频"
        resolution = ""

        for s in data.get("streams", []):
            if s.get("codec_type") == "video":
                media_type = "视频"
                w = s.get("width")
                h = s.get("height")
                if w and h:
                    resolution = f"{w}x{h}"
                break

        return {
            "文件夹目录": str(path.parent),
            "文件名": path.name,
            "类型": media_type,
            "时长": sec_to_hms(duration),
            "分辨率": resolution,
            "备注": remark
        }

    except Exception as e:
        error_msg = f"媒体读取失败：{e}"
        remark = f"{remark}；{error_msg}" if remark else error_msg

        return {
            "文件夹目录": str(path.parent),
            "文件名": path.name,
            "类型": "媒体读取失败",
            "时长": "",
            "分辨率": "",
            "备注": remark
        }


def image_info(path: Path):
    remark = check_path_remark(path)

    try:
        with Image.open(path) as img:
            w, h = img.size

        return {
            "文件夹目录": str(path.parent),
            "文件名": path.name,
            "类型": "图片",
            "时长": "",
            "分辨率": f"{w}x{h}",
            "备注": remark
        }

    except Exception as e:
        error_msg = f"图片读取失败：{e}"
        remark = f"{remark}；{error_msg}" if remark else error_msg

        return {
            "文件夹目录": str(path.parent),
            "文件名": path.name,
            "类型": "图片读取失败",
            "时长": "",
            "分辨率": "",
            "备注": remark
        }


def handle_file(path: Path):
    ext = path.suffix.lower()

    if ext in IMAGE_EXT:
        return image_info(path)

    if ext in VIDEO_EXT or ext in AUDIO_EXT:
        return ffprobe_media(path)

    return None


def main():
    print("开始删除 .db 文件...")
    delete_db_files_recursively(ROOT)

    print("\n开始扫描媒体文件...")
    files = list(scan_files(ROOT))
    print(f"找到媒体文件：{len(files)} 个")

    rows = []

    with ThreadPoolExecutor(max_workers=MAX_WORKERS) as pool:
        futures = [pool.submit(handle_file, p) for p in files]

        for f in tqdm(as_completed(futures), total=len(futures)):
            row = f.result()
            if row:
                rows.append(row)

    rows.sort(key=lambda x: (x["文件夹目录"], x["文件名"]))

    with open(OUT_CSV, "w", newline="", encoding="utf-8-sig") as f:
        writer = csv.DictWriter(
            f,
            fieldnames=[
                "文件夹目录",
                "文件名",
                "类型",
                "时长",
                "分辨率",
                "备注"
            ]
        )
        writer.writeheader()
        writer.writerows(rows)

    print(f"\n完成，已输出：{OUT_CSV}")


if __name__ == "__main__":
    main()
