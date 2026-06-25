#!/usr/bin/env python3
"""
把指定文件夹下的文件逐个压缩成独立压缩包，并把压缩包移动到输出目录。

特点：
- 原始文件/原始文件夹不删除、不移动、不改名。
- 每个文件生成一个 .zip 包，例如 a.mp4 -> a.zip。
- 支持递归扫描子目录，并在输出目录保留相对目录结构，避免重名覆盖。
- 多线程并发压缩，适合大量文件；每个压缩包先写到临时目录，成功后再原子移动到输出目录。
- 自带 tqdm 进度条；如果本机没装 tqdm，会自动用国内 pip 源安装。
- 指定文件夹下如果有子文件夹，会把子文件夹整体压缩成一个 zip，不再拆开压缩子文件夹里的每个文件。
- 完成后导出 CSV 表格，记录每个文件/文件夹压缩成功或失败；也支持读取 txt 清单逐行压缩指定路径。

使用方法：
1. 直接修改下面的 SOURCE_DIR 和 OUT_DIR，然后运行：python 批量压缩移动.py
2. 也可以临时从命令行覆盖目录：python 批量压缩移动.py "G:\\Submit" "G:\\ZipOut"
"""

from __future__ import annotations

import argparse
import csv
import importlib.util
import os
import shutil
import subprocess
import sys
import tempfile
import time
import zipfile
from datetime import datetime
from concurrent.futures import ThreadPoolExecutor, as_completed
from pathlib import Path


def ensure_pip_package(module_name: str, package_name: str | None = None) -> bool:
    """缺少依赖时自动用国内 pip 源安装；安装失败则返回 False。"""
    if importlib.util.find_spec(module_name) is not None:
        return True

    package = package_name or module_name
    print(f"缺少依赖 {package}，正在使用清华 pip 源自动安装...")
    result = subprocess.run([
        sys.executable,
        "-m",
        "pip",
        "install",
        "-i",
        "https://pypi.tuna.tsinghua.edu.cn/simple",
        "--trusted-host",
        "pypi.tuna.tsinghua.edu.cn",
        package,
    ], check=False)
    return result.returncode == 0 and importlib.util.find_spec(module_name) is not None


if ensure_pip_package("tqdm"):
    from tqdm import tqdm
else:
    class tqdm:  # noqa: N801 - 兼容 tqdm 的调用方式
        """tqdm 安装失败时的简易进度条兜底。"""

        def __init__(self, iterable=None, total: int = 0, unit: str = "", desc: str = "", **_: object):
            self.iterable = iterable
            self.total = total
            self.unit = unit
            self.desc = desc
            self.count = 0
            self.ok = 0
            self.fail = 0
            self.start = time.time()

        def __enter__(self):
            return self

        def __exit__(self, exc_type, exc, tb):
            print()
            return False

        def __iter__(self):
            for item in self.iterable:
                self.update(1)
                yield item

        def update(self, n: int) -> None:
            self.count += n
            elapsed = max(time.time() - self.start, 0.001)
            mb_done = self.count / 1024 / 1024
            mb_total = self.total / 1024 / 1024 if self.total else 0
            mbps = mb_done / elapsed
            percent = self.count / self.total * 100 if self.total else 0
            print(f"\r{self.desc}: {percent:6.2f}% {mb_done:.2f}/{mb_total:.2f} MB {mbps:.2f} MB/s ok={self.ok} fail={self.fail}", end="", flush=True)

        def set_postfix(self, ok: int, fail: int, refresh: bool = False) -> None:
            self.ok = ok
            self.fail = fail

        @staticmethod
        def write(message: str) -> None:
            print(f"\n{message}")


