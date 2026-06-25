#!/usr/bin/env python3
"""
把指定文件夹下的文件逐个压缩成独立压缩包，并把压缩包移动到输出目录。

特点：
- 原始文件/原始文件夹不删除、不移动、不改名。
- 每个文件生成一个 .zip 包，例如 a.mp4 -> a.zip。
- 支持递归扫描子目录，并在输出目录保留相对目录结构，避免重名覆盖。
- 多线程并发压缩，适合大量文件；每个压缩包先写到临时目录，成功后再原子移动到输出目录。

使用方法：
1. 直接修改下面的 SOURCE_DIR 和 OUT_DIR，然后运行：python 批量单文件压缩移动.py
2. 也可以临时从命令行覆盖目录：python 批量单文件压缩移动.py "G:\\Submit" "G:\\ZipOut"
"""

from __future__ import annotations

import argparse
import os
import shutil
import tempfile
import time
import zipfile
from concurrent.futures import ThreadPoolExecutor, as_completed
from pathlib import Path


# ===== 直接在这里写目录和默认参数 =====
# 源目录：只读取里面的文件，原始文件/文件夹不会被移动或删除。
SOURCE_DIR = r"G:\\Submit"
# 输出目录：每个文件压缩后的 zip 包会移动到这里。
OUT_DIR = r"G:\\ZipOut"
# True=递归处理子文件夹；False=只处理 SOURCE_DIR 第一层文件。
RECURSIVE = True
# deflate=压缩体积更小；store=只打包不压缩，速度最快。
METHOD = "deflate"
# False=遇到同名 zip 自动追加 _1、_2；True=覆盖已有同名 zip。
OVERWRITE = False
# 线程数越大不一定越快，机械硬盘建议 4-8，SSD 可适当调高。
DEFAULT_WORKERS = min(32, (os.cpu_count() or 4) * 2)


class CompressError(RuntimeError):
    """单个文件压缩失败。"""


def iter_files(source_dir: Path, recursive: bool):
    pattern = "**/*" if recursive else "*"
    for path in source_dir.glob(pattern):
        if path.is_file():
            yield path


def unique_zip_path(out_dir: Path, relative_file: Path, overwrite: bool) -> Path:
    """为文件生成压缩包路径；默认不覆盖已有文件。"""
    target = out_dir / relative_file.with_suffix(".zip")
    if overwrite or not target.exists():
        return target

    stem = target.stem
    suffix = target.suffix
    parent = target.parent
    index = 1
    while True:
        candidate = parent / f"{stem}_{index}{suffix}"
        if not candidate.exists():
            return candidate
        index += 1


def compress_one_file(source_file: Path, source_dir: Path, out_dir: Path, method: int, overwrite: bool) -> tuple[Path, Path, int]:
    """压缩单个文件，返回：原文件、压缩包路径、原文件大小。"""
    relative_file = source_file.relative_to(source_dir)
    final_zip = unique_zip_path(out_dir, relative_file, overwrite)
    final_zip.parent.mkdir(parents=True, exist_ok=True)

    fd, temp_name = tempfile.mkstemp(prefix=f".{final_zip.stem}.", suffix=".tmp", dir=final_zip.parent)
    os.close(fd)
    temp_zip = Path(temp_name)

    try:
        with zipfile.ZipFile(temp_zip, "w", compression=method, compresslevel=6 if method == zipfile.ZIP_DEFLATED else None) as zf:
            # arcname 只放文件名，解压后不会带上源目录路径。
            zf.write(source_file, arcname=source_file.name)
        if overwrite and final_zip.exists():
            final_zip.unlink()
        shutil.move(str(temp_zip), str(final_zip))
        return source_file, final_zip, source_file.stat().st_size
    except Exception as exc:  # noqa: BLE001 - 命令行工具需要把失败文件继续汇总出来
        temp_zip.unlink(missing_ok=True)
        raise CompressError(f"{source_file} -> {final_zip} 失败：{exc}") from exc


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description="指定文件夹下文件逐个压缩成 zip 包，然后移动到输出目录；原始文件夹不动。")
    parser.add_argument("source_dir", nargs="?", type=Path, default=Path(SOURCE_DIR), help="要扫描的源文件夹；不填则使用 py 文件里的 SOURCE_DIR")
    parser.add_argument("out_dir", nargs="?", type=Path, default=Path(OUT_DIR), help="压缩包输出/移动到的目标文件夹；不填则使用 py 文件里的 OUT_DIR")
    parser.add_argument("--recursive", "-r", action="store_true", default=RECURSIVE, help=f"递归处理子文件夹，默认 {RECURSIVE}")
    parser.add_argument("--workers", "-w", type=int, default=DEFAULT_WORKERS, help=f"并发线程数，默认 {DEFAULT_WORKERS}")
    parser.add_argument(
        "--method",
        choices=("deflate", "store"),
        default=METHOD,
        help="deflate=正常压缩体积更小；store=只打包不压缩，速度最快但体积基本不变",
    )
    parser.add_argument("--overwrite", action="store_true", default=OVERWRITE, help=f"允许覆盖目标目录中已有同名 zip 包，默认 {OVERWRITE}")
    return parser.parse_args()


def main() -> int:
    args = parse_args()
    source_dir = args.source_dir.expanduser().resolve()
    out_dir = args.out_dir.expanduser().resolve()

    if not source_dir.is_dir():
        print(f"源文件夹不存在：{source_dir}")
        return 2
    if source_dir == out_dir or out_dir.is_relative_to(source_dir):
        print("输出目录不能是源目录本身，也不能放在源目录里面；这样可以避免重复扫描压缩包。")
        return 2
    if args.workers < 1:
        print("--workers 必须大于等于 1")
        return 2

    out_dir.mkdir(parents=True, exist_ok=True)
    files = list(iter_files(source_dir, args.recursive))
    if not files:
        print("未找到需要压缩的文件。")
        return 0

    method = zipfile.ZIP_STORED if args.method == "store" else zipfile.ZIP_DEFLATED
    start = time.time()
    ok_count = 0
    fail_count = 0
    total_bytes = 0

    print(f"源目录：{source_dir}")
    print(f"输出目录：{out_dir}")
    print(f"文件数量：{len(files)}，线程数：{args.workers}，模式：{args.method}")

    with ThreadPoolExecutor(max_workers=args.workers) as executor:
        futures = [executor.submit(compress_one_file, file, source_dir, out_dir, method, args.overwrite) for file in files]
        for future in as_completed(futures):
            try:
                source_file, final_zip, size = future.result()
                ok_count += 1
                total_bytes += size
                print(f"[{ok_count}/{len(files)}] OK {source_file} -> {final_zip}")
            except CompressError as exc:
                fail_count += 1
                print(f"FAIL {exc}")

    elapsed = time.time() - start
    speed = total_bytes / 1024 / 1024 / elapsed if elapsed > 0 else 0
    print(f"完成：成功 {ok_count}，失败 {fail_count}，耗时 {elapsed:.2f}s，读取速度约 {speed:.2f} MiB/s")
    return 1 if fail_count else 0


if __name__ == "__main__":
    raise SystemExit(main())
