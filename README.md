# 飞牛 fnOS 部署仓库

证照到期管理系统的飞牛 fnOS 专用部署包：**独立 GitHub 仓库、独立 MySQL 数据库、独立数据目录**。

---

## ⚡ 一行命令部署（推荐，全程只输一次 ACR 密码）

```bash
cd /vol1/1000/docker && git clone https://github.com/lzk898482768/certagnet.git cert-expiry-manager-fnos && cd cert-expiry-manager-fnos && sh deploy-acr.sh
```

- 前提：飞牛已安装并启动 Docker。
- 运行后只需输入 **一次 ACR 密码**（输入时不显示，属正常），其余密码自动生成、镜像自动拉取、容器自动启动、数据库自动初始化。
- 已有文件夹升级：`cd cert-expiry-manager-fnos && git pull && sh deploy-acr.sh`
- 详见同目录 [`快速开始.md`](快速开始.md)。

---

- 主程序版本：v2.99.75
- 部署包版本：v2.99.87
- 部署形态：单应用容器（nginx + php-fpm + 定时任务），MySQL 8 与 Redis 7 各自独立容器
- 默认独立库名：`cert_agent_fnos`（不会读取或覆盖现有 `cert_agent` 数据）

> 本仓库由主仓库 `cert-expiry-manager` 的发布代码同步而来。业务代码请改主仓库，
> 再用 `tools/sync-from-main.mjs` 同步到本仓库，避免两份代码长期分叉。

---

## 一、环境要求

| 项目 | 要求 |
|---|---|
| 飞牛 fnOS | 已安装并启用「Docker」应用（含 Docker Compose v2） |
| 架构 | x86_64 |
| 内存 | 建议 ≥ 4 GB（MySQL 占大头；数据量大时上调） |
| 磁盘 | 建议 ≥ 10 GB 可用空间（镜像 + 数据库 + 附件） |
| 端口 | 默认 18080/TCP（非常用端口，可改）；如启用了飞牛防火墙，请放行该端口，并在路由器做 DDNS 端口映射 |

首次构建需要拉取 `php:8.2-fpm-bookworm`、`mysql:8.0`、`redis:7-alpine`，国内网络建议先在
飞牛 Docker 设置里配置镜像加速，否则可能拉取缓慢或失败。

---

## 二、安装步骤（SSH 方式，推荐）

### 0A. 最快方式：ACR 一键部署（不本地构建，推荐飞牛使用）

把整个仓库目录复制到飞牛（`git clone` 或上传压缩包解压均可），然后：

```bash
cd /vol1/1000/docker/cert-expiry-manager-fnos

# 二选一提供 ACR 凭据：
#   方式 1（零交互）：在 .env 里填 ACR_USERNAME / ACR_PASSWORD
cp .env.example .env
vi .env          # 填 ACR_USERNAME、ACR_PASSWORD；其余密码项留空，脚本会自动生成
chmod 600 .env

#   方式 2：用环境变量传入（密码不落盘）
# export ACR_USERNAME='你的阿里云账号'
# read -s ACR_PASSWORD && export ACR_PASSWORD

sh deploy-acr.sh            # 用 .env 里的 ACR_TAG
# 或指定版本：
sh deploy-acr.sh 2.99.87
```

`deploy-acr.sh` 等价于 `sh install.sh --acr [--tag <版本>]`，会自动完成：

1. 检查 Docker / Compose；
2. 首次运行生成 `.env`，随机填充 MySQL/Redis/管理员/密钥等所有空密码项；
3. 登录 ACR（凭据来自环境变量或 `.env`，密码走 `--password-stdin`，不进日志）；
4. `docker compose -f docker-compose.acr.yml pull` 拉取 php/mysql/redis/app 全部镜像；
5. `up -d` 启动，容器入口自动建库、导入数据、创建管理员。

> 私有 ACR 仓库必须登录；若仓库已设为公开，可直接跳过登录匿名拉取。
> `.env` 已被 `.gitignore` 与 `.dockerignore` 排除，凭据不会进入仓库或镜像。

### 0B. 本地构建方式（需要飞牛能访问 Docker Hub）

如果飞牛已安装并启动 Docker，直接执行（路径以你实际的存储空间为准）：

```bash
mkdir -p /vol1/1000/docker
cd /vol1/1000/docker
git clone https://github.com/lzk898482768/certagnet.git cert-expiry-manager-fnos
cd cert-expiry-manager-fnos
sh install.sh
```

> 仓库为 GitHub 公开库，可直接用 HTTPS 克隆；如需令牌，使用 GitHub Personal Access Token。
> 无法访问 GitHub 时，可先在电脑上克隆/下载压缩包，再上传到飞牛目录。

