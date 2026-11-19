#!/bin/sh
#
# 证照到期管理系统 —— 飞牛 fnOS 容器入口
#
# 职责：
#   1. 准备持久化目录（配置 / runtime / 上传附件）
#   2. 等待 MySQL 与 Redis 就绪
#   3. 首次启动自动建库、导入结构与种子数据、创建管理员（独立数据库）
#   4. 交给 supervisor 启动 nginx、php-fpm、定时任务
#
set -eu

APP_DIR=/var/www/backend
DATA_CONFIG=/data/config
RUNTIME_DIR="$APP_DIR/runtime"
UPLOAD_DIR="$APP_DIR/public/uploads"

log() {
    printf '%s [fnos] %s\n' "$(date '+%Y-%m-%d %H:%M:%S')" "$*"
}

# ---------------- 默认值 ----------------
: "${FNOS_DB_HOST:=mysql}"
: "${FNOS_DB_PORT:=3306}"
: "${FNOS_DB_NAME:=cert_agent_fnos}"
: "${FNOS_DB_USER:=root}"
: "${FNOS_DB_PASS:=}"
: "${FNOS_REDIS_HOST:=redis}"
: "${FNOS_REDIS_PORT:=6379}"
: "${FNOS_REDIS_PASSWORD:=}"
: "${FNOS_REDIS_DB:=0}"
: "${FNOS_REDIS_PREFIX:=cert:}"
: "${FNOS_ADMIN_USER:=admin}"
: "${FNOS_ADMIN_PASS:=}"
: "${FNOS_ADMIN_NAME:=系统管理员}"
export FNOS_DB_HOST FNOS_DB_PORT FNOS_DB_NAME FNOS_DB_USER FNOS_DB_PASS
export FNOS_REDIS_HOST FNOS_REDIS_PORT FNOS_REDIS_PASSWORD FNOS_REDIS_DB FNOS_REDIS_PREFIX
export FNOS_ADMIN_USER FNOS_ADMIN_PASS FNOS_ADMIN_NAME

if [ -z "$FNOS_DB_PASS" ]; then
    log "错误：未设置数据库密码（DB_ROOT_PASSWORD）"
    exit 1
fi
if [ -z "$FNOS_REDIS_PASSWORD" ]; then
    log "错误：未设置 Redis 密码（REDIS_PASSWORD）"
    exit 1
fi
if [ -z "$FNOS_ADMIN_PASS" ]; then
    log "错误：未设置管理员初始密码（ADMIN_PASS）"
    exit 1
fi

# ---------------- 目录 ----------------
mkdir -p "$DATA_CONFIG" \
    "$RUNTIME_DIR/backup" \
    "$RUNTIME_DIR/cache" \
    "$RUNTIME_DIR/export" \
    "$RUNTIME_DIR/log" \
    "$RUNTIME_DIR/temp" \
    "$RUNTIME_DIR/upgrade" \
    "$RUNTIME_DIR/upload" \
    "$RUNTIME_DIR/vault" \
    "$UPLOAD_DIR"

# .env 持久化到 /data/config，容器重建不丢失
if [ ! -L "$APP_DIR/.env" ]; then
    if [ -f "$APP_DIR/.env" ]; then
        mv -f "$APP_DIR/.env" "$DATA_CONFIG/.env"
    fi
    ln -sfn "$DATA_CONFIG/.env" "$APP_DIR/.env"
fi

chown -R www-data:www-data "$RUNTIME_DIR" "$UPLOAD_DIR" "$DATA_CONFIG" 2>/dev/null || true

