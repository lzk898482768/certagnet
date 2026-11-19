# 更新日志

## v2.99.73 — 首个飞牛 fnOS 部署版本（2026-11-19）

- 新增飞牛 fnOS 单容器镜像：nginx + php-fpm + 定时任务由 supervisor 托管
- 新增 `install.sh` 一键安装：检查 Docker / Compose、自动生成 `.env` 随机密码并执行 `docker compose up -d --build`
- 新增 MySQL 8 / Redis 7 独立容器编排，默认独立数据库 `cert_agent_fnos`
- 安装器禁止使用 `cert_agent` 及 MySQL 系统库名，避免误覆盖现有生产数据
- 首次启动自动建库、导入结构与种子数据、创建管理员、生成 `.env`
- 附件、配置、运行日志与授权文件通过 `./data/` 持久化
- 内置每小时证书到期提醒补跑与政务网同步任务
- 提供从主仓库同步代码的脚本 `tools/sync-from-main.mjs`