脚本会自动生成 `.env` 随机密码、构建镜像（自动安装 PHP 扩展 / nginx / supervisor 等依赖）、
启动 MySQL 8 与 Redis 7，并由容器入口完成建库、导入数据、创建管理员。
安装完成后会打印授权地址与管理员密码。

> 脚本只负责生成配置和启动容器；授权仍需浏览器访问 `/setup.php` 完成。
> 如果默认端口被占用：先 `cp .env.example .env`，把 `HTTP_PORT` 改成其它非常用端口（如 28080），再执行 `sh install.sh`；
> 也可以安装后修改仓库根目录的 `.env`，再执行 `docker compose up -d`。

下面是手动步骤，适合需要自定义密码或排查问题的场景。

### 1. 开启 SSH 并登录

飞牛：`系统设置 → 远程访问 → SSH`，开启后用电脑终端登录：

```bash
ssh 你的用户名@飞牛IP
```

### 2. 放置代码

在飞牛存储空间里建目录（路径以你实际的存储空间为准，常见为 `/vol1/1000/...`）：

```bash
mkdir -p /vol1/1000/docker/cert-expiry-manager-fnos
cd /vol1/1000/docker/cert-expiry-manager-fnos
```

把本仓库代码放进去，二选一：

- **GitHub 克隆**（公开库，直接 HTTPS；私有化后需使用 Personal Access Token）：
  ```bash
  git clone https://github.com/lzk898482768/certagnet.git .
  ```
- **本地上传**：把仓库压缩包上传到该目录后解压。

确认目录下能看到 `docker-compose.yml`、`Dockerfile`、`backend/`、`docker/`。

### 3. 生成并填写 `.env`

```bash
cp .env.example .env
```

生成随机密码（推荐纯字母数字，避免 Compose 变量解析问题）：

```bash
openssl rand -hex 24   # 分别用于 DB_ROOT_PASSWORD、REDIS_PASSWORD
openssl rand -hex 32   # 用于 GOV_PASSWORD_KEY
```

编辑 `.env`：

```bash
vi .env
```

必须填写的项：

| 变量 | 说明 |
|---|---|
| `DB_ROOT_PASSWORD` | MySQL root 密码，独立数据库专用 |
| `REDIS_PASSWORD` | Redis 密码，不能为空 |
| `ADMIN_PASS` | 管理员初始密码（≥ 8 位，不能是 `admin123`） |
| `GOV_PASSWORD_KEY` | 政务网申报密码加密密钥，安装后不可更换 |
| `HTTP_PORT` | 默认 18080（非常用端口），可按需修改；只影响访问端口，不影响授权（授权只校验域名） |

> `.env` 含明文密码，不要提交到 Git，也不要发给无关人员。安装完成后请另行备份。

### 4. 构建并启动

```bash
docker compose up -d --build
```

首次构建约 5～15 分钟。查看初始化进度：

```bash
docker compose logs -f app
```

看到类似输出即表示成功：

```
[fnos] MySQL 已就绪
[fnos] Redis 已就绪
[fnos] 首次启动：创建独立数据库 cert_agent_fnos 并初始化数据
  - 数据库 `cert_agent_fnos` 已就绪
  - init.sql 执行完成（... 条语句）
  - init_data.sql 执行完成（... 条语句）
  - 管理员账号已创建
[fnos] 初始化完成，启动 nginx / php-fpm / 定时任务
```

按 `Ctrl+C` 退出日志跟随（不会停止容器）。

### 5. 完成授权（必须）

系统带授权门禁，未完成授权时页面会被拦到 `/license-gate.php`。

浏览器访问：

```
http://飞牛IP:18080/setup.php
```

按页面提示：

1. 配置授权端公钥（使用分发包内置公钥，或粘贴你方授权端公钥）；
2. 粘贴授权端签发的凭证（payload + signature）；
3. 完成安装。

> **凭证域名必须与访问地址一致**：授权校验只比对域名/IP，不比对端口。
> 例如凭证写的是 `192.168.1.10`，就必须用 `http://192.168.1.10:18080` 访问；
> 用 `localhost` 或其它 IP 会报「当前域名不在授权范围」。
> 用 DDNS 时，凭证绑定 DDNS 域名即可，端口（如 `:18080`）不参与校验。

### 6. 登录系统

| 入口 | 地址 |
|---|---|
| PC 管理端 | `http://飞牛IP:18080/web/` |
| 移动端 H5 | `http://飞牛IP:18080/mobile/` |

用户名：`.env` 里的 `ADMIN_USER`（默认 `admin`），密码：`ADMIN_PASS`。

