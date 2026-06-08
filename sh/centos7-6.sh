#!/usr/bin/env bash
# Description: CentOS 7 Configure & Security Toolkit
# System Download URL  https://mirrors.aliyun.com/centos/7.7.1908/isos/x86_64/CentOS-7-x86_64-Minimal-1908.iso
# Copyright (C) 2026 Puck
# Modified: Added Security Audit & AppNode Uninstaller

# ==================== 功能区 ====================

# 配置网络防火墙
function ips(){
    systemctl stop firewalld     # 停止firewall防火墙
    systemctl disable firewalld  # 禁止firewall开机启动
    systemctl mask firewalld     # 禁用firewalld服务

    yum install -y wget iptables nmap iptables-services   # 安装iptables防火墙和 nmap
    systemctl start iptables     # 启动防火墙

    # 配置文件目录 /etc/sysconfig/iptables
    iptables -P INPUT ACCEPT   # 先允许所有,不然有可能会杯具

    iptables -F      # 清空所有的防火墙规则
    iptables -X      # 清空所有自定义规则
    iptables -Z      # 所有计数器归0

    iptables -A INPUT -p tcp --dport 22 -j ACCEPT      # 开放22端口
    iptables -A INPUT -p tcp --dport 80 -j ACCEPT      # 开放80端口(HTTP)
    iptables -A INPUT -p tcp --dport 443 -j REJECT     # 关闭443端口(HTTPS)
    iptables -A INPUT -p tcp --dport 25565 -j ACCEPT   # 开放25565端口(MC)
    iptables -A INPUT -p tcp --dport 8888 -j ACCEPT    # 开放8888端口
    iptables -A INPUT -p icmp --icmp-type 8 -j ACCEPT  # 允许ping

    iptables -P INPUT DROP  # 其他入站一律丢弃

    iptables -A INPUT -m state --state ESTABLISHED,RELATED -j ACCEPT # 允许由服务器本身请求的数据通过
    iptables -A OUTPUT -m state --state ESTABLISHED,RELATED -j ACCEPT

    iptables -P OUTPUT ACCEPT  # 所有出站一律绿灯
    iptables -P FORWARD DROP   # 所有转发一律丢弃

    service iptables save                # 保存上述规则
    systemctl restart iptables.service   # 重启服务
    systemctl enable iptables            # 设置开机启动

    systemctl start iptables.service    # 启动服务
    systemctl status iptables.service   # 查看服务状态
    iptables -L -n                      # 查看防火墙规则
    nmap localhost                      # 查看开放的端口
}

# 安装 网络工具
function nt(){
    yum -y install wget curl-devel expat-devel gettext-devel openssl-devel zlib-devel net-tools openssh-server iptables nmap iptables-services git-core
    cd /etc
    touch gitconfig
    gpt 
    git config --list
    git --version
    ip add  
}

# Git用户配置文件
function gpt(){
cat > gitconfig  <<END
[http]
    postBuffer = 2M
[user]
    name = puck
    email = 0xf197@gmail.com
END
}

# 安装py3 (3.7)
function py3(){
    yum -y groupinstall "Development tools"
    yum -y install wget zlib-devel bzip2-devel openssl-devel ncurses-devel sqlite-devel readline-devel tk-devel gdbm-devel db4-devel libpcap-devel xz-devel
    yum install libffi-devel -y
    cd /home
    wget https://www.python.org/ftp/python/3.7.0/Python-3.7.0.tar.xz
    tar -xvJf  Python-3.7.0.tar.xz
    mkdir /usr/local/python3 #创建编译安装目录
    cd Python-3.7.0
    ./configure --prefix=/usr/local/python3
    make && make install
    cd /usr/bin/
    mv python python.bak
    ln -s /usr/local/python3/bin/python3 /usr/bin/python
    ln -s /usr/local/python3/bin/pip3 /usr/bin/pip
    
    cd /home
    curl https://bootstrap.pypa.io/get-pip.py -o get-pip.py
    sudo python get-pip.py
    PATH=$PATH:/usr/local/python3/bin
    cd /home
    wget https://github.com/soimort/you-get/archive/master.zip
    unzip -o master.zip
    mv you-get-master xz
    python -V
    pip3 -V
}