# ---------------- 等待依赖 ----------------
log "等待 MySQL（$FNOS_DB_HOST:$FNOS_DB_PORT）..."
i=0
while [ "$i" -lt 150 ]; do
    if php -r '
        try {
            $host = getenv("FNOS_DB_HOST");
            $port = getenv("FNOS_DB_PORT");
            $pdo = new PDO(
                "mysql:host={$host};port={$port};charset=utf8mb4",
                getenv("FNOS_DB_USER"),
                getenv("FNOS_DB_PASS"),
                [PDO::ATTR_TIMEOUT => 3, PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );
            $pdo->query("SELECT 1");
            exit(0);
        } catch (Throwable $e) {
            exit(1);
        }
    ' >/dev/null 2>&1; then
        break
    fi
    i=$((i + 1))
    sleep 2
done
if [ "$i" -ge 150 ]; then
    log "错误：等待 MySQL 超时（5 分钟），请检查 DB_ROOT_PASSWORD 与 mysql 容器日志"
    sleep 5
    exit 1
fi
log "MySQL 已就绪"

log "等待 Redis（$FNOS_REDIS_HOST:$FNOS_REDIS_PORT）..."
i=0
while [ "$i" -lt 60 ]; do
    if php -r '
        try {
            $redis = new Redis();
            if (!$redis->connect(getenv("FNOS_REDIS_HOST"), (int)getenv("FNOS_REDIS_PORT"), 2.0)) {
                exit(1);
            }
            $password = (string)getenv("FNOS_REDIS_PASSWORD");
            if ($password !== "" && !$redis->auth($password)) {
                exit(1);
            }
            if (!$redis->select((int)getenv("FNOS_REDIS_DB"))) {
                exit(1);
            }
            $key = "fnos:boot:" . bin2hex(random_bytes(4));
            if (!$redis->setex($key, 5, "ok") || $redis->get($key) !== "ok") {
                exit(1);
            }
            $redis->del($key);
            exit(0);
        } catch (Throwable $e) {
            exit(1);
        }
    ' >/dev/null 2>&1; then
        break
    fi
    i=$((i + 1))
    sleep 2
done
if [ "$i" -ge 60 ]; then
    log "错误：等待 Redis 超时（2 分钟），请检查 REDIS_PASSWORD 与 redis 容器日志"
    sleep 5
    exit 1
fi
log "Redis 已就绪"

# ---------------- 数据卷被清空时自动重装 ----------------
# 典型场景：执行过 docker compose down -v，但 data/runtime/install.lock 还在。
# 此时数据库是空的，若直接跳过安装，应用会因缺表而报错。
if [ -f "$RUNTIME_DIR/install.lock" ]; then
    db_state=$(php -r '
        try {
            $host = getenv("FNOS_DB_HOST");
            $port = getenv("FNOS_DB_PORT");
            $name = getenv("FNOS_DB_NAME");
            $pdo = new PDO(
                "mysql:host={$host};port={$port};charset=utf8mb4",
                getenv("FNOS_DB_USER"),
                getenv("FNOS_DB_PASS"),
                [PDO::ATTR_TIMEOUT => 3, PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );
            $stmt = $pdo->prepare("SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?");
            $stmt->execute([$name]);
            if (!$stmt->fetchColumn()) {
                echo "0";
                exit;
            }
            $stmt = $pdo->prepare("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?");
            $stmt->execute([$name, "sys_user"]);
            echo $stmt->fetchColumn() ? "1" : "0";
        } catch (Throwable $e) {
            echo "E";
        }
    ' 2>/dev/null || echo E)

    case "$db_state" in
        0)
            log "检测到安装锁，但数据库中没有业务表（数据卷可能被清空），将重新初始化"
            rm -f "$RUNTIME_DIR/install.lock"
            ;;
        E)
            log "警告：无法确认数据库状态，按已安装继续启动"
            ;;
    esac
fi

# ---------------- 首次安装 ----------------
if [ ! -f "$RUNTIME_DIR/install.lock" ]; then
    log "首次启动：创建独立数据库 $FNOS_DB_NAME 并初始化数据"
    if ! php /usr/local/bin/fnos-install.php; then
        log "初始化失败，请根据上方日志排查；容器将在 15 秒后退出"
        sleep 15
        exit 1
    fi
else
    if [ ! -s "$DATA_CONFIG/.env" ]; then
        log "检测到安装锁但缺少 .env，正在按环境变量重建配置"
        if ! php /usr/local/bin/fnos-install.php --env-only; then
            log "配置重建失败；容器将在 15 秒后退出"
            sleep 15
            exit 1
        fi
    fi
fi

# 安装过程由 root 执行，恢复运行用户权限
if chown -R www-data:www-data "$RUNTIME_DIR" "$UPLOAD_DIR" "$DATA_CONFIG" 2>/dev/null; then
    chmod 600 "$DATA_CONFIG/.env" 2>/dev/null || true
else
    log "警告：无法调整 data 目录属主，请确认挂载目录允许容器 root 写入"
fi

log "初始化完成，启动 nginx / php-fpm / 定时任务"
exec "$@"