登录后建议立即在「个人中心 / 用户管理」修改管理员密码，并创建日常账号。

---

## 三、安装步骤（飞牛 Docker 图形界面方式）

1. 打开飞牛「Docker」应用 → 「Compose」→「新增项目」。
2. 项目目录选择上面放代码的目录（必须包含 `docker-compose.yml`）。
3. 若界面要求粘贴 Compose 内容，直接使用仓库内的 `docker-compose.yml`；
   注意 `.env` 仍需在项目目录中提前创建并填写。
4. 选择「构建并启动」。
5. 后续授权与登录步骤同上一节第 5、6 步。

> 不同 fnOS 版本的 Docker 界面略有差异；若图形界面不支持本地 Dockerfile 构建，
> 请改用 SSH 执行 `docker compose up -d --build`。

---

## 四、首次启动做了什么

1. 等待 MySQL / Redis 健康检查通过；
2. 在独立库 `cert_agent_fnos` 中导入 `backend/database/init.sql`（77 张表）与
   `backend/database/init_data.sql`（角色、菜单、字典、消息模板等种子数据）；
3. 创建管理员账号（`ADMIN_USER` / `ADMIN_PASS`）；
4. 生成 `backend/.env`（实际持久化在 `./data/config/.env`）与
   `backend/runtime/install.lock`；
5. 启动 nginx、php-fpm、定时任务。

以上只在 `data/runtime/install.lock` 不存在时执行一次。

---

## 五、数据目录与备份

| 宿主机路径 | 内容 | 说明 |
|---|---|---|
| `./.env` | Compose 变量：MySQL root、Redis、管理员、GOV 密钥 | **必须备份**；丢失后 MySQL 密码对不上，容器无法正常启动 |
| `./data/config/.env` | 数据库 / Redis / GOV 密钥 | **必须备份**，丢失后需重建配置 |
| `./data/runtime/` | 日志、备份、授权文件 `vault/` | 授权凭证在这里，丢失需重新授权 |
| `./data/uploads/` | 上传的附件 | 与业务数据同等重要 |
| Docker 卷 `*_mysql_data` | MySQL 数据 | 用 mysqldump 备份更稳妥 |
| Docker 卷 `*_redis_data` | Redis AOF | 缓存可丢，但保留可加快恢复 |

备份示例：

```bash
# 1) 数据库
docker compose exec -T mysql sh -c 'exec mysqldump -uroot -p"$MYSQL_ROOT_PASSWORD" --single-transaction --routines --triggers cert_agent_fnos' > backup-$(date +%F).sql

# 2) 配置 / 授权 / 附件（文件属主为容器内 www-data，需 sudo；根目录 .env 必须一起备份）
sudo tar czf cert-fnos-data-$(date +%F).tar.gz .env data/
```

恢复时先 `docker compose down`，恢复根目录 `.env`、`data/` 与数据库，再 `docker compose up -d`。

> 注意：`docker compose down -v` 会删除 MySQL / Redis 数据卷，等同于清空数据库，
> 除非确认要重装，否则不要加 `-v`。如果只删了数据卷而保留 `data/`，
> 容器下次启动会检测到数据库为空并自动重新初始化（附件与授权文件仍在 `data/`）。

---

## 六、升级（更新到新版本）

飞牛版已**固定关闭页面「在线升级」**（`PHP_UPGRADE_ENABLED=0`）：容器内代码属于镜像层，
页面升级替换的文件会在容器重建后丢失，也会造成镜像版本与代码不一致；菜单不显示该入口，
相关接口直接拒绝请求。升级统一走「拉新代码 → 拉新镜像 → 重建容器」。

### 6.1 推荐：ACR 一键升级（标准部署方式）

```bash
cd /vol2/1000/certagent/cert-expiry-manager-fnos   # 换成你的实际目录

# 1) 建议先备份（见「五、数据目录与备份」）
# 2) 一次性忽略文件可执行权限位差异（部分 fnOS 卷不保留 +x，不加会挡住 git 合并）
git config core.fileMode false
# 3) 拉取新代码
git pull
# 4) 指定新版本号部署（会把 ACR_TAG 写回 .env，再拉镜像、滚动重建 app 容器）
sh deploy-acr.sh 2.99.87
```

说明：

- 版本号要换成目标版本；不指定时使用 `.env` 里现有的 `ACR_TAG`（可能是旧版），所以**升级时建议显式带版本号**。
- `.env` 与 `data/` 不入库，`git pull` 不会动它们；端口、所有密码、授权凭证、附件均保留。
- 数据库结构变更由程序内 `*SchemaService` 在启动/请求时自愈，**无需手工执行 SQL**。
- 升级只重建 app 容器，MySQL / Redis 数据卷不受影响，业务中断通常几十秒。
- 升级后页面仍是旧资源时，浏览器 **Ctrl+F5 强制刷新**。

