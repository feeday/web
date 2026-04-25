#!/bin/bash

# ========================================================
# Conda 环境管理脚本 
# ========================================================

# 1. 显式指定 D 盘的 Conda 初始化路径 (Git Bash 格式)
CONDA_SH_PATH="/d/anaconda3/etc/profile.d/conda.sh"

# 检查初始化文件是否存在
if [ -f "$CONDA_SH_PATH" ]; then
    source "$CONDA_SH_PATH"
else
    echo -e "\033[31m[错误] 找不到 Conda 初始化脚本：$CONDA_SH_PATH\033[0m"
    echo "请确认 Anaconda 是否安装在 D:\anaconda3"
    exit 1
fi

# --- 辅助函数：安全获取非空输入 ---
function get_input() {
    local prompt=$1
    local var_name=$2
    while true; do
        read -p "$(echo -e "\033[35m$prompt\033[0m")" input_val
        if [[ -z "$input_val" ]]; then
            echo -e "\033[33m[!] 输入不能为空，请重新输入 (或输入 q 返回): \033[0m"
            continue
        fi
        if [[ "$input_val" == "q" ]]; then return 1; fi
        eval $var_name=\'$input_val\'
        return 0
    done
}

# --- 1. 创建环境 ---
function create_env() {
    echo -e "\n--- [1] 创建 Conda 环境 (输入 q 返回) ---"
    get_input "请输入新环境名称: " env_name || return
    read -p "请输入 Python 版本 (直接回车默认 3.10): " py_ver
    py_ver=${py_ver:-3.10} 
    echo -e "\033[32m>>> 正在创建环境 $env_name (Python $py_ver)...\033[0m"
    conda create -n "$env_name" python="$py_ver" -y
    echo -e "\033[32m>>> 创建完成！\033[0m"
}

# --- 2. 删除环境 ---
function delete_env() {
    echo -e "\n--- [2] 删除 Conda 环境 (输入 q 返回) ---"
    conda env list
    get_input "请输入要删除的环境名称: " env_name || return
    if [[ "$env_name" == "base" ]]; then
        echo -e "\033[31m[拒绝] 不能删除 base 环境！\033[0m"
        return
    fi
    read -p "危险操作！确认删除 $env_name 及其所有包吗？(y/n): " confirm
    if [[ "$confirm" == "y" || "$confirm" == "Y" ]]; then
        conda remove -n "$env_name" --all -y
        echo -e "\033[32m>>> 环境 $env_name 已彻底移除。\033[0m"
    else
        echo "操作已取消。"
    fi
}

# --- 3. 切换环境 ---
function switch_env() {
    echo -e "\n--- [3] 切换 Conda 环境 (输入 q 返回) ---"
    conda env list
    get_input "请输入目标环境名称: " env_name || return
    echo "正在激活环境..."
    conda activate "$env_name" 2>/dev/null
    if [ $? -eq 0 ]; then
        echo -e "\033[32m>>> 已成功激活 $env_name 环境！\033[0m"
        return 0
    else
        echo -e "\033[31m[错误] 切换失败。请检查环境名是否输入正确。\033[0m"
        return 1
    fi
}

# --- 5. 配置国内镜像源 ---
function set_mirrors() {
    while true; do
        echo -e "\n--- [5] 配置国内镜像源 (解决警告+自动更新缓存) ---"
        echo "1) 清华大学 (Tuna) - 推荐"
        echo "2) 中国科学技术大学 (USTC)"
        echo "3) 阿里云 (Aliyun)"
        echo "4) 恢复官方默认源 (Default)"
        echo "q) 返回上级菜单"
        read -p "请选择序号: " m_choice

        case "$m_choice" in
            1|2|3)
                echo "正在重置并应用新源配置..."
                conda config --remove-key channels 2>/dev/null
                # 显式添加 defaults 解决 FutureWarning 警告
                conda config --add channels defaults
                
                if [ "$m_choice" == "1" ]; then
                    echo "配置清华源..."
                    conda config --add channels https://mirrors.tuna.tsinghua.edu.cn/anaconda/pkgs/main/
                    conda config --add channels https://mirrors.tuna.tsinghua.edu.cn/anaconda/pkgs/free/
                    conda config --add channels https://mirrors.tuna.tsinghua.edu.cn/anaconda/cloud/conda-forge/
                    conda config --add channels https://mirrors.tuna.tsinghua.edu.cn/anaconda/cloud/pytorch/
                elif [ "$m_choice" == "2" ]; then
                    echo "配置中科大源..."
                    conda config --add channels https://mirrors.ustc.edu.cn/anaconda/pkgs/main/
                    conda config --add channels https://mirrors.ustc.edu.cn/anaconda/pkgs/free/
                    conda config --add channels https://mirrors.ustc.edu.cn/anaconda/cloud/conda-forge/
                else
                    echo "配置阿里源..."
                    conda config --add channels https://mirrors.aliyun.com/anaconda/pkgs/main/
                    conda config --add channels https://mirrors.aliyun.com/anaconda/pkgs/free/
                fi

                conda config --set show_channel_urls yes
                echo -e "\033[32m正在自动清理索引缓存 (确保新源立即生效)...\033[0m"
                conda clean -i -y
                echo -e "\033[32m配置成功！当前渠道优先级：\033[0m"
                conda config --show channels
                break
                ;;
            4)
                echo "正在清理配置并恢复默认..."
                conda config --remove-key channels 2>/dev/null
                conda clean -i -y
                echo "已恢复默认官方源。"
                break
                ;;
            q) return ;;
            *) echo -e "\033[33m无效选择，请重新输入。\033[0m" ;;
        esac
    done
}

# --- 主菜单循环 ---
while true; do
    echo -e "\n\033[36m====================================="
    echo "      Conda 环境管理工具 (V3.0)"
    echo "====================================="
    echo "  1. 创建虚拟环境"
    echo "  2. 删除虚拟环境"
    echo "  3. 切换虚拟环境 (成功后退出菜单)"
    echo "  4. 退出当前环境 (返回 base)"
    echo "  5. 配置国内镜像源 (加速+清理缓存)"
    echo "  0. 退出管理菜单"
    echo -e "=====================================\033[0m"
    read -p "请输入序号选择操作 [0-5]: " choice

    case "$choice" in
        1) create_env ;;
        2) delete_env ;;
        3) 
            if switch_env; then
                echo -e "\033[34m提示: 脚本已安全关闭，你现在处于新环境中。\033[0m"
                break
            fi
            ;;
        4) 
            conda deactivate
            echo -e "\033[32m>>> 已退出当前环境，返回 base。\033[0m"
            break 
            ;;
        5) set_mirrors ;;
        0) 
            echo "已退出管理菜单。"
            break 
            ;;
        *) 
            echo -e "\033[33m[!] 无效选择: '$choice'，请输入 0-5 之间的序号。\033[0m"
            ;;
    esac
done
