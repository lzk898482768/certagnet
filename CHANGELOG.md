# 更新日志

## v2.99.80 — ACR 镜像 tag 与部署版本对齐（2026-11-20）

- GitHub Actions 已推送 `certagent/certagent:2.99.78`、`:2.99.79`、`:latest`，基础镜像 php / mysql / redis 齐备
- `.env.example` 默认 `ACR_TAG` 改为固定版本号 `2.99.80`，避免直接用 `latest` 导致回滚困难
- README / `tools/push-acr.sh` 示例 tag 同步更新

## v2.99.79 — 发布渠道切换为 GitHub（2026-11-20）

- 飞牛版后续只发布到 GitHub `lzk898482768/certagnet`，不再推送 Gitee
- README 克隆地址与说明改为 GitHub，仓库已公开，可直接 HTTPS 克隆
- 影响范围仅文档与发布流程，镜像与编排无变化

## v2.99.78 — 预置 ACR certagent 仓库并新增 GitHub Actions（2026-11-20）

- 默认 ACR 命名空间设为 `certagent`，应用镜像仓库设为 `certagent`
- 新增 `.github/workflows/acr-push.yml`：在 GitHub Actions 中手动推送 php / mysql / redis / 应用镜像
- `tools/push-acr.sh`、`docker-compose.acr.yml`、`.env.example`、README 同步更新
- 工作流只读取 GitHub Secrets `ACR_USERNAME` / `ACR_PASSWORD`，不包含任何密码

## v2.99.77 — 支持阿里云 ACR 镜像推送与部署（2026-11-20）

- 新增 `tools/push-acr.sh`：把 `php:8.2-fpm-bookworm`、`mysql:8.0`、`redis:7-alpine` 与应用镜像推送到阿里云 ACR
- 新增 `docker-compose.acr.yml`：全部镜像从 ACR 拉取，飞牛无需访问 Docker Hub、也无需本地构建
- `Dockerfile` 支持 `PHP_BASE_IMAGE` 构建参数；默认编排支持 `MYSQL_IMAGE` / `REDIS_IMAGE`
- `.env.example` 与 README 增加 ACR 命名空间、标签、登录与升级说明
- 不包含任何账号密码；ACR 凭据只通过环境变量传入

## v2.99.76 — 修复飞牛「构建」卡在拉取本地镜像（2026-11-20）

- 移除 `docker-compose.yml` 中 app 服务的 `image:` 声明：避免 Compose 构建前先尝试从 Docker Hub 拉取本地镜像名 `cert-expiry-manager-fnos:latest`，导致飞牛「构建」长时间停在 `Pulling`
- 镜像名改由 Compose 根据项目名自动生成，`docker compose build` / `up -d --build` 直接本地构建
- `README` 增加卡住原因、升级步骤与旧版本手工修复方法
- 不影响数据库、`.env`、`data/` 目录与已有容器数据

## v2.99.75 — 登录菜单树兜底过滤在线升级（2026-11-20）

- 登录接口输出菜单树前强制移除 74 号「在线升级」，避免旧 Redis 缓存或 60 秒节流期间仍显示入口
- 配合 v2.99.74 的菜单隐藏与接口拒绝，确保升级后侧边栏立即不可见
- 同步主程序 v2.99.75

## v2.99.74 — 飞牛版关闭在线升级入口（2026-11-20）

- 飞牛版不再显示「在线升级」菜单，升级相关接口统一拒绝请求
- 升级统一使用 `git pull` + `docker compose up -d --build`，避免页面升级破坏镜像一致性
- 同步主程序 v2.99.74

## v2.99.73 — 首个飞牛 fnOS 部署版本（2026-11-19）

- 新增飞牛 fnOS 单容器镜像：nginx + php-fpm + 定时任务由 supervisor 托管
- 新增 `install.sh` 一键安装：检查 Docker / Compose、自动生成 `.env` 随机密码并执行 `docker compose up -d --build`
- 新增 MySQL 8 / Redis 7 独立容器编排，默认独立数据库 `cert_agent_fnos`
- 安装器禁止使用 `cert_agent` 及 MySQL 系统库名，避免误覆盖现有生产数据
- 首次启动自动建库、导入结构与种子数据、创建管理员、生成 `.env`
- 附件、配置、运行日志与授权文件通过 `./data/` 持久化
- 内置每小时证书到期提醒补跑与政务网同步任务
- 提供从主仓库同步代码的脚本 `tools/sync-from-main.mjs`
