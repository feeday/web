#!/usr/bin/env bash
# A10 (24 GB) / Ubuntu 一键部署并启动 ComfyUI + LTX-2.3 KJ 工作流。
# 首次运行会下载约数十 GB 的模型；私有/受限模型请先设置 HF_TOKEN。
set -Eeuo pipefail

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
WORKFLOW_NAME='LTX2.3数字人说话唱歌对口型！优化升级kj版！B站艾橘溪.json'
WORKFLOW_FILE="${SCRIPT_DIR}/${WORKFLOW_NAME}"
COMFYUI_DIR="${COMFYUI_DIR:-${HOME}/ComfyUI}"
PYTHON_BIN="${PYTHON_BIN:-python3}"
PORT="${PORT:-8188}"
LISTEN="${LISTEN:-0.0.0.0}"
MODEL_BASE_URL="${MODEL_BASE_URL:-https://huggingface.co/Kijai/LTXVideo_comfy/resolve/main}"

log() { printf '\033[1;32m[run.sh]\033[0m %s\n' "$*"; }
die() { printf '\033[1;31m[run.sh] ERROR:\033[0m %s\n' "$*" >&2; exit 1; }

command -v git >/dev/null || die "未找到 git"
[[ -f "$WORKFLOW_FILE" ]] || die "工作流不存在：${WORKFLOW_FILE}"

install_system_packages() {
  command -v apt-get >/dev/null || return 0
  local apt=(apt-get)
  if (( EUID != 0 )); then
    command -v sudo >/dev/null || die "安装系统依赖需要 root 或 sudo"
    apt=(sudo apt-get)
  fi
  log "安装系统依赖"
  "${apt[@]}" update
  DEBIAN_FRONTEND=noninteractive "${apt[@]}" install -y \
    git git-lfs ffmpeg aria2 python3 python3-venv python3-pip libgl1 libglib2.0-0
}

clone_or_update() {
  local url="$1" dir="$2"
  if [[ -d "$dir/.git" ]]; then
    log "更新 $(basename "$dir")"
    git -C "$dir" pull --ff-only || log "保留 $(basename "$dir") 当前版本（无法快进更新）"
  elif [[ -e "$dir" ]]; then
    log "跳过已存在的非 Git 目录：$dir"
  else
    git clone --depth 1 "$url" "$dir"
  fi
}

install_requirements() {
  local dir="$1"
  [[ -f "$dir/requirements.txt" ]] && "$PIP" install -r "$dir/requirements.txt"
  [[ -f "$dir/install.py" ]] && "$PYTHON" "$dir/install.py"
}

download_model() {
  local relative_path="$1" source_name="${2:-$1}"
  local destination="${COMFYUI_DIR}/models/${relative_path}"
  [[ -s "$destination" ]] && { log "模型已存在：$relative_path"; return 0; }
  mkdir -p "$(dirname "$destination")"
  log "下载模型：$relative_path"
  local headers=()
  [[ -n "${HF_TOKEN:-}" ]] && headers+=(--header="Authorization: Bearer ${HF_TOKEN}")
  aria2c --continue=true --max-connection-per-server=8 --split=8 \
    --min-split-size=10M --retry-wait=5 --max-tries=10 \
    "${headers[@]}" --dir="$(dirname "$destination")" \
    --out="$(basename "$destination")" "${MODEL_BASE_URL}/${source_name}"
}

install_system_packages

if [[ ! -d "$COMFYUI_DIR/.git" ]]; then
  log "安装 ComfyUI 到 ${COMFYUI_DIR}"
  git clone --depth 1 https://github.com/comfyanonymous/ComfyUI.git "$COMFYUI_DIR"
else
  log "更新 ComfyUI"
  git -C "$COMFYUI_DIR" pull --ff-only || log "保留当前 ComfyUI 版本（无法快进更新）"
fi

VENV="${COMFYUI_DIR}/venv"
[[ -x "$VENV/bin/python" ]] || "$PYTHON_BIN" -m venv "$VENV"
PYTHON="$VENV/bin/python"
PIP="$VENV/bin/pip"
"$PIP" install --upgrade pip wheel setuptools
# A10 使用 CUDA 12.8 wheel；可通过 TORCH_INDEX_URL 覆盖，已有兼容 torch 时不会重复安装。
"$PIP" install torch torchvision torchaudio \
  --index-url "${TORCH_INDEX_URL:-https://download.pytorch.org/whl/cu128}"
"$PIP" install -r "$COMFYUI_DIR/requirements.txt"

CUSTOM_NODES="$COMFYUI_DIR/custom_nodes"
mkdir -p "$CUSTOM_NODES"
declare -a NODE_REPOS=(
  'https://github.com/ltdrdata/ComfyUI-Manager.git|ComfyUI-Manager'
  'https://github.com/kijai/ComfyUI-KJNodes.git|ComfyUI-KJNodes'
  'https://github.com/Kosinkadink/ComfyUI-VideoHelperSuite.git|ComfyUI-VideoHelperSuite'
  'https://github.com/kijai/ComfyUI-MelBandRoFormer.git|ComfyUI-MelBandRoFormer'
  'https://github.com/chflame163/ComfyUI_LayerStyle.git|ComfyUI_LayerStyle'
  'https://github.com/cubiq/ComfyUI_essentials.git|ComfyUI_essentials'
  'https://github.com/yolain/ComfyUI-Easy-Use.git|ComfyUI-Easy-Use'
)
for entry in "${NODE_REPOS[@]}"; do
  IFS='|' read -r url name <<< "$entry"
  clone_or_update "$url" "$CUSTOM_NODES/$name"
  install_requirements "$CUSTOM_NODES/$name"
