#!/bin/bash

# ==========================================
# Conda 环境管理脚本 (交互逻辑优化版)
# ==========================================

CONDA_BASE=$(conda info --base 2>/dev/null)
if [ -f "$CONDA_BASE/etc/profile.d/conda.sh" ]; then
    source "$CONDA_BASE/etc/profile.d/conda.sh"
fi

function create_env() {
    echo -e "\n--- 创建 Conda 环境 ---"
    read -p "请输入新环境名称: " env_name
    read -p "请输入 Python 版本 (直接回车默认 3.10): " py_ver
    py_ver=${py_ver:-3.10} 

    echo ">>> 正在创建环境 $env_name (Python $py_ver)..."
    conda create -n "$env_name" python="$py_ver" -y
    echo ">>> 环境 $env_name 创建完成！"
}

function delete_env() {
    echo -e "\n--- 删除 Conda 环境 ---"
    conda env list
    read -p "请输入要彻底删除的环境名称: " env_name
    
    if [ "$env_name" == "base" ]; then
        echo "错误: 不能删除 base 环境！"
        return
    fi

    echo ">>> 正在删除环境 $env_name 及其所有依赖..."
    conda remove -n "$env_name" --all -y
    echo ">>> 环境 $env_name 删除完成！"
}

function switch_env() {
    echo -e "\n--- 切换 Conda 环境 ---"
    conda env list
    read -p "请输入要切换到的环境名称: " env_name
    
    conda activate "$env_name" 2>/dev/null
    if [ $? -eq 0 ]; then
        echo ">>> 已成功切换到 $env_name 环境！"
        return 0
    else
        echo "错误: 切换失败。请确认环境是否存在。"
        return 1
    fi
}

function exit_env() {
    echo -e "\n--- 退出当前 Conda 环境 ---"
    conda deactivate
    echo ">>> 已退出当前环境，返回 base 状态。"
}

# 主菜单循环
while true; do
    echo -e "\n============================="
    echo "    Conda 环境管理工具"
    echo "============================="
    echo "1. 创建虚拟环境"
    echo "2. 删除虚拟环境"
    echo "3. 切换虚拟环境"
    echo "4. 退出虚拟环境"
    echo "0. 退出本菜单"
    echo "============================="
    read -p "请输入序号选择操作: " choice

    case $choice in
        1) create_env ;;
        2) delete_env ;;
        3) 
            switch_env 
            # 如果切换成功 (返回状态码0)，则跳出循环关闭菜单
            if [ $? -eq 0 ]; then
                break
            fi
            ;;
        4) 
            exit_env 
            break # 退出环境后也直接关闭菜单
            ;;
        0) 
            echo "已退出管理菜单。"
            break
            ;;
        *) 
            echo "无效的选择，请重新输入。" 
            ;;
    esac
done
