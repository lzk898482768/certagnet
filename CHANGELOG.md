# 更新日志

## v2.99.88 — 修复河南建造师一键采集 429 误判与多专业选择（2026-11-25）

### 修复
- 河南建管平台近期对 `pageSize>10` 的查询直接返回伪装成 `429 TOO_MANY_REQUESTS` 的拒答，旧版把这种返回当成「未查询到证书」，导致一级/二级建造师一键采集总是失败
- 采集请求固定 `pageSize=10`，人员证书超过 10 条时自动翻页（最多 3 页）
- 注册类人员不再用平台 `specialtytypnumstr` 硬筛一级/二级，改为按身份证取回全部注册类证书后本地按所选级别优先筛选；同一人员多个专业（zczy）时弹出候选列表由业务人员选择
- 平台返回 `code≠0` 时显示真实错误，命中 429 提示稍后重试，不再静默当成「未查询到」
- 兼容平台把到期日返回为毫秒时间戳（如 `1837353600000`）
- 验证码 Cookie（`YZM_RL_BID`）现在随会话保存并回传；「换一张」同步更新会话，首次采集复用同一会话减少请求

### 说明
- 仍为人工输入图形验证码，不识别、不绕过验证码；采集仅回填表单，不自动保存

## v2.99.87 — 状态变更日志显示人员/借调信息，编辑证书支持一键采集（2026-11-20）

### 状态变更日志（借调与提醒）
- 借调状态变更记录现在直接显示**持证人员姓名**、**借调企业**与**借调起止时间**，不再只显示「#借调记录ID」
- 后端 `cert-status-log` 列表预载 `secondment`（含人员/企业）并对嵌套人员按脱敏矩阵处理；借调记录已被删除时自动回退到证书持证人
- PC 端「状态变更日志」新增「人员姓名」列，原「借调记录」列改为「借调企业 / 借调时间」
- 移动端「状态变更日志」卡片同步显示人员、借调企业与借调时间

### 证照台账
- **编辑证书时也可「一键采集」**：与新增一致，可按证书类型/专业连接河南建管平台或应急管理部平台匹配证书并回填表单（采集仅回填，不自动保存，需核对后点击「保存」）
- 采集接口权限由「仅新增」放宽为「新增或编辑」任一权限，编辑岗人员可正常使用
- 打开编辑弹窗时重置上一次的验证码/候选状态，避免串扰

## v2.99.86 — 飞牛 fnOS 全链路稳定性与性能优化，定时任务生产级加固（2026-11-20）

### 定时任务（消息推送可靠性）
- 重写 `docker/scheduler.sh`：任务级 `timeout` 限时（默认到期检测 15 分钟、政务同步 25 分钟），避免政务网 HTTP 挂起导致整个调度循环卡死、连带到期提醒停摆
- 新增非阻塞文件锁（mkdir 原子锁）防重入，上一轮未结束则本轮跳过，杜绝并发重复执行
- 新增心跳文件 `runtime/scheduler.heartbeat` 与持久日志 `runtime/log/scheduler.log`，可从外部判断调度器存活与历史结果
- 工具缺失自动降级（无 timeout 退化为不限时）、TERM/INT 优雅退出并释放锁；间隔/超时全部可经环境变量调整

### 性能（4GB NAS 为默认基线，参数全部 .env 可调，高规格 NAS 改配置即放大）
- MySQL 8：新增 InnoDB buffer pool（默认 256M）、redo log、连接数、临时表、慢查询日志等调优，全部 `MYSQL_*` 可覆盖
- Redis 7：新增内存上限（默认 256M）+ `volatile-lru` 淘汰（只淘汰带 TTL 的缓存/会话，不碰持久数据）、空闲连接回收，关闭 RDB 仅保留 AOF 减少 NAS IO，`REDIS_*` 可覆盖
- 新增 `config/session.php` 将框架原生 Session（如有启用）强制指向 Redis，杜绝任何会话文件落到 NAS 挂载卷；本系统登录态与验证码本就基于 Redis 自定义 token / 缓存，此次为防御性加固，**无迁移、无需重新登录**
- 数据库 `debug` 改为 env 控制且默认关闭，生产不再逐条记录 SQL 调试日志
- PHP-FPM 进程池参数 env 化并按 4GB 收敛（max_children 默认 12），entrypoint 启动时渲染，高规格 NAS 可调大
- nginx：拆分 main 主配置（worker_processes auto、worker_connections 2048、keepalive、sendfile），启用 gzip_static 与 fastcgi keepalive 复用
- OPcache `max_accelerated_files` 由 20000 提升到 40000，适配含 vendor 的较大文件数，避免缓存触顶后性能回落
- 两份 compose（本地构建 / ACR）保持同步；CI 的 nginx 校验同步覆盖新的主配置