### 6.2 升级前的端口 / 配置注意

- 访问端口请固定写在 `.env` 的 `HTTP_PORT`（如 `HTTP_PORT=8099`），它不入库、永不被覆盖；
  **不要直接改 `docker-compose.acr.yml` 里的兜底端口**，那是受版本管理的文件，会在 `git pull` 时产生冲突。
- 若 `git pull` 提示 `Your local changes would be overwritten`：先 `git status` / `git diff` 确认，
  多数是权限位（`old mode 100755 / new mode 100644`，内容无变化）或误改了受管文件。
  权限位问题用上面的 `git config core.fileMode false` 解决；确认无需保留的受管文件改动可用
  `git checkout -- <文件>` 还原后再 `git pull`。**不要随手 `git reset --hard`**，先确认 `.env`、`data/` 不受影响。

### 6.3 升级后验证

```bash
# 容器状态（app/mysql/redis 应为 Up；app 健康检查通过后显示 healthy）
docker compose -f docker-compose.acr.yml --env-file .env ps

# 启动与定时任务日志
docker compose -f docker-compose.acr.yml --env-file .env logs --tail=200 app

# 路由/接口验证（端口换成你的 HTTP_PORT）：探针应返回 200 文本，接口应返回 200 JSON 而非 302
curl -i "http://你的域名或IP:8099/envprobe.php/api/captcha"
curl -i "http://你的域名或IP:8099/api/captcha"
```

### 6.4 备用：本地构建模式升级（需要能访问 Docker Hub，一般不用）

```bash
git pull
docker compose up -d --build
docker compose logs -f app
```

> 页面上的升级备份/恢复按钮已一并关闭，备份请统一使用「五、数据目录与备份」里的 `mysqldump` + `tar` 命令。

---

## 七、定时任务（容器内置，无需在 NAS 配置）

飞牛版的定时任务**内置在应用容器里、开箱即跑**，不需要、也不应该再到飞牛 NAS 系统里配置 crontab，
否则会与内置调度器**重复执行**。

### 7.1 运行机制

容器内由 supervisor 常驻三个进程（见 `docker/supervisord.conf`）：`nginx`、`php-fpm`、`scheduler`，
均设置 `autorestart=true`，进程异常会被自动拉起。其中 `scheduler`（`docker/scheduler.sh`）
启动后先等待 30 秒，再按间隔（默认每小时）循环执行下列命令：

| 命令 | 作用 |
|---|---|
| `php think cert:checkexpire --catch-up` | 证书 / 借调 / 企业资质**到期检测与多渠道提醒推送**；`--catch-up` 补发最近 3 天内因停机漏掉的提醒节点 |
| `php think gov:sync` | **政务服务网申报进度同步**；默认无间隔限制，每轮同步未办结记录，仅对「环节/状态变化」的记录推送通知 |

为保证消息推送稳定，调度器内置四重保护（v2.99.86 起）：

1. **超时隔离**：每条任务用 `timeout` 限时（默认到期检测 900s、政务同步 1500s），政务网 HTTP 挂起也会被强制终止，下一轮自动重试，不会卡死整个调度；
2. **非阻塞防重入**：用原子文件锁保证同一时刻只有一轮任务在跑，上一轮没结束时本轮直接跳过，绝不并发（业务层本身也按「证书×节点×当天」幂等，锁是双保险，不会重复推送）；
3. **心跳与日志**：每轮更新 `runtime/scheduler.heartbeat` 时间戳，结果追加到 `runtime/log/scheduler.log`，同时输出到 `docker logs`；
4. **优雅退出 + 工具降级**：收到停止信号释放锁后退出；镜像缺少 `timeout` 等工具时自动退化为不限时，不会直接崩溃。

### 7.2 确认定时任务在运行

```bash
# 实时日志：每轮可见「开始执行 cert:checkexpire / gov:sync」与完成/超时结果
docker compose -f docker-compose.acr.yml --env-file .env logs --tail=200 app

# 心跳文件：时间戳持续更新即代表调度器存活（容器内路径）
docker compose -f docker-compose.acr.yml --env-file .env exec app cat /var/www/backend/runtime/scheduler.heartbeat

# 持久化的任务结果日志
docker compose -f docker-compose.acr.yml --env-file .env exec app tail -n 50 /var/www/backend/runtime/log/scheduler.log
```

