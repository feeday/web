#!/bin/bash

# ========================================================
# Conda 环境管理脚本 
# ========================================================

# 1. 显式指定 D 盘的 Conda 初始化路径 (新增路径兼容修复)
CONDA_ROOT="/d/anaconda3"
CONDA_SH_PATH="$CONDA_ROOT/etc/profile.d/conda.sh"

if [ -f "$CONDA_SH_PATH" ]; then
    # 强制重置 PATH，防止 Git Bash 的 cygdrive 路径干扰删除操作
    export PATH="$CONDA_ROOT/Scripts:$CONDA_ROOT/bin:$PATH"
    source "$CONDA_SH_PATH"
else
    echo -e "\033[31m[错误] 找不到 Conda 初始化脚本：$CONDA_SH_PATH\033[0m"
    exit 1
fi

# --- 辅助函数：安全获取非空输入 ---
function get_input() {
    local prompt=$1
    local var_name=$2
    while true; do
        read -p "$(echo -e "\033[35m$prompt\033[0m")" input_val
        if [[ -z "$input_val" ]]; then
            echo -e "\033[33m[!] 输入不能为空 (输入 q 返回): \033[0m"
            continue
        fi
        if [[ "$input_val" == "q" ]]; then return 1; fi
        eval $var_name=\'$input_val\'
        return 0
    done
}

# --- 1. 创建环境 ---
function create_env() {
    echo -e "\n--- [1] 创建 Conda 环境 ---"
    get_input "请输入新环境名称: " env_name || return
    read -p "请输入 Python 版本 (默认 3.10): " py_ver
    py_ver=${py_ver:-3.10} 
    echo -e "\033[32m>>> 正在创建环境 $env_name (Python $py_ver)...\033[0m"
    conda create -n "$env_name" python="$py_ver" -y
}

# --- 2. 删除环境 (V5.0 核心升级：物理粉碎防残留) ---
function delete_env() {
    echo -e "\n--- [2] 删除 Conda 环境 ---"
    conda env list
    get_input "请输入要删除的环境名称: " env_name || return
    
    [[ "$env_name" == "base" ]] && { echo -e "\033[31m禁止删除 base！\033[0m"; return; }

    # 防呆设计：如果要删的是当前正在使用的环境，自动退房
    if [[ "$env_name" == "$CONDA_DEFAULT_ENV" ]]; then
        echo -e "\033[33m[提示] 您正在删除当前激活的环境，已自动为您退出...\033[0m"
        conda deactivate
    fi

    read -p "确认彻底删除 $env_name？(y/n): " confirm
    if [[ "$confirm" == "y" || "$confirm" == "Y" ]]; then
        # 抓取环境的真实物理路径
        local env_path=$(conda env list | grep -E "^$env_name\s" | awk '{print $NF}')
        
        echo -e "\033[32m>>> 第一步: 运行 Conda 标准卸载...\033[0m"
        conda remove -n "$env_name" --all -y
        
        # 物理粉碎：如果文件夹还赖着不走，直接强删
        if [[ -n "$env_path" && -d "$env_path" ]]; then
            echo -e "\033[33m>>> 第二步: 发现残留文件，正在执行物理抹除...\033[0m"
            rm -rf "$env_path"
            if [ $? -eq 0 ]; then
                echo -e "\033[32m[成功] $env_name 环境及底层文件已彻底粉碎！\033[0m"
            else
                echo -e "\033[31m[警告] 物理抹除未完全成功，可能有其他程序占用了该文件夹。\033[0m"
            fi
        else
            echo -e "\033[32m[成功] 环境清理完毕，无残留。\033[0m"
        fi
    fi
}

# --- 3. 切换环境 ---
function switch_env() {
    echo -e "\n--- [3] 切换 Conda 环境 ---"
    conda env list
    get_input "请输入目标环境名称: " env_name || return
    conda activate "$env_name" 2>/dev/null
    if [ $? -eq 0 ]; then
        echo -e "\033[32m>>> 已成功激活 $env_name！\033[0m"
        return 0
    else
        echo -e "\033[31m[错误] 环境不存在或切换失败。\033[0m"
        return 1
    fi
}

# --- 5. 配置国内镜像源 (保留 V4.0 完整逻辑) ---
function set_mirrors() {
    while true; do
        echo -e "\n--- [5] 配置国内镜像源 (已加入防超时逻辑) ---"
        echo "1) 清华大学 (Tuna) - 极致加速"
        echo "2) 中国科学技术大学 (USTC)"
        echo "3) 阿里云 (Aliyun)"
        echo "4) 恢复官方默认源 (不推荐)"
        echo "q) 返回上级菜单"
        read -p "请选择序号: " m_choice

        case "$m_choice" in
            1|2|3)
                echo "正在深度重置配置..."
                conda config --remove-key channels 2>/dev/null
                conda config --set auto_update_conda False
                
                if [ "$m_choice" == "1" ]; then
                    echo "应用清华镜像源..."
                    conda config --add channels https://mirrors.tuna.tsinghua.edu.cn/anaconda/pkgs/main/
                    conda config --add channels https://mirrors.tuna.tsinghua.edu.cn/anaconda/pkgs/free/
                    conda config --add channels https://mirrors.tuna.tsinghua.edu.cn/anaconda/cloud/conda-forge/
                    conda config --add channels https://mirrors.tuna.tsinghua.edu.cn/anaconda/cloud/pytorch/
                elif [ "$m_choice" == "2" ]; then
                    echo "应用中科大源..."
                    conda config --add channels https://mirrors.ustc.edu.cn/anaconda/pkgs/main/
                    conda config --add channels https://mirrors.ustc.edu.cn/anaconda/pkgs/free/
                    conda config --add channels https://mirrors.ustc.edu.cn/anaconda/cloud/conda-forge/
                else
                    echo "应用阿里源..."
                    conda config --add channels https://mirrors.aliyun.com/anaconda/pkgs/main/
                    conda config --add channels https://mirrors.aliyun.com/anaconda/pkgs/free/
                fi

                conda config --set show_channel_urls yes
                echo -e "\033[32m正在强制清理索引缓存 (这能解决刚才的超时卡顿)...\033[0m"
                conda clean -i -y
                echo -e "\033[32m配置完成！当前源优先级：\033[0m"
                conda config --show channels
                echo -e "\033[34m[提示] 官方源已从列表中移除，现在连接应该非常顺畅。\033[0m"
                break
                ;;
            4)
                conda config --remove-key channels 2>/dev/null
                conda clean -i -y
                echo "已恢复官方源。"
                break
                ;;
            q) return ;;
            *) echo "无效选择。" ;;
        esac
    done
}

# --- 主循环 ---
while true; do
    echo -e "\n\033[36m====================================="
    echo "      Conda 环境管理工具 (V5.0)"
    echo "====================================="
    echo "  1. 创建虚拟环境"
    echo "  2. 删除虚拟环境 (物理清理防残留)"
    echo "  3. 切换虚拟环境 (成功后自动退出菜单)"
    echo "  4. 退出当前环境 (返回 base)"
    echo "  5. 配置国内镜像源 (彻底解决超时)"
    echo "  0. 退出管理菜单"
    echo -e "=====================================\033[0m"
    read -p "请输入序号 [0-5]: " choice

    case "$choice" in
        1) create_env ;;
        2) delete_env ;;
        3) switch_env && break ;;
        4) conda deactivate && echo "已返回 base。" && break ;;
        5) set_mirrors ;;
        0) break ;;
        *) echo -e "\033[33m[!] 无效选择，请输入 0-5。\033[0m" ;;
    esac
done

alias ce='source /c/Users/Puck/Videos/ce.sh'
