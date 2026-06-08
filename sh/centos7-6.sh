#!/bin/bash
# ==========================================================
# 脚本名称: CentOS 7 全自动安全检测与漏洞修复整合脚本
# 执行逻辑: 状态检测 -> 自动备份 -> 自动修复 -> 结果输出
# yum install -y dos2unix 
# dos2unix centos7-6.sh
# ==========================================================

RED='\033[31m'
GREEN='\033[32m'
YELLOW='\033[33m'
BLUE='\033[34m'
NC='\033[0m'

echo -e "${BLUE}======================================================${NC}"
echo -e "${BLUE}       CentOS 7 全自动安全审计与加固脚本 (整合版)     ${NC}"
echo -e "${BLUE}======================================================${NC}"

# 1. 权限检查
if [ "$EUID" -ne 0 ]; then
  echo -e "${RED}[错误] 必须使用 root 权限运行此脚本！${NC}"
  exit 1
fi

# 2. 自动备份
echo -e "\n${YELLOW}[+] 阶段 1: 备份核心配置文件...${NC}"
BACKUP_DIR="/root/sec_backup_$(date +%F_%H%M%S)"
mkdir -p "$BACKUP_DIR"
cp /etc/ssh/sshd_config /etc/sysctl.conf /etc/passwd /etc/shadow "$BACKUP_DIR/" 2>/dev/null
cp -r /etc/yum.repos.d "$BACKUP_DIR/yum.repos.d.bak"
echo -e "${GREEN}[完成] 备份已保存至: $BACKUP_DIR${NC}"

# 3. 修复 CentOS 7 官方源 (解决停更导致无法 yum update 的问题)
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

# 4. 危险服务检测与禁用
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

# 5. 敏感文件权限修复
echo -e "\n${YELLOW}[+] 阶段 4: 检测并收紧关键文件权限...${NC}"
chmod 644 /etc/passwd
chmod 400 /etc/shadow
chmod 644 /etc/group
chmod 400 /etc/gshadow
echo -e "${GREEN}[完成] /etc/passwd (644) 与 /etc/shadow (400) 权限已重置。${NC}"

# 6. SSH 安全基线强制应用
echo -e "\n${YELLOW}[+] 阶段 5: 应用 SSH 安全基线配置...${NC}"
# 禁止空密码
sed -i 's/^#*PermitEmptyPasswords.*/PermitEmptyPasswords no/g' /etc/ssh/sshd_config
# 关闭 DNS 解析防卡顿
sed -i 's/^#*UseDNS.*/UseDNS no/g' /etc/ssh/sshd_config
# 设置超时自动断开 (600秒)
sed -i 's/^#*ClientAliveInterval.*/ClientAliveInterval 600/g' /etc/ssh/sshd_config
sed -i 's/^#*ClientAliveCountMax.*/ClientAliveCountMax 3/g' /etc/ssh/sshd_config
systemctl restart sshd
echo -e "${GREEN}[完成] SSH 加固已完成并重启服务。${NC}"

# 7. 内核网络安全参数注入
echo -e "\n${YELLOW}[+] 阶段 6: 注入内核抗攻击参数...${NC}"
# 清理可能重复的旧配置
sed -i '/net.ipv4.tcp_syncookies/d' /etc/sysctl.conf
sed -i '/accept_redirects/d' /etc/sysctl.conf
sed -i '/send_redirects/d' /etc/sysctl.conf
sed -i '/icmp_echo_ignore_broadcasts/d' /etc/sysctl.conf
# 写入新配置
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

# 8. 账户特权扫描 (仅输出警告，不自动删号防误杀)
echo -e "\n${YELLOW}[+] 阶段 7: 扫描异常后门账户...${NC}"
SUPER_USERS=$(awk -F: '($3 == "0" && $1 != "root") {print $1}' /etc/passwd)
if [ -n "$SUPER_USERS" ]; then
    echo -e "${RED}[严重警告] 发现非 root 的特权账户 (UID=0): ${SUPER_USERS}，请手动核实并删除！${NC}"
else
    echo -e "${GREEN}[安全] 未发现异常 UID=0 账户。${NC}"
fi

# 9. 自动化安全补丁更新
echo -e "\n${YELLOW}[+] 阶段 8: 执行 Yum 官方安全补丁自动更新...${NC}"
echo -e "${BLUE}[操作] 正在安装 yum 安全更新插件...${NC}"
yum install -y yum-plugin-security >/dev/null 2>&1
echo -e "${BLUE}[操作] 正在扫描并安装安全更新 (可能需要几分钟)...${NC}"
yum update --security -y
echo -e "${GREEN}[完成] 系统安全补丁检测与更新流程结束。${NC}"

echo -e "\n${BLUE}======================================================${NC}"
echo -e "${GREEN}🎉 综合扫描与自动修复任务全部完成！${NC}"
echo -e "${YELLOW}提示: 如果内核或 Glibc 发生了更新，建议稍后执行 reboot 重启服务器。${NC}"
echo -e "${BLUE}======================================================${NC}"
