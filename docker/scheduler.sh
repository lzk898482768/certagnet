#!/bin/sh
#
# 定时任务（飞牛 fnOS 镜像内置，由 supervisor 常驻）
#
# 每小时执行一次：
#   - cert:checkexpire --catch-up  补跑最近 3 天漏掉的证书到期提醒
#   - gov:sync                     政务网申报进度同步（命令内部按 daily_min_hours 幂等）
#
set -u

stop=0
trap 'stop=1' TERM INT

# 等待首次安装与自动迁移完成
sleep 30

while [ "$stop" -eq 0 ]; do
    php /var/www/backend/think cert:checkexpire --catch-up || echo "[fnos-scheduler] cert:checkexpire 执行失败"
    php /var/www/backend/think gov:sync || echo "[fnos-scheduler] gov:sync 执行失败"

    sleep 3600 &
    wait $! 2>/dev/null || true
done

exit 0