也可在网页后台佐证：「政务网申报进度」页有定时任务**执行记录**（每次 gov:sync 落一条，含上次执行时间）；
「消息推送」页提供**立即检测**按钮，可手动触发一次到期检测。

> 容器健康检查只探测 MySQL/Redis，显示 healthy 不等于调度器在跑，请以心跳文件和日志为准。

### 7.3 立即手动执行一次（不等下一轮）

手动执行请带 `-u www-data`，与内置调度器同用户，避免 root 生成的缓存/日志造成权限问题：

```bash
# 只体检不推送（排查最安全）
docker compose -f docker-compose.acr.yml --env-file .env exec -u www-data app php think cert:checkexpire --dry-run
# 立即检测并推送
docker compose -f docker-compose.acr.yml --env-file .env exec -u www-data app php think cert:checkexpire
# 立即同步政务网进度（可加 --id=12 只同步某条 / --limit=50 限量 / --all 含已办结）
docker compose -f docker-compose.acr.yml --env-file .env exec -u www-data app php think gov:sync
```

### 7.4 调整频率与超时（改 .env，无需改代码）

在 `.env` 设置后重新 `sh deploy-acr.sh` 生效（compose 已透传到调度器环境变量）：

| `.env` 变量 | 默认 | 含义 |
|---|---|---|
| `SCHED_INTERVAL` | 3600 | 调度循环间隔（秒），改 1800 即每半小时 |
| `TASK_TIMEOUT_CHECK` | 900 | 到期检测单次超时（秒） |
| `TASK_TIMEOUT_GOV` | 1500 | 政务同步单次超时（秒） |

### 7.5 常见疑问

- **后台「计划任务」页给的 crontab 命令要配吗？** 不用。那是给宝塔面板 / 裸机部署的指引；
  Docker 版已内置等价调度，照配会重复执行。
- **想给政务同步降频？** 可在 `backend/config/gov.php` 把 `daily_min_hours` 设为大于 0 的小时数
  （或经 `.env` 的 `gov.daily_min_hours`），同一间隔内重复执行会自动跳过。
- **调度器停了怎么办？** supervisor 的 `autorestart=true` 会自动拉起；也可
  `docker compose restart app` 手动重启。锁文件残留导致不执行时，删除
  `runtime/scheduler.lock`（对应宿主机 `data/runtime/scheduler.lock`）即可。

---

## 八、性能调优（按 NAS 内存自适应）

所有性能参数都在 `.env` 可调，**默认按 4GB 内存的 NAS 保守调优**；在更高规格 NAS 上调大对应值并重新
`sh deploy-acr.sh` 即可发挥性能，无需改代码、无需重新构建镜像（compose 通过环境变量透传）。

### 8.1 默认做了哪些优化

| 层 | 优化项 | 默认（4GB） |
|---|---|---|
| 定时任务 | 任务超时隔离、非阻塞防重入、心跳与持久日志 | 间隔 3600s，超时 900/1500s |
| MySQL | InnoDB buffer pool、redo log、慢查询日志 | buffer pool 256M |
| Redis | 内存上限 + `volatile-lru` 淘汰、空闲回收、关 RDB 留 AOF | maxmemory 256M |
| 会话 | 登录态/验证码本就基于 Redis；新增 session.php 兜底，杜绝原生 Session 写 NAS 文件 | token 默认 3 小时 |
| PHP-FPM | 动态进程池按 4GB 收敛，进程定期回收防内存泄漏 | max_children 12 |
| OPcache | 预编译字节码缓存，文件数上限提升到 40000 | memory 256M |
| nginx | worker 自适应、keepalive、gzip_static、fastcgi 长连复用 | worker_processes auto |

### 8.2 不同内存规格的推荐值

在 `.env` 覆盖下列变量（单位见 `.env.example` 注释，字节配置给了换算）：

| 变量 | 4GB（默认） | 8GB | 16GB+ |
|---|---|---|---|
| `MYSQL_INNODB_BUFFER_POOL_SIZE` | 256M | 512M | 1G |
| `MYSQL_INNODB_LOG_FILE_SIZE` | 128M | 256M | 512M |
| `REDIS_MAXMEMORY` | 256M | 384M | 512M |
| `PM_MAX_CHILDREN` | 12 | 20 | 30 |
| `PM_START_SERVERS` | 4 | 6 | 8 |
| `PM_MAX_SPARE_SERVERS` | 6 | 10 | 16 |

> 经验法则：MySQL buffer pool 约取可用内存的 50%~60%；`PM_MAX_CHILDREN × 512M`（PHP memory_limit）
> 是 PHP 侧理论内存上限，切勿盲目调大导致 OOM。改完执行 `sh deploy-acr.sh` 滚动重启生效。