# ===== 直接在这里写目录和默认参数 =====
# 源目录：只读取里面的文件，原始文件/文件夹不会被移动或删除。
SOURCE_DIR = r"G:\\Submit"
# 输出目录：每个文件压缩后的 zip 包会移动到这里。
OUT_DIR = r"G:\\ZipOut"
# True=压缩文件夹时包含所有子目录内容；False=只压缩文件夹第一层文件。
RECURSIVE = True
# deflate=压缩体积更小；store=只打包不压缩，速度最快。
METHOD = "deflate"
# False=遇到同名 zip 自动追加 _1、_2；True=覆盖已有同名 zip。
OVERWRITE = False
# 可选：txt 文件清单路径；每行一个文件或文件夹路径，留空则扫描 SOURCE_DIR。
LIST_TXT = r""
# 可选：结果表格路径；留空则自动输出到 OUT_DIR/压缩结果.csv。
REPORT_CSV = r""
# 线程数越大不一定越快，机械硬盘建议 4-8，SSD 可适当调高。
DEFAULT_WORKERS = min(32, (os.cpu_count() or 4) * 2)
CHUNK_SIZE = 8 * 1024 * 1024


class CompressError(RuntimeError):
    """单个文件压缩失败。"""

    def __init__(self, source_file: Path, final_zip: Path, message: str):
        super().__init__(message)
        self.source_file = source_file
        self.final_zip = final_zip


def iter_targets(source_dir: Path):
    """只收集指定目录第一层的文件和文件夹；文件夹作为整体压缩目标。"""
    for path in source_dir.iterdir():
        if path.is_file() or path.is_dir():
            yield path


def iter_dir_files(folder: Path, recursive: bool):
    pattern = "**/*" if recursive else "*"
    for path in folder.glob(pattern):
        if path.is_file():
            yield path


def collect_targets(source_dir: Path, list_file: Path | None) -> list[Path]:
    """收集待压缩文件/文件夹；list_file 存在时按 txt 每行路径处理。"""
    if list_file is None:
        return list(iter_targets(source_dir))

    files: list[Path] = []
    list_base = list_file.parent
    for line_no, raw_line in enumerate(list_file.read_text(encoding="utf-8-sig").splitlines(), start=1):
        line = raw_line.strip().strip('"')
        if not line or line.startswith("#"):
            continue

        path = Path(line).expanduser()
        if not path.is_absolute():
            path = list_base / path
        path = path.resolve()

        if path.is_file():
            files.append(path)
        elif path.is_dir():
            files.append(path)
        else:
            print(f"清单第 {line_no} 行路径不存在，已跳过：{raw_line}")

    return files


def relative_output_path(source_path: Path, source_dir: Path) -> Path:
    """源路径不在 source_dir 下时只用名称输出，避免 txt 清单跨盘路径报错。"""
    try:
        return source_path.relative_to(source_dir)
    except ValueError:
        return Path(source_path.name)


def unique_zip_path(out_dir: Path, source_path: Path, relative_path: Path, overwrite: bool) -> Path:
    """为文件/文件夹生成压缩包路径；默认不覆盖已有文件。"""
    zip_relative_path = relative_path.with_name(f"{relative_path.name}.zip") if source_path.is_dir() else relative_path.with_suffix(".zip")
    target = out_dir / zip_relative_path
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


def write_file_to_zip(zf: zipfile.ZipFile, source_file: Path, arcname: str, method: int, progress: tqdm) -> None:
    """分块写入单个文件到 zip，并更新字节级进度。"""
    stat = source_file.stat()
    mtime = datetime.fromtimestamp(stat.st_mtime)
    zip_info = zipfile.ZipInfo(arcname, date_time=mtime.timetuple()[:6])
    zip_info.compress_type = method
    zip_info.file_size = stat.st_size

    with source_file.open("rb") as src, zf.open(zip_info, "w", force_zip64=True) as dst:
        while True:
            chunk = src.read(CHUNK_SIZE)
            if not chunk:
                break
            dst.write(chunk)
            progress.update(len(chunk))


