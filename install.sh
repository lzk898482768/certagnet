#!/bin/sh
#
# 证照到期管理系统 —— 飞牛 fnOS 一键安装
#
# 用法：
#   sh install.sh              本地构建模式（docker-compose.yml，需要访问 Docker Hub）
#   sh install.sh --acr        阿里云 ACR 模式（docker-compose.acr.yml，只拉镜像不构建）
#
# ACR 模式可选参数 / 环境变量：
#   --tag 2.99.81             覆盖 .env 中的 ACR_TAG（升级时用）
#   ACR_USERNAME / ACR_PASSWORD  已设置时自动 docker login，不交互
#
# 脚本会：
#   1. 检查 Docker / Docker Compose
#   2. 首次运行时基于 .env.example 生成 .env，并自动填充随机密码
#   3. ACR 模式：按需登录 ACR、拉取全部镜像
#   4. 启动 MySQL 8 / Redis 7 / 应用容器，入口自动建库、导入数据、创建管理员
#
set -eu

cd "$(dirname "$0")"

MODE="build"
OVERRIDE_TAG=""

while [ $# -gt 0 ]; do
    case "$1" in
        --acr)
            MODE="acr"
            shift
            ;;
        --tag)
            OVERRIDE_TAG="${2:?--tag 需要一个版本号参数，例如 --tag 2.99.81}"
            shift 2
            ;;
        -h|--help)
            sed -n '2,20p' "$0"
            exit 0
            ;;
        *)
            echo "未知参数：$1（可用：--acr、--tag <版本号>）" >&2
            exit 1
            ;;
    esac
done

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

# 覆盖已有键值（用于 --tag 升级）
set_value() {
    key="$1"
    value="$2"
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

# ---------------- ACR 模式 ----------------
if [ "$MODE" = "acr" ]; then
    # 再次清理 CRLF，确保后续 awk 取到的值不含 \r（Windows 编辑/复制场景）
    sed -i 's/\r$//' .env
    acr_registry=$(awk -F= '/^ACR_REGISTRY=/{sub(/\r$/,"",$2); print $2; exit}' .env)
    acr_registry="${acr_registry:-crpi-e8ivxiujwrjswkb4.cn-beijing.personal.cr.aliyuncs.com}"
    acr_namespace=$(awk -F= '/^ACR_NAMESPACE=/{sub(/\r$/,"",$2); print $2; exit}' .env)
    acr_namespace="${acr_namespace:-certagent}"

    if [ -n "$OVERRIDE_TAG" ]; then
        set_value ACR_TAG "$OVERRIDE_TAG"
        log "ACR_TAG 已设置为 $OVERRIDE_TAG"
    fi

    # 默认 ACR 账号（非密钥，可入库）；密码仍只通过环境变量/.env/交互输入
    ACR_DEFAULT_USERNAME="240623475@qq.com"

    # 也允许把凭据写在 .env 中（ACR_USERNAME / ACR_PASSWORD），实现复制后零交互
    if [ -z "${ACR_USERNAME:-}" ]; then
        ACR_USERNAME=$(awk -F= '/^ACR_USERNAME=/{sub(/\r$/,"",$2); print $2; exit}' .env)
    fi
    [ -z "${ACR_USERNAME:-}" ] && ACR_USERNAME="$ACR_DEFAULT_USERNAME"
    if [ -z "${ACR_PASSWORD:-}" ]; then
        ACR_PASSWORD=$(awk -F= '/^ACR_PASSWORD=/{sub(/\r$/,"",$2); print $2; exit}' .env)
    fi

    # 密码优先级：环境变量 > .env > 交互输入（隐藏输入，走 --password-stdin，不进日志）
    if [ -z "${ACR_PASSWORD:-}" ]; then
        if [ -t 0 ]; then
            printf '请输入 ACR 访问凭证（账号 %s，输入时不显示）：' "$ACR_USERNAME"
            stty -echo
            read -r ACR_PASSWORD
            stty echo
            printf '\n'
        else
            echo "错误：非交互终端且未提供 ACR_PASSWORD。请用 ACR_PASSWORD 环境变量，或在 .env 填写后重试。" >&2
            exit 1
        fi
    fi
    log "登录 ACR：$acr_registry（账号 $ACR_USERNAME）"
    printf '%s' "$ACR_PASSWORD" | docker login "$acr_registry" \
        --username "$ACR_USERNAME" --password-stdin
    unset ACR_PASSWORD

    log "从 ACR 拉取全部镜像（registry=$acr_registry namespace=$acr_namespace）..."
    if ! docker compose -f docker-compose.acr.yml --env-file .env pull; then
        echo "错误：镜像拉取失败。请确认：" >&2
        echo "  1. 已在 ACR 控制台创建命名空间 $acr_namespace 且镜像已推送" >&2
        echo "  2. 私有仓库已执行 docker login $acr_registry" >&2
        echo "  3. 飞牛能访问公网（或已配置 ACR VPC/专线地址）" >&2
        exit 1
    fi

    log "启动容器（ACR 模式，不本地构建）..."
    docker compose -f docker-compose.acr.yml --env-file .env up -d
else
    if [ -n "$OVERRIDE_TAG" ]; then
        echo "错误：--tag 仅能与 --acr 一起使用" >&2
        exit 1
    fi
    # ---------------- 本地构建模式 ----------------
    log "开始构建并启动（首次构建约 5～15 分钟，会自动安装 PHP 扩展 / nginx / supervisor 等依赖）..."
    docker compose up -d --build
fi

log "容器已启动。初始化日志：docker compose logs -f app"
log "授权入口：http://<飞牛IP>:${http_port:-8080}/setup.php"
log "登录入口：http://<飞牛IP>:${http_port:-8080}/web/"
log "管理员账号：${admin_user:-admin}"
log "管理员密码：${admin_pass:-请查看 .env 中的 ADMIN_PASS}"