### 文档
- README 重写「升级」章节（ACR 一键升级、fileMode 权限位、端口持久化、升级验证），新增「定时任务」与「性能调优」说明
- 《快速开始》补充定时任务零配置说明与调优入口

## v2.99.85 — 彻底修复接口 302 跳 /web/：改用 ThinkPHP 兼容模式重写（2026-11-20）

- 接续 v2.99.84：84 仅在 PHP 段加 `fastcgi_split_path_info`，但根 location 的兜底是裸 `/index.php`（未携带路径后缀），`PATH_INFO` 仍为空，问题未根治
- 本版把 `location /` 兜底改为 ThinkPHP 官方兼容模式 `try_files $uri $uri/ /index.php?s=$uri&$args`
- ThinkPHP `Request::pathinfo()` 读取优先级为 `$_GET['s'] > PATH_INFO > 其它`，从 `s` 参数取路由后，彻底不依赖 `cgi.fix_pathinfo` / PATH_INFO / split_path_info，任何环境下路由都能正确解析
- 保留 84 的 PHP 段显式 PATH_INFO 与诊断探针 `envprobe.php`，作为双保险与现场排障手段

## v2.99.84 — 修复 PATH_INFO 丢失导致所有接口被 302 跳到 /web/（2026-11-20）

- 现象：访问 `/api/captcha` 等任意接口均返回 `302 Location: /web/`，验证码无法加载、登录不可用
- 根因：nginx 的 PHP location 未显式设置 `PATH_INFO`，仅依赖 PHP `cgi.fix_pathinfo` 兜底；该兜底在当前环境不生效时，ThinkPHP 把 pathinfo 解析为空，所有请求命中根路由的 UA 分流
- 修复：nginx 改用 `fastcgi_split_path_info` 显式解析，并显式传递 `SCRIPT_NAME` / `PATH_INFO`，不再依赖兜底；location 调整为 `\.php(/|$)` 以承接带 path-info 的请求
- 新增独立诊断探针 `envprobe.php`（不经框架/门禁，锁死态可访问），仅回显路由相关服务器变量，便于现场确认
- CI（acr-push.yml）新增 nginx 配置语法校验步骤，配置有误在推送镜像前即失败

## v2.99.83 — 默认端口改为非常用端口 18080，适配 DDNS（2026-11-20）

- 默认访问端口由 8080 改为非常用端口 18080（`.env` 的 `HTTP_PORT`，容器内部仍为 80）
- 两个 compose、install 脚本、`.env.example`、README、`快速开始.md` 同步更新
- 明确说明：授权只校验域名不校验端口，换端口不影响授权；补 DDNS 端口映射与心跳排查指引
- 端口可在 `.env` 自行修改，修改后重新 `sh deploy-acr.sh` 并同步路由器映射

## v2.99.82 — 一行命令一键部署，ACR 账号预置、只输一次密码（2026-11-20）

- 新增极简说明 `快速开始.md`；README 顶部增加「一行命令部署」
- `install.sh --acr` 预置 ACR 账号 `240623475@qq.com`，运行时只需隐藏式输入一次密码
- `.env.example` 预置 ACR 账号（非密钥），密码仍不入库；非交互且无密码时明确报错退出
- 全新机器一行命令：`git clone ... && cd ... && sh deploy-acr.sh`

## v2.99.81 — ACR 一键部署，复制到飞牛即可零/少交互完成（2026-11-20）

- 新增 `deploy-acr.sh`：一键完成 ACR 登录、拉镜像、启动，支持 `sh deploy-acr.sh [版本号]`
- `install.sh` 新增 `--acr` / `--tag` 参数；ACR 凭据支持环境变量或 `.env` 中 `ACR_USERNAME`/`ACR_PASSWORD`
- 自动处理 Windows CRLF/BOM，密码走 `--password-stdin`，不写入日志
- `.env.example` 增加 ACR 登录字段（留空即交互/环境变量方式）；README 增加「0A ACR 一键部署」章节

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