### 8.3 验证调优是否生效

```bash
# MySQL buffer pool（密码按提示输入，或把 -p 后的占位换成 .env 里的 DB_ROOT_PASSWORD）
docker compose -f docker-compose.acr.yml --env-file .env exec mysql \
  sh -c 'mysql -uroot -p"$MYSQL_ROOT_PASSWORD" -e "SHOW VARIABLES LIKE \"innodb_buffer_pool_size\";"'
# Redis 内存上限与淘汰策略（密码取容器内环境变量，无需手工填）
docker compose -f docker-compose.acr.yml --env-file .env exec redis \
  sh -c 'redis-cli -a "$REDIS_PASSWORD" CONFIG GET maxmemory maxmemory-policy'
# PHP-FPM 进程数（含 master，实际 worker 数减一；用 || true 避免无匹配时返回非零）
docker compose -f docker-compose.acr.yml --env-file .env exec app sh -c 'ps aux | grep -c "[p]hp-fpm" || true'
# OPcache 状态（命中数 num_cached_hits 应随访问持续增长）
docker compose -f docker-compose.acr.yml --env-file .env exec app \
  php -r 'var_export(opcache_get_status(false)["opcache_statistics"] ?? "n/a");'
```

### 8.4 慢查询与性能排查

- MySQL 已开启慢查询日志（阈值 `MYSQL_LONG_QUERY_TIME=2` 秒），可用
  `docker compose ... exec mysql mysql -e "SHOW VARIABLES LIKE 'slow_query%';"` 确认日志位置后分析。
- 应用层「推送中心 → 推送体检」可只读检查渠道、接收人、提醒规则与命中情况，定位「为什么没推送」。
- 调度器历史结果见 `data/runtime/log/scheduler.log`（容器内 `/var/www/backend/runtime/log/scheduler.log`）。

---

## 九、常见问题

**1. 默认端口被占用 / 想用其它端口**

修改 `.env` 的 `HTTP_PORT`（默认 18080，避开 80/443/8080），然后重新 `sh deploy-acr.sh`（或 `docker compose up -d`），并同步调整路由器 DDNS 端口映射。

**2. 构建时拉取镜像很慢 / 失败**

在飞牛 Docker 设置中配置镜像加速；或先手动 `docker pull mysql:8.0`、
`docker pull redis:7-alpine`、`docker pull php:8.2-fpm-bookworm` 再构建。

**3. 启动后一直重启**

```bash
docker compose logs --tail=200 app
```

常见原因：`.env` 未填写必填项、密码含 `$` 等特殊字符、MySQL 首次初始化超时。
修正后 `docker compose up -d`。

**4. 页面跳转到「未授权」**

访问 `http://IP:端口/setup.php` 完成授权；确认凭证域名与当前访问域名/IP 完全一致。

**5. 忘记管理员密码**

在数据库里重置（把 `新密码` 换成实际值）：

```bash
docker compose exec mysql sh -c \
  "mysql -uroot -p\"\$MYSQL_ROOT_PASSWORD\" cert_agent_fnos -e \"UPDATE sys_user SET password=MD5('新密码') WHERE username='admin';\""
```

系统兼容 md5 旧哈希，登录后建议在页面重新设置一次密码。

**6. 想彻底重装**

```bash
docker compose down -v          # 删除容器与数据库卷（数据清空！）
rm -rf data/                    # 删除配置、授权、附件
docker compose up -d --build
```

**7. 时区 / 时间不对**

容器已设置 `TZ=Asia/Shanghai`，PHP 时区为 `Asia/Shanghai`；若仍不对，检查飞牛宿主机时间。

**8. 菜单里没有「在线升级」**

这是飞牛版的预期行为：页面在线升级会替换容器内代码，容器重建后丢失，也会与镜像版本不一致。
升级请按「六、升级」执行，ACR 标准部署用 `git pull && sh deploy-acr.sh <版本号>`；
不要尝试通过菜单管理重新启用该菜单，后端会自动将其隐藏。

**9. 定时任务需要在飞牛 NAS 里配置吗**

不需要。定时任务由容器内 supervisor 常驻的 `scheduler` 进程自动执行（默认每小时一轮，含超时隔离与防重入），
详见「七、定时任务」。在 NAS 系统里再配 crontab 反而会重复执行。查看运行情况与心跳：

```bash
docker compose -f docker-compose.acr.yml --env-file .env logs --tail=200 app
docker compose -f docker-compose.acr.yml --env-file .env exec app cat /var/www/backend/runtime/scheduler.heartbeat
```

