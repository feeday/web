#!/bin/bash
# ==========================================================
# 脚本名称: CentOS 7.6 基础漏洞修复与安全加固脚本
# 注意事项: 请务必在测试环境验证无误后，再在生产环境执行！
# ==========================================================

# # 1. 安装工具（如果已安装可跳过）
# # yum install -y dos2unix
# 2. 转换脚本格式
# # dos2unix centos7-6.sh

# 1. 检查是否为 root 用户
if [ "$EUID" -ne 0 ]; then
  echo -e "\033[31m[错误] 请使用 root 权限运行此脚本！\033[0m"
  exit 1
fi

echo -e "\033[34m[信息] 开始执行 CentOS 7 安全加固与漏洞修复...\033[0m"

# 2. 备份核心配置文件
echo -e "\033[34m[信息] 正在备份重要配置文件...\033[0m"
BACKUP_DIR="/root/security_backup_$(date +%F_%T)"
mkdir -p "$BACKUP_DIR"
cp /etc/ssh/sshd_config "$BACKUP_DIR/"
cp /etc/sysctl.conf "$BACKUP_DIR/"
cp -r /etc/yum.repos.d "$BACKUP_DIR/"
echo "备份已保存至: $BACKUP_DIR"

# 3. 修复并替换 EOL 的 Yum 源 (替换为阿里云 Vault 源)
echo -e "\033[34m[信息] 正在配置 CentOS 7 Vault 归档源...\033[0m"
curl -o /etc/yum.repos.d/CentOS-Base.repo https://mirrors.aliyun.com/repo/Centos-7.repo
sed -i -e '/mirrors.cloud.aliyuncs.com/d' -e '/mirrors.aliyuncs.com/d' /etc/yum.repos.d/CentOS-Base.repo
sed -i 's/mirror.centos.org/vault.centos.org/g' /etc/yum.repos.d/CentOS-Base.repo
sed -i 's/^#.*baseurl=http/baseurl=http/g' /etc/yum.repos.d/CentOS-Base.repo
sed -i 's/^mirrorlist=http/#mirrorlist=http/g' /etc/yum.repos.d/CentOS-Base.repo

# 4. 更新系统软件包补丁
echo -e "\033[34m[信息] 开始更新系统软件包 (这可能需要几分钟)...\033[0m"
yum clean all
yum makecache
# 仅更新安全相关补丁和基础软件，跳过无法解决的依赖
yum update -y --skip-broken

# 5. SSH 服务安全加固 (防爆破与提权)
echo -e "\033[34m[信息] 进行 SSH 安全加固...\033[0m"
# 禁止空密码登录
sed -i 's/#PermitEmptyPasswords no/PermitEmptyPasswords no/g' /etc/ssh/sshd_config
# 关闭 DNS 解析 (加快 SSH 登录速度)
sed -i 's/#UseDNS yes/UseDNS no/g' /etc/ssh/sshd_config
# 设置 SSH 空闲超时时间为 10 分钟 (600秒)
sed -i 's/#ClientAliveInterval 0/ClientAliveInterval 600/g' /etc/ssh/sshd_config
sed -i 's/#ClientAliveCountMax 3/ClientAliveCountMax 3/g' /etc/ssh/sshd_config
systemctl restart sshd

# 6. 系统内核参数优化 (防 DDOS、IP 欺骗等)
echo -e "\033[34m[信息] 优化系统内核网络参数...\033[0m"
cat >> /etc/sysctl.conf << EOF
# 开启 SYN 洪水攻击保护
net.ipv4.tcp_syncookies = 1
# 禁用 ICMP 重定向 (防止路由欺骗)
net.ipv4.conf.all.accept_redirects = 0
net.ipv4.conf.default.accept_redirects = 0
net.ipv4.conf.all.send_redirects = 0
net.ipv4.conf.default.send_redirects = 0
# 忽略 ICMP 广播请求 (防止 SMURF 攻击)
net.ipv4.icmp_echo_ignore_broadcasts = 1
# 开启恶意的 ICMP 错误消息保护
net.ipv4.icmp_ignore_bogus_error_responses = 1
# 关闭源路由接收
net.ipv4.conf.all.accept_source_route = 0
net.ipv4.conf.default.accept_source_route = 0
EOF
sysctl -p > /dev/null 2>&1

# 7. 权限修复 (关键文件权限控制)
echo -e "\033[34m[信息] 修复关键文件权限...\033[0m"
chmod 644 /etc/passwd
chmod 400 /etc/shadow
chmod 644 /etc/group
chmod 400 /etc/gshadow

echo -e "\033[32m[完成] 基础漏洞修复与系统加固已完成！\033[0m"
echo -e "\033[33m[建议] 内核更新通常需要重启系统才能生效，建议在业务低峰期执行 'reboot' 重启服务器。\033[0m"