# 安装p3 (3.6)
function p3(){
    yum install -y wget epel-release xz gcc zlib zlib-devel openssl-devel bzip2-devel expat-devel gdbm-devel readline-devel sqlite-devel
    if [[ ! -s /usr/bin/python3 ]]; then
        wget http://file.aionlife.xyz/source/download?id=5b9e7227dc72d90ebb47023a -O Python-3.6.4.tar.xz 
        tar -Jxvf Python-3.6.4.tar.xz
        cd Python-3.6.4
        ./configure --prefix=/usr/python3.6
        make && make install
        ln -s /usr/python3.6/bin/python3 /usr/bin/python3
        mkdir ~/.pip
        echo -e "[global]\nindex-url = http://mirrors.aliyun.com/pypi/simple/\n[install]\ntrusted-host = mirrors.aliyun.com" > ~/.pip/pip.conf
        ln -s /usr/python3.6/bin/pip3 /usr/bin/pip3  
    fi
    pip3 install --upgrade pip
    python3 -V
    pip3 -V
}

# 安装Nginx
function nx(){
    cd /home
    yum -y install wget unzip zip gcc gcc-c++ autoconf automake make openssl openssl-devel pcre-devel zlib zlib-devel
    wget http://nginx.org/download/nginx-1.16.1.tar.gz
    tar -zxvf nginx-1.16.1.tar.gz
    cd nginx-1.16.1
    ./configure --prefix=/usr/local/nginx
    make && make install
    /usr/local/nginx/sbin/nginx 
    /usr/local/nginx/sbin/nginx -s stop
    /usr/local/nginx/sbin/nginx -s reload
    /usr/local/nginx/sbin/nginx -c /usr/local/nginx/conf/nginx.conf
    /usr/local/nginx/sbin/nginx -t
    ln -s /usr/local/nginx/sbin/nginx  /usr/bin/nginx
    cd /home
    rm -rf nginx-1.16.1/
    cd /usr/local/nginx/conf/
    nginx -t
    echo "nginx 配置文件目录 /usr/local/nginx/html"
}

# 安装jupyter
function jr(){
    cd /home 
    python -m venv tutorial-env
    source tutorial-env/bin/activate
    python -m pip install novas
    pip install --upgrade pip 
    pip3 install jupyter
    jupyter notebook --port 8888 --allow-root
}

# 安装jupyter (方式2)
function jr2(){
    wget https://pypi.python.org/packages/45/29/8814bf414e7cd1031e1a3c8a4169218376e284ea2553cc0822a6ea1c2d78/setuptools-36.6.0.zip#md5=74663b15117d9a2cc5295d76011e6fd1
    unzip setuptools-36.6.0.zip 
    cd setuptools-36.6.0
    python setup.py install
    wget https://pypi.python.org/packages/11/b6/abcb525026a4be042b486df43905d6893fb04f05aac21c32c638e939e447/pip-9.0.1.tar.gz#md5=35f01da33009719497f01a4ba69d63c9
    tar -zxvf pip-9.0.1.tar.gz
    cd pip-9.0.1
    python setup.py install
    pip install --upgrade pip 
    pip install jupyter notebook
    jupyter notebook --port 9999 --allow-root
}

# 卸载 AppNode (新增)
function uninstall_appnode() {
    echo -e "\033[33m准备卸载 AppNode 受控端与控制中心...\033[0m"
    # 卸载受控端
    echo "正在卸载受控端 (agent)..."
    appnode agent remove
    # 卸载控制中心
    echo "正在卸载控制中心 (ccenter)..."
    appnode ccenter remove
    # 清除残留数据
    echo "正在清理残留数据和配置..."
    rm -rf /opt/appnode/{ccenter,agent,ui}/
    echo -e "\033[32mAppNode 已彻底卸载并清理完毕！\033[0m"
}