若怀疑锁残留导致任务不跑，删除 `data/runtime/scheduler.lock` 后 `docker compose restart app`。

**10. `git pull` 报 local changes would be overwritten / 权限位变化**

多为 fnOS 卷不保留可执行位（`old mode 100755 / new mode 100644`，内容无变化）或误改了受管文件。
先执行下面命令让 git 忽略权限位差异，再拉取：

```bash
git config core.fileMode false
git status          # 若已干净即可直接 git pull
git pull
```

端口等自定义配置应写在不入库的 `.env`，不要改 `docker-compose.acr.yml` 等受管文件；
确认无需保留的受管文件改动可用 `git checkout -- <文件>` 还原。不要随手 `git reset --hard`。

**11. 「构建」一直卡在 `Image cert-expiry-manager-fnos:latest Pulling`**

v2.99.75 及更早的 `docker-compose.yml` 同时声明了 `image:` 和 `build:`，飞牛/Docker 会先尝试
从 Docker Hub 拉取这个本地镜像名，网络不通时就会一直卡住。

先关闭卡住的构建，拉取 v2.99.76 后重新构建：

```bash
cd /vol1/1000/docker/cert-expiry-manager-fnos
git pull
docker compose build --no-cache
docker compose up -d
```

v2.99.76 已删除 `image:` 声明，镜像名由 Compose 自动生成。若暂时不能升级，也可以手工删除
`docker-compose.yml` 中 `app:` 下的 `image: cert-expiry-manager-fnos:${APP_TAG:-latest}` 一行，
再执行 `docker compose build --no-cache && docker compose up -d`。

> 首次构建仍会拉取 `php:8.2-fpm-bookworm`、`mysql:8.0`、`redis:7-alpine` 等基础镜像；
> 如果停在这些真实镜像名上，属于 Docker Hub 网络问题，请配置镜像加速。

---

## 十、安全建议

- 不要把 3306 / 6379 映射到宿主机；本编排默认不暴露。
- `HTTP_PORT` 尽量只在内网访问；公网使用请在前面加 HTTPS 反向代理。
- 使用强随机密码，`.env` 权限建议 `chmod 600 .env`。
- 定期备份仓库根目录 `.env`、数据库、`data/config`、`data/runtime`、`data/uploads`。
- 授权文件 `data/runtime/vault/` 属于敏感资料，不要外发。

---

## 十一、目录结构

```
.
├── Dockerfile                 # 应用镜像（nginx + php-fpm + scheduler）
├── docker-compose.yml         # app / mysql / redis（本地构建）
├── docker-compose.acr.yml     # 可选：全部镜像从阿里云 ACR 拉取
├── install.sh                 # 一键安装（生成 .env + docker compose up -d --build）
├── .env.example               # 环境变量模板
├── docker/
│   ├── entrypoint.sh          # 目录准备、依赖等待、首次安装
│   ├── install-cli.php        # 复用 install.php 的 CLI 安装器
│   ├── healthcheck.php        # MySQL / Redis 健康检查
│   ├── scheduler.sh           # 每小时定时任务
│   ├── nginx.conf             # 站点配置
│   ├── php-app.ini            # PHP 运行参数
│   ├── opcache.ini            # OPcache 生产配置
│   ├── php-fpm.conf           # clear_env=no 等
│   └── supervisord.conf       # 进程托管
├── backend/                   # 主程序（含 vendor 与前端构建产物）
├── .github/workflows/
│   └── acr-push.yml           # GitHub Actions：推送全部镜像到阿里云 ACR
└── tools/
    ├── push-acr.sh            # 把镜像推送到阿里云 ACR
    └── sync-from-main.mjs     # 从主仓库同步 backend
```

---

## 十二、从主仓库同步代码

在开发机（已克隆主仓库）执行：

```bash
node tools/sync-from-main.mjs /path/to/cert-expiry-manager
```

脚本会用主仓库当前提交的 `backend/` 覆盖本仓库 `backend/`，并更新 `VERSION`。
检查差异后提交、打标签即可。

> **发布渠道**：本仓库只发布到 GitHub `lzk898482768/certagnet`，不再推送 Gitee。
> 飞牛侧统一用 `git pull` + `docker compose up -d --build`（或 ACR 镜像）升级。

---

## 十三、使用阿里云 ACR（可选）

如果飞牛访问 Docker Hub 很慢或失败，可以先把镜像推送到阿里云容器镜像服务（ACR）。
三种用法，任选一种。当前 ACR：实例 `crpi-e8ivxiujwrjswkb4`，命名空间 `certagent`，
应用镜像仓库 `certagent`，公网地址 `crpi-e8ivxiujwrjswkb4.cn-beijing.personal.cr.aliyuncs.com`。

