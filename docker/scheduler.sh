#!/bin/sh
#
# 定时任务（飞牛 fnOS 镜像内置，由 supervisor 常驻，无需在 NAS 系统配置 crontab）
#
# 稳定性设计（消息推送可靠的关键）：
#   1. 每轮执行两条任务，单条任务用 timeout 限时，避免政务网 HTTP 挂起导致
#      整个调度循环永久卡死、连带证书到期提醒停摆；
#   2. 非阻塞文件锁防重入：上一轮尚未结束时本轮直接跳过，绝不并发执行
#      （业务层 cert:checkexpire 已按「证书×节点×当天」幂等，锁是双保险）；
#   3. 心跳文件 runtime/scheduler.heartbeat：每轮更新时间戳，便于从外部判断
#      调度器是否存活；任务结果追加到 runtime/log/scheduler.log；
#   4. 工具缺失自动降级：无 timeout 时退化为不限时（保持原行为），无 mkdir 等
#      基础工具的极端环境也不会让调度器直接退出；
#   5. 收到 TERM/INT 优雅退出，并释放锁文件。
#
# 可调项（经容器环境变量 / compose 注入，默认按 4GB NAS 调优）：
#   FNOS_SCHED_INTERVAL        循环间隔秒，默认 3600（每小时）
#   FNOS_TASK_TIMEOUT_CHECK    到期检测超时秒，默认 900（15 分钟）
#   FNOS_TASK_TIMEOUT_GOV      政务同步超时秒，默认 1500（25 分钟）
#   FNOS_SCHED_START_DELAY     启动后首次执行前等待秒，默认 30
#
set -u

APP_DIR=/var/www/backend
RUNTIME_DIR="$APP_DIR/runtime"
LOG_DIR="$RUNTIME_DIR/log"
LOCK_FILE="$RUNTIME_DIR/scheduler.lock"
HEARTBEAT="$RUNTIME_DIR/scheduler.heartbeat"
LOG_FILE="$LOG_DIR/scheduler.log"

INTERVAL="${FNOS_SCHED_INTERVAL:-3600}"
TIMEOUT_CHECK="${FNOS_TASK_TIMEOUT_CHECK:-900}"
TIMEOUT_GOV="${FNOS_TASK_TIMEOUT_GOV:-1500}"
START_DELAY="${FNOS_SCHED_START_DELAY:-30}"

stop=0
# 正常退出 / 被信号终止时释放锁，避免残留锁挡住后续调度
cleanup() {
    stop=1
    rm -f "$LOCK_FILE" 2>/dev/null || true
}
trap cleanup TERM INT

# 日志同时输出到 stdout（docker logs 可见）与 runtime/log/scheduler.log（持久、可回溯）
log() {
    line="$(date '+%Y-%m-%d %H:%M:%S') [fnos-scheduler] $*"
    echo "$line"
    if mkdir -p "$LOG_DIR" 2>/dev/null; then
        echo "$line" >> "$LOG_FILE" 2>/dev/null || true
    fi
}

# 带超时执行：有 timeout 命令则限时，超时返回 124；无该命令则直接执行（降级）
run_limited() {
    timeout_sec="$1"; shift
    if command -v timeout >/dev/null 2>&1; then
        timeout "$timeout_sec" "$@"
    else
        "$@"
    fi
}

# 等待首次安装与自动迁移完成
log "调度器启动，${START_DELAY}s 后开始首轮任务，间隔 ${INTERVAL}s"
sleep "$START_DELAY" &
wait $! 2>/dev/null || true

mkdir -p "$LOG_DIR" 2>/dev/null || true

while [ "$stop" -eq 0 ]; do
    # 非阻塞锁：mkdir 原子创建，成功才执行；上一轮没跑完则跳过本轮
    if ! mkdir "$LOCK_FILE" 2>/dev/null; then
        log "上一轮任务仍在执行，本轮跳过（防重入）"
    else
        # 心跳：先标记本轮开始
        date '+%Y-%m-%d %H:%M:%S' > "$HEARTBEAT" 2>/dev/null || true

        log "开始执行 cert:checkexpire --catch-up（超时 ${TIMEOUT_CHECK}s）"
        if run_limited "$TIMEOUT_CHECK" php "$APP_DIR/think" cert:checkexpire --catch-up; then
            log "cert:checkexpire 完成"
        else
            rc=$?
            if [ "$rc" -eq 124 ]; then
                log "cert:checkexpire 超时（>${TIMEOUT_CHECK}s）已终止，等待下一轮重试"
            else
                log "cert:checkexpire 执行失败(rc=$rc)，等待下一轮重试"
            fi
        fi

        # 中途收到停止信号则不再继续
        if [ "$stop" -eq 0 ]; then
            log "开始执行 gov:sync（超时 ${TIMEOUT_GOV}s）"
            if run_limited "$TIMEOUT_GOV" php "$APP_DIR/think" gov:sync; then
                log "gov:sync 完成"
            else
                rc=$?
                if [ "$rc" -eq 124 ]; then
                    log "gov:sync 超时（>${TIMEOUT_GOV}s）已终止，等待下一轮重试"
                else
                    log "gov:sync 执行失败(rc=$rc)，等待下一轮重试"
                fi
            fi
        fi

        # 本轮结束更新心跳并释放锁
        date '+%Y-%m-%d %H:%M:%S' > "$HEARTBEAT" 2>/dev/null || true
        rmdir "$LOCK_FILE" 2>/dev/null || rm -rf "$LOCK_FILE" 2>/dev/null || true
    fi

    # 可被信号打断的睡眠
    sleep "$INTERVAL" &
    wait $! 2>/dev/null || true
done

log "调度器已停止"
exit 0