# 安全审计与修复 (新增)
function audit_and_fix() {
    RED='\033[31m'
    GREEN='\033[32m'
    YELLOW='\033[33m'
    BLUE='\033[34m'
    NC='\033[0m'

    echo -e "${BLUE}======================================================${NC}"
    echo -e "${BLUE}       CentOS 7 全自动安全审计与加固脚本 (整合版)     ${NC}"
    echo -e "${BLUE}======================================================${NC}"

    if [ "$EUID" -ne 0 ]; then
      echo -e "${RED}[错误] 必须使用 root 权限运行此操作！${NC}"
      return 1
    fi

    echo -e "\n${YELLOW}[+] 阶段 1: 备份核心配置文件...${NC}"
    BACKUP_DIR="/root/sec_backup_$(date +%F_%H%M%S)"
    mkdir -p "$BACKUP_DIR"
    cp /etc/ssh/sshd_config /etc/sysctl.conf /etc/passwd /etc/shadow "$BACKUP_DIR/" 2>/dev/null
    cp -r /etc/yum.repos.d "$BACKUP_DIR/yum.repos.d.bak"
    echo -e "${GREEN}[完成] 备份已保存至: $BACKUP_DIR${NC}"

    echo -e "\n${YELLOW}[+] 阶段 2: 检测并修复 Yum 软件源...${NC}"
    echo -e "${BLUE}[操作] 正在将 CentOS 源替换为 Aliyun Vault 归档源...${NC}"
    curl -s -o /etc/yum.repos.d/CentOS-Base.repo https://mirrors.aliyun.com/repo/Centos-7.repo
    sed -i -e '/mirrors.cloud.aliyuncs.com/d' -e '/mirrors.aliyuncs.com/d' /etc/yum.repos.d/CentOS-Base.repo
    sed -i 's/mirror.centos.org/vault.centos.org/g' /etc/yum.repos.d/CentOS-Base.repo
    sed -i 's/^#.*baseurl=http/baseurl=http/g' /etc/yum.repos.d/CentOS-Base.repo
    sed -i 's/^mirrorlist=http/#mirrorlist=http/g' /etc/yum.repos.d/CentOS-Base.repo
    yum clean all >/dev/null 2>&1
    yum makecache >/dev/null 2>&1
    echo -e "${GREEN}[完成] 软件源修复成功。${NC}"

    echo -e "\n${YELLOW}[+] 阶段 3: 检测并禁用高危明文服务...${NC}"
    DANGER_SERVICES="telnet rsh rlogin vsftpd rpcbind"
    for service in $DANGER_SERVICES; do
        if systemctl is-active --quiet $service 2>/dev/null; then
            echo -e "${RED}[检测] 发现高危服务正在运行: $service${NC}"
            systemctl stop $service 2>/dev/null
            systemctl disable $service 2>/dev/null
            echo -e "${GREEN}[修复] 已停止并禁用服务: $service${NC}"
        fi
    done
    echo -e "${GREEN}[完成] 高危服务排查完毕。${NC}"

    echo -e "\n${YELLOW}[+] 阶段 4: 检测并收紧关键文件权限...${NC}"
    chmod 644 /etc/passwd
    chmod 400 /etc/shadow
    chmod 644 /etc/group
    chmod 400 /etc/gshadow
    echo -e "${GREEN}[完成] /etc/passwd (644) 与 /etc/shadow (400) 权限已重置。${NC}"

    echo -e "\n${YELLOW}[+] 阶段 5: 应用 SSH 安全基线配置...${NC}"
    sed -i 's/^#*PermitEmptyPasswords.*/PermitEmptyPasswords no/g' /etc/ssh/sshd_config
    sed -i 's/^#*UseDNS.*/UseDNS no/g' /etc/ssh/sshd_config
    sed -i 's/^#*ClientAliveInterval.*/ClientAliveInterval 600/g' /etc/ssh/sshd_config
    sed -i 's/^#*ClientAliveCountMax.*/ClientAliveCountMax 3/g' /etc/ssh/sshd_config
    systemctl restart sshd
    echo -e "${GREEN}[完成] SSH 加固已完成并重启服务。${NC}"

    echo -e "\n${YELLOW}[+] 阶段 6: 注入内核抗攻击参数...${NC}"
    sed -i '/net.ipv4.tcp_syncookies/d' /etc/sysctl.conf
    sed -i '/accept_redirects/d' /etc/sysctl.conf
    sed -i '/send_redirects/d' /etc/sysctl.conf
    sed -i '/icmp_echo_ignore_broadcasts/d' /etc/sysctl.conf
    cat >> /etc/sysctl.conf << EOF
net.ipv4.tcp_syncookies = 1
net.ipv4.conf.all.accept_redirects = 0
net.ipv4.conf.default.accept_redirects = 0
net.ipv4.conf.all.send_redirects = 0
net.ipv4.conf.default.send_redirects = 0
net.ipv4.icmp_echo_ignore_broadcasts = 1
EOF
    sysctl -p >/dev/null 2>&1
    echo -e "${GREEN}[完成] 成功开启 SYN 洪水保护并禁用 ICMP 路由重定向。${NC}"

    echo -e "\n${YELLOW}[+] 阶段 7: 扫描异常后门账户...${NC}"
    SUPER_USERS=$(awk -F: '($3 == "0" && $1 != "root") {print $1}' /etc/passwd)
    if [ -n "$SUPER_USERS" ]; then
        echo -e "${RED}[严重警告] 发现非 root 的特权账户 (UID=0): ${SUPER_USERS}，请手动核实并删除！${NC}"
    else
        echo -e "${GREEN}[安全] 未发现异常 UID=0 账户。${NC}"
    fi

    echo -e "\n${YELLOW}[+] 阶段 8: 执行 Yum 官方安全补丁自动更新...${NC}"
    echo -e "${BLUE}[操作] 正在安装 yum 安全更新插件...${NC}"
    yum install -y yum-plugin-security >/dev/null 2>&1
    echo -e "${BLUE}[操作] 正在扫描并安装安全更新 (可能需要几分钟)...${NC}"
    yum update --security -y
    echo -e "${GREEN}[完成] 系统安全补丁检测与更新流程结束。${NC}"
}