### 方式 A：全部镜像从 ACR 拉取，飞牛不构建（推荐）

先在**能访问 Docker Hub 的 x86_64 构建机**上执行（首次拉基础镜像需要 Docker Hub）：

```bash
git clone https://github.com/lzk898482768/certagnet.git cert-expiry-manager-fnos
cd cert-expiry-manager-fnos

export ACR_REGISTRY=crpi-e8ivxiujwrjswkb4.cn-beijing.personal.cr.aliyuncs.com
export ACR_NAMESPACE=certagent
export ACR_APP_REPO=certagent
export ACR_USERNAME='你的阿里云账号'
read -s ACR_PASSWORD && export ACR_PASSWORD

sh tools/push-acr.sh
```

脚本会推送：

- `certagent/php:8.2-fpm-bookworm`
- `certagent/mysql:8.0`
- `certagent/redis:7-alpine`
- `certagent/certagent:<VERSION>` 与 `:latest`

然后在飞牛上：

```bash
cd /vol1/1000/docker/cert-expiry-manager-fnos
git pull

# 在 .env 里增加（模板见 .env.example）：
# ACR_REGISTRY=crpi-e8ivxiujwrjswkb4.cn-beijing.personal.cr.aliyuncs.com
# ACR_NAMESPACE=certagent
# ACR_APP_REPO=certagent
# ACR_TAG=2.99.87

docker login crpi-e8ivxiujwrjswkb4.cn-beijing.personal.cr.aliyuncs.com --username '你的阿里云账号'
docker compose -f docker-compose.acr.yml --env-file .env pull
docker compose -f docker-compose.acr.yml --env-file .env up -d
```

升级时，在构建机重新推送新版本，把飞牛 `.env` 的 `ACR_TAG` 改成新版本，再执行
`docker compose -f docker-compose.acr.yml pull && docker compose -f docker-compose.acr.yml up -d`。

### 方式 B：只用 ACR 基础镜像，飞牛仍本地构建

构建机同样先执行 `tools/push-acr.sh`（至少推送 php / mysql / redis），然后在飞牛 `.env` 中设置：

```ini
PHP_BASE_IMAGE=crpi-e8ivxiujwrjswkb4.cn-beijing.personal.cr.aliyuncs.com/certagent/php:8.2-fpm-bookworm
MYSQL_IMAGE=crpi-e8ivxiujwrjswkb4.cn-beijing.personal.cr.aliyuncs.com/certagent/mysql:8.0
REDIS_IMAGE=crpi-e8ivxiujwrjswkb4.cn-beijing.personal.cr.aliyuncs.com/certagent/redis:7-alpine
```

之后仍执行 `docker compose up -d --build`。

### 方式 C：用 GitHub Actions 推送全部镜像

如果本机/飞牛都没有 Docker Hub 访问能力，可以直接让 GitHub Actions 在云端拉取并推送：

1. 打开 GitHub 仓库 `lzk898482768/certagnet` → `Settings` → `Secrets and variables` → `Actions`；
2. 新增两个 Repository secret：
   - `ACR_USERNAME`：阿里云账号（不是命名空间 `certagent`）
   - `ACR_PASSWORD`：ACR 访问凭证/固定密码
3. 打开 `Actions` → `Push images to Aliyun ACR` → `Run workflow`；
4. 工作流会推送 `certagent/php`、`certagent/mysql`、`certagent/redis`、`certagent/certagent`。

工作流文件是 `.github/workflows/acr-push.yml`，默认手动触发，不会在每次提交时自动跑。
如需以后打 tag 自动推送，可在该文件的 `on:` 下增加：

```yaml
  push:
    tags:
      - 'v*'
```

注意事项：

- `ACR_NAMESPACE` 是 ACR 控制台里创建的命名空间，不是登录用户名；当前为 `certagent`。
- `ACR_APP_REPO` 是 ACR 控制台里的“仓库名称”；当前为 `certagent`。基础镜像仓库分别是 `php` / `mysql` / `redis`。
- ACR 个人版默认私有仓库，飞牛必须先 `docker login` 才能拉取。
- 如果构建机也访问不了 Docker Hub，先在 ACR 控制台使用「镜像同步/镜像加速」导入基础镜像，
  或让构建机走代理；`push-acr.sh` 本身无法凭空下载镜像。
- 不要把 ACR 用户名/密码写进仓库；建议为本项目单独创建只具备推送权限的 RAM 用户。
- ACR 个人版可能有存储/流量限制，推送前确认套餐余量。