def zip_path_with_progress(source_path: Path, temp_zip: Path, method: int, recursive: bool, progress: tqdm) -> None:
    """用分块写入 zip 的方式更新字节级进度，避免大文件/文件夹时进度条长时间不动。"""
    compresslevel = 6 if method == zipfile.ZIP_DEFLATED else None
    with zipfile.ZipFile(temp_zip, "w", compression=method, compresslevel=compresslevel) as zf:
        if source_path.is_file():
            write_file_to_zip(zf, source_path, source_path.name, method, progress)
            return

        folder_base = source_path.parent
        for file in iter_dir_files(source_path, recursive):
            write_file_to_zip(zf, file, file.relative_to(folder_base).as_posix(), method, progress)


def path_size(source_path: Path, recursive: bool) -> int:
    if source_path.is_file():
        return source_path.stat().st_size
    return sum(file.stat().st_size for file in iter_dir_files(source_path, recursive))


def compress_one_file(source_file: Path, source_dir: Path, out_dir: Path, method: int, overwrite: bool, recursive: bool, progress: tqdm) -> tuple[Path, Path, int]:
    """压缩单个文件或整个文件夹，返回：原路径、压缩包路径、原路径大小。"""
    relative_file = relative_output_path(source_file, source_dir)
    final_zip = unique_zip_path(out_dir, source_file, relative_file, overwrite)
    final_zip.parent.mkdir(parents=True, exist_ok=True)

    fd, temp_name = tempfile.mkstemp(prefix=f".{final_zip.stem}.", suffix=".tmp", dir=final_zip.parent)
    os.close(fd)
    temp_zip = Path(temp_name)

    try:
        zip_path_with_progress(source_file, temp_zip, method, recursive, progress)
        if overwrite and final_zip.exists():
            final_zip.unlink()
        shutil.move(str(temp_zip), str(final_zip))
        return source_file, final_zip, path_size(source_file, recursive)
    except Exception as exc:  # noqa: BLE001 - 命令行工具需要把失败文件继续汇总出来
        temp_zip.unlink(missing_ok=True)
        raise CompressError(source_file, final_zip, f"{source_file} -> {final_zip} 失败：{exc}") from exc


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description="指定文件夹下文件逐个压缩成 zip 包，然后移动到输出目录；原始文件夹不动。")
    parser.add_argument("source_dir", nargs="?", type=Path, default=Path(SOURCE_DIR), help="要扫描的源文件夹；不填则使用 py 文件里的 SOURCE_DIR")
    parser.add_argument("out_dir", nargs="?", type=Path, default=Path(OUT_DIR), help="压缩包输出/移动到的目标文件夹；不填则使用 py 文件里的 OUT_DIR")
    parser.add_argument("--recursive", "-r", action="store_true", default=RECURSIVE, help=f"压缩文件夹时递归包含子目录内容，默认 {RECURSIVE}")
    parser.add_argument("--workers", "-w", type=int, default=DEFAULT_WORKERS, help=f"并发线程数，默认 {DEFAULT_WORKERS}")
    parser.add_argument(
        "--method",
        choices=("deflate", "store"),
        default=METHOD,
        help="deflate=正常压缩体积更小；store=只打包不压缩，速度最快但体积基本不变",
    )
    parser.add_argument("--overwrite", action="store_true", default=OVERWRITE, help=f"允许覆盖目标目录中已有同名 zip 包，默认 {OVERWRITE}")
    parser.add_argument("--list-file", type=Path, default=Path(LIST_TXT) if LIST_TXT else None, help="txt 清单路径；每行一个要压缩的文件或文件夹路径")
    parser.add_argument("--report-csv", type=Path, default=Path(REPORT_CSV) if REPORT_CSV else None, help="压缩结果 CSV 表格路径；不填则输出到目标目录/压缩结果.csv")
    return parser.parse_args()


def write_report(report_csv: Path, rows: list[dict[str, str]]) -> None:
    """导出压缩成功/失败结果表格。"""
    report_csv.parent.mkdir(parents=True, exist_ok=True)
    fieldnames = ["状态", "源文件", "压缩包", "原大小MB", "压缩包大小MB", "耗时秒", "错误"]
    with report_csv.open("w", newline="", encoding="utf-8-sig") as file:
        writer = csv.DictWriter(file, fieldnames=fieldnames)
        writer.writeheader()
        writer.writerows(rows)