# ==================== 菜单区 ====================

echo "------------------------------------------------------------"
echo 'CentOS 7 Configure By Puck:'
echo "1) Install Software More"      # 安装常用软件
echo "2) Test Serve Host Puck"       # 测试服务器
echo "3) iptables port"              # 配置网络防火墙
echo "4) AppNode web"                # Web管理软件安装
echo "5) Minecraft"                  # 安装我的世界服务器
echo "6) Port nmap"                  # 端口检测
echo "7) Poweroff"                   # 关机
echo "8) Fooocus"                    # 画图
echo "9) Security Audit & Fix"       # [新增] 系统安全检测与加固修复
echo "10) Uninstall AppNode"         # [新增] 彻底卸载AppNode控制中心/受控端
echo "q) Exit!"
echo "------------------------------------------------------------"
read -p "请输入选项:" cof

case $cof in      
    1) 
        echo "------------------------------------------------------------"
        echo 'Software Install By TCQ233:'
        echo "1) PHP74 MySQL56" #安装 php74 mysql56
        echo "2) Python3"       #安装Python3          
        echo "3) jupyter"       #安装常用网络工具
        echo "4) Nginx"         #安装 nginx 
        echo "5) BTCN-5"        #安装 宝塔 
        echo "6) termux"        #安装 termux 
        echo "q) Exit!"
        echo "------------------------------------------------------------"
        read -p "请输入选项:" ins
        case $ins in    
            1)
                INSTALL_AGENT=1 INSTALL_APPS=sitemgr INIT_SWAPFILE=1 INSTALL_PKGS='nginx-stable,php74,mysql56' bash -c "$(curl -sS http://dl.appnode.com/install.sh)"
            ;;
            2)
                p3
            ;;
            3)
                jr2     
            ;;
            4)
                nx
            ;;
            5)
                if [ -f /usr/bin/curl ];then curl -sSO https://download.bt.cn/install/install_panel.sh;else wget -O install_panel.sh https://download.bt.cn/install/install_panel.sh;fi;bash install_panel.sh ed8484bec
            ;;
            6)
                curl -Lso- https://datxy.com/sh/ts.sh | bash
            ;;
            q)
                exit
            ;;  
            *)
                echo 'Input Error'
                exit
            ;;                             
        esac    
    ;;    
    2)
        curl -Lso- https://datxy.com/sh/host.sh | bash
    ;;
    3)
        ips 
    ;;  
    4) 
        bash -c "$(curl -sS http://dl.appnode.com/install.sh)"
    ;;  
    5)
        bash -c "$(curl -sS https://datxy.com/sh/mcs.sh)" 
    ;;
    6)
        yum install -y nmap
        nmap localhost
    ;; 
    7)
        poweroff
    ;; 
    8)
        sudo apt update || sudo yum update
        sudo apt install git || sudo yum install git
        pip install pygit2==1.12.2
        git clone https://github.com/lllyasviel/Fooocus.git
        cd Fooocus
        python entry_with_update.py --share --always-high-vram
    ;; 
    9)
        audit_and_fix
    ;;
    10)
        uninstall_appnode
    ;;
    q)
        exit
    ;;  
    *)
        echo 'Input Error'
        exit
    ;;  
esac
