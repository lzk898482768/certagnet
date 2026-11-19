#!/bin/sh
#
# 证照到期管理系统 —— 飞牛 fnOS 一键安装
#
# 用法：
#   sh install.sh
#
# 脚本会：
#   1. 检查 Docker / Docker Compose
#   2. 首次运行时基于 .env.example 生成随机密码
#   3. 执行 docker compose up -d --build（自动构建镜像并安装 PHP 扩展、nginx 等依赖）
#   4. 启动 MySQL 8 / Redis 7，并由容器入口自动建库、导入数据、创建管理员
#
set -eu

cd "$(dirname "$0")"

log() {
    printf '%s [install] %s\n' "$(date '+%Y-%m-%d %H:%M:%S')" "$*"
}

# ---------------- 环境检查 ----------------
if ! command -v docker >/dev/null 2>&1; then
    echo "错误：未找到 docker。请先在飞牛应用中心安装并启动 Docker。" >&2
    exit 1
fi

if ! docker compose version >/dev/null 2>&1; then
    echo "错误：未找到 docker compose（需要 Compose v2）。请升级飞牛 Docker 应用。" >&2
    exit 1
fi

if ! docker info >/dev/null 2>&1; then
    echo "错误：当前用户没有 Docker 权限。请使用 sudo sh install.sh，或把用户加入 docker 组。" >&2
    exit 1
fi

if ! command -v openssl >/dev/null 2>&1 && ! command -v od >/dev/null 2>&1; then
    echo "错误：需要 openssl 或 od 生成随机密码。请先安装 openssl（或 coreutils）。" >&2
    exit 1
fi

# 生成指定字节数的十六进制随机串（优先 openssl，缺失时退回 /dev/urandom）
rand_hex() {
    if command -v openssl >/dev/null 2>&1; then
        openssl rand -hex "$1"
    else
        od -An -N "$1" -tx1 /dev/urandom | tr -d ' \n'
    fi
}

# ---------------- 生成 .env ----------------
if [ ! -f .env ]; then
    cp .env.example .env
    log "未找到 .env，已从 .env.example 创建"
fi

# 兼容 Windows 编辑器：去掉 UTF-8 BOM 与 CRLF，避免第一项配置被忽略
sed -i '1s/^\xEF\xBB\xBF//; s/\r$//' .env

# 确保文件以换行结尾，避免追加的键值粘到最后一行
if [ -s .env ] && [ -n "$(tail -c 1 .env)" ]; then
    printf '\n' >> .env
fi

# 只填充空值，已有配置一律保留
fill_empty() {
    key="$1"
    value="$2"
    if grep -q "^${key}=." .env; then
        return 0
    fi
    if grep -q "^${key}=" .env; then
        awk -v k="$key" -v v="$value" -F= '
            $1 == k { print k "=" v; next }
            { print }
        ' .env > .env.tmp
        mv .env.tmp .env
    else
        printf '%s=%s\n' "$key" "$value" >> .env
    fi
}

fill_empty DB_ROOT_PASSWORD "$(rand_hex 24)"
fill_empty REDIS_PASSWORD "$(rand_hex 24)"
fill_empty GOV_PASSWORD_KEY "$(rand_hex 32)"
fill_empty ADMIN_PASS "$(rand_hex 12)"
chmod 600 .env
log "配置已就绪（空密码项已自动生成随机值，请妥善备份 .env）"

admin_user=$(awk -F= '/^ADMIN_USER=/{print $2; exit}' .env)
admin_pass=$(awk -F= '/^ADMIN_PASS=/{print $2; exit}' .env)
http_port=$(awk -F= '/^HTTP_PORT=/{print $2; exit}' .env)

# ---------------- 构建并启动 ----------------
log "开始构建并启动（首次构建约 5～15 分钟，会自动安装 PHP 扩展 / nginx / supervisor 等依赖）..."
docker compose up -d --build

log "容器已启动。初始化日志：docker compose logs -f app"
log "授权入口：http://<飞牛IP>:${http_port:-8080}/setup.php"
log "登录入口：http://<飞牛IP>:${http_port:-8080}/web/"
log "管理员账号：${admin_user:-admin}"
log "管理员密码：${admin_pass:-请查看 .env 中的 ADMIN_PASS}"