def mb(size: int) -> str:
    return f"{size / 1024 / 1024:.2f}"


def main() -> int:
    args = parse_args()
    source_dir = args.source_dir.expanduser().resolve()
    out_dir = args.out_dir.expanduser().resolve()
    list_file = args.list_file.expanduser().resolve() if args.list_file else None

    if list_file is not None and not list_file.is_file():
        print(f"txt 清单不存在：{list_file}")
        return 2
    if list_file is None and not source_dir.is_dir():
        print(f"源文件夹不存在：{source_dir}")
        return 2
    if list_file is not None and not source_dir.is_dir():
        source_dir = list_file.parent
    if source_dir == out_dir or out_dir.is_relative_to(source_dir):
        print("输出目录不能是源目录本身，也不能放在源目录里面；这样可以避免重复扫描压缩包。")
        return 2
    if args.workers < 1:
        print("--workers 必须大于等于 1")
        return 2

    out_dir.mkdir(parents=True, exist_ok=True)
    files = collect_targets(source_dir, list_file)
    if not files:
        print("未找到需要压缩的文件。")
        return 0

    method = zipfile.ZIP_STORED if args.method == "store" else zipfile.ZIP_DEFLATED
    start = time.time()
    ok_count = 0
    fail_count = 0
    total_bytes = 0
    report_rows: list[dict[str, str]] = []

    print(f"源目录：{source_dir}")
    print(f"输出目录：{out_dir}")
    print(f"文件数量：{len(files)}，线程数：{args.workers}，模式：{args.method}")

    total_input_bytes = sum(path_size(file, args.recursive) for file in files)
    with tqdm(total=total_input_bytes, unit="B", unit_scale=True, unit_divisor=1024, desc="压缩进度") as progress:
        with ThreadPoolExecutor(max_workers=args.workers) as executor:
            futures = [executor.submit(compress_one_file, file, source_dir, out_dir, method, args.overwrite, args.recursive, progress) for file in files]
            for future in as_completed(futures):
                try:
                    source_file, final_zip, size = future.result()
                    ok_count += 1
                    total_bytes += size
                    zip_size = final_zip.stat().st_size if final_zip.exists() else 0
                    report_rows.append({
                        "状态": "成功",
                        "源文件": str(source_file),
                        "压缩包": str(final_zip),
                        "原大小MB": mb(size),
                        "压缩包大小MB": mb(zip_size),
                        "耗时秒": "",
                        "错误": "",
                    })
                    progress.set_postfix(ok=ok_count, fail=fail_count, refresh=False)
                    tqdm.write(f"[{ok_count}/{len(files)}] OK {source_file} -> {final_zip}")
                except CompressError as exc:
                    fail_count += 1
                    report_rows.append({
                        "状态": "失败",
                        "源文件": str(exc.source_file),
                        "压缩包": str(exc.final_zip),
                        "原大小MB": mb(path_size(exc.source_file, args.recursive)) if exc.source_file.exists() else "",
                        "压缩包大小MB": "",
                        "耗时秒": "",
                        "错误": str(exc),
                    })
                    progress.set_postfix(ok=ok_count, fail=fail_count, refresh=False)
                    tqdm.write(f"FAIL {exc}")

    elapsed = time.time() - start
    speed = total_bytes / 1024 / 1024 / elapsed if elapsed > 0 else 0
    report_csv = args.report_csv.expanduser().resolve() if args.report_csv else out_dir / "压缩结果.csv"
    for row in report_rows:
        row["耗时秒"] = f"{elapsed:.2f}"
    write_report(report_csv, report_rows)

    print(f"完成：成功 {ok_count}，失败 {fail_count}，耗时 {elapsed:.2f}s，读取速度约 {speed:.2f} MiB/s")
    print(f"结果表格：{report_csv}")
    return 1 if fail_count else 0


if __name__ == "__main__":
    raise SystemExit(main())