done

if [[ "${SKIP_MODELS:-0}" != 1 ]]; then
  # 文件名与本目录工作流中的 Loader 节点完全一致。
  download_model 'diffusion_models/ltx-2.3-22b-distilled-1.1_transformer_only_fp8_scaled.safetensors'
  download_model 'text_encoders/gemma_3_12B_it.safetensors'
  download_model 'text_encoders/ltx-2.3_text_projection_bf16.safetensors'
  download_model 'vae/LTX23_video_vae_bf16.safetensors'
  download_model 'vae/LTX23_audio_vae_bf16.safetensors'
  download_model 'vae/taeltx2_3.safetensors'
  download_model 'loras/ltx-2.3-22b-distilled-lora-384.safetensors'
  download_model 'latent_upscale_models/ltx-2.3-spatial-upscaler-x2-1.1.safetensors'
  download_model 'audio_filters/MelBandRoFormer_comfy/MelBandRoformer_fp16.safetensors'
else
  log "SKIP_MODELS=1，跳过模型下载"
fi

WORKFLOW_DIR="$COMFYUI_DIR/user/default/workflows"
mkdir -p "$WORKFLOW_DIR"
# 旧版 KJNodes 的 GetNode/SetNode 只是前端虚拟节点。新版 ComfyUI 可能将 Get_vae
# 等标题当成后端 class_type，导致任务无法提交。安装时自动把这些虚拟节点展开成
# 普通连线，而不是中止整个一键部署。源 JSON 保持不变，转换结果原子写入 ComfyUI。
"$PYTHON" - "$WORKFLOW_FILE" "$WORKFLOW_DIR/$WORKFLOW_NAME" <<'PY'
import json
import os
import sys

source_path, destination_path = sys.argv[1:]
with open(source_path, encoding="utf-8") as workflow_file:
    workflow = json.load(workflow_file)

nodes = {node["id"]: node for node in workflow.get("nodes", [])}
links = {link[0]: link for link in workflow.get("links", [])}
virtual_ids = {
    node_id for node_id, node in nodes.items()
    if node.get("type") in {"GetNode", "SetNode"}
}

if virtual_ids:
    setters = {}
    for node_id in virtual_ids:
        node = nodes[node_id]
        if node.get("type") != "SetNode":
            continue
        name = node.get("widgets_values", [None])[0]
        input_link = node.get("inputs", [{}])[0].get("link")
        if not name or input_link not in links:
            raise SystemExit(f"无法转换未连接的 SetNode：{node.get('title', node_id)}")
        if name in setters:
            raise SystemExit(f"无法转换重复的 SetNode 名称：{name}")
        link = links[input_link]
        setters[name] = (link[1], link[2], input_link)

    for link in workflow["links"]:
        origin = nodes[link[1]]
        if origin.get("type") not in {"GetNode", "SetNode"}:
            continue
        name = origin.get("widgets_values", [None])[0]
        if name not in setters:
            raise SystemExit(f"找不到与 {origin.get('title', link[1])} 对应的 SetNode")
        link[1], link[2], _ = setters[name]

    setter_input_links = {value[2] for value in setters.values()}
    workflow["links"] = [
        link for link in workflow["links"] if link[0] not in setter_input_links
    ]
    workflow["nodes"] = [
        node for node in workflow["nodes"] if node["id"] not in virtual_ids
    ]

    # links 数组才是 LiteGraph 的连线真值来源；同步每个端口的缓存元数据。
    remaining = {node["id"]: node for node in workflow["nodes"]}
    for node in workflow["nodes"]:
        for node_input in node.get("inputs", []):
            node_input["link"] = None
        for output in node.get("outputs", []):
            output["links"] = None
    for link in workflow["links"]:
        link_id, origin_id, origin_slot, target_id, target_slot, _ = link
        remaining[target_id]["inputs"][target_slot]["link"] = link_id
        output = remaining[origin_id]["outputs"][origin_slot]
        if output["links"] is None:
            output["links"] = []
        output["links"].append(link_id)

temporary_path = destination_path + ".tmp"
with open(temporary_path, "w", encoding="utf-8") as workflow_file:
    json.dump(workflow, workflow_file, ensure_ascii=False, indent=2)
    workflow_file.write("\n")
os.replace(temporary_path, destination_path)
print(f"工作流安装完成：已展开 {len(virtual_ids)} 个 GetNode/SetNode 虚拟节点")
PY

log "部署完成，启动地址：http://${LISTEN}:${PORT}"
log "工作流已存入：${WORKFLOW_DIR}/${WORKFLOW_NAME}"
cd "$COMFYUI_DIR"
exec "$PYTHON" main.py --listen "$LISTEN" --port "$PORT" --lowvram "$@"
