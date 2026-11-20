# 飞牛 fnOS 部署仓库

证照到期管理系统的飞牛 fnOS 专用部署包：**独立 Gitee 仓库、独立 MySQL 数据库、独立数据目录**。

- 主程序版本：v2.99.75
- 部署包版本：v2.99.77
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
| 端口 | 默认 8080/TCP（可改）；如启用了飞牛防火墙，请放行该端口 |

首次构建需要拉取 `php:8.2-fpm-bookworm`、`mysql:8.0`、`redis:7-alpine`，国内网络建议先在
飞牛 Docker 设置里配置镜像加速，否则可能拉取缓慢或失败。

---

## 二、安装步骤（SSH 方式，推荐）

### 0. 最快方式：一键脚本

如果飞牛已安装并启动 Docker，直接执行（路径以你实际的存储空间为准）：

```bash
mkdir -p /vol1/1000/docker
cd /vol1/1000/docker
git clone git@gitee.com:kxunyun/cert-expiry-manager-fnos.git
cd cert-expiry-manager-fnos
sh install.sh
```

> 私有库需要先给飞牛配置 Gitee SSH key；没有 SSH key 时，可先在电脑上克隆/下载压缩包，
> 再上传到飞牛目录，或使用带令牌的 HTTPS 地址。

脚本会自动生成 `.env` 随机密码、构建镜像（自动安装 PHP 扩展 / nginx / supervisor 等依赖）、
启动 MySQL 8 与 Redis 7，并由容器入口完成建库、导入数据、创建管理员。
安装完成后会打印授权地址与管理员密码。

> 脚本只负责生成配置和启动容器；授权仍需浏览器访问 `/setup.php` 完成。
> 如果 8080 端口被占用：先 `cp .env.example .env`，把 `HTTP_PORT` 改成 8081，再执行 `sh install.sh`；
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

- **Gitee 克隆**（私有库需先配置 SSH key 或使用带令牌的 HTTPS 地址）：
  ```bash
  git clone git@gitee.com:kxunyun/cert-expiry-manager-fnos.git .
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
| `HTTP_PORT` | 默认 8080，可按需修改 |

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
http://飞牛IP:8080/setup.php
```

按页面提示：

1. 配置授权端公钥（使用分发包内置公钥，或粘贴你方授权端公钥）；
2. 粘贴授权端签发的凭证（payload + signature）；
3. 完成安装。

> **凭证域名必须与访问地址一致**：授权校验只比对域名/IP，不比对端口。
> 例如凭证写的是 `192.168.1.10`，就必须用 `http://192.168.1.10:8080` 访问；
> 用 `localhost` 或其它 IP 会报「当前域名不在授权范围」。

### 6. 登录系统

| 入口 | 地址 |
|---|---|
| PC 管理端 | `http://飞牛IP:8080/web/` |
| 移动端 H5 | `http://飞牛IP:8080/mobile/` |

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

## 六、升级

飞牛版已**关闭页面「在线升级」入口**：容器内代码属于镜像层，页面升级替换的文件会在容器重建后丢失，
也会造成镜像版本与代码不一致。菜单中不再显示「在线升级」，相关接口也会拒绝请求。
升级统一走重建镜像：

推荐流程：

```bash
cd /vol1/1000/docker/cert-expiry-manager-fnos

# 1) 先备份（见上一节）
# 2) 拉取新代码（或重新上传新版本压缩包）
git pull

# 3) 重新构建并滚动重启
docker compose up -d --build

# 4) 查看日志确认自动迁移与启动正常
docker compose logs -f app
```

数据库结构变更由程序内的 `*SchemaService` 在启动/请求时自愈，无需手工执行 SQL。
升级后如页面仍显示旧资源，强制刷新浏览器（Ctrl+F5）。

> 如果从 v2.99.73 升级上来仍能看到「在线升级」，请确认已重新执行
> `docker compose up -d --build` 并强制刷新；后端会在首次登录时隐藏该菜单。
> 页面上的升级备份/恢复按钮也一并关闭，备份请使用上一节的 `mysqldump` + `tar` 命令。

---

## 七、常见问题

**1. 8080 端口被占用**

修改 `.env` 的 `HTTP_PORT`，然后 `docker compose up -d`。

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
升级请按第六节执行 `git pull && docker compose up -d --build`。不要尝试通过菜单管理重新启用该菜单，
后端会自动将其隐藏。

**9. 「构建」一直卡在 `Image cert-expiry-manager-fnos:latest Pulling`**

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

## 八、安全建议

- 不要把 3306 / 6379 映射到宿主机；本编排默认不暴露。
- `HTTP_PORT` 尽量只在内网访问；公网使用请在前面加 HTTPS 反向代理。
- 使用强随机密码，`.env` 权限建议 `chmod 600 .env`。
- 定期备份仓库根目录 `.env`、数据库、`data/config`、`data/runtime`、`data/uploads`。
- 授权文件 `data/runtime/vault/` 属于敏感资料，不要外发。

---

## 九、目录结构

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
└── tools/
    ├── push-acr.sh            # 把镜像推送到阿里云 ACR
    └── sync-from-main.mjs     # 从主仓库同步 backend
```

---

## 十、从主仓库同步代码

在开发机（已克隆主仓库）执行：

```bash
node tools/sync-from-main.mjs /path/to/cert-expiry-manager
```

脚本会用主仓库当前提交的 `backend/` 覆盖本仓库 `backend/`，并更新 `VERSION`。
检查差异后提交、打标签即可。

---

## 十一、使用阿里云 ACR（可选）

如果飞牛访问 Docker Hub 很慢或失败，可以先把镜像推送到阿里云容器镜像服务（ACR）。
两种用法，任选一种。

### 方式 A：全部镜像从 ACR 拉取，飞牛不构建（推荐）

先在**能访问 Docker Hub 的 x86_64 构建机**上执行（首次拉基础镜像需要 Docker Hub）：

```bash
git clone git@gitee.com:kxunyun/cert-expiry-manager-fnos.git
cd cert-expiry-manager-fnos

export ACR_REGISTRY=crpi-e8ivxiujwrjswkb4.cn-beijing.personal.cr.aliyuncs.com
export ACR_NAMESPACE=你的ACR命名空间
export ACR_USERNAME='你的阿里云账号'
read -s ACR_PASSWORD && export ACR_PASSWORD

sh tools/push-acr.sh
```

脚本会推送：

- `php:8.2-fpm-bookworm`
- `mysql:8.0`
- `redis:7-alpine`
- `cert-expiry-manager-fnos:<VERSION>` 与 `:latest`

然后在飞牛上：

```bash
cd /vol1/1000/docker/cert-expiry-manager-fnos
git pull

# 在 .env 里增加（模板见 .env.example）：
# ACR_REGISTRY=crpi-e8ivxiujwrjswkb4.cn-beijing.personal.cr.aliyuncs.com
# ACR_NAMESPACE=你的ACR命名空间
# ACR_TAG=2.99.77

docker login crpi-e8ivxiujwrjswkb4.cn-beijing.personal.cr.aliyuncs.com --username '你的阿里云账号'
docker compose -f docker-compose.acr.yml --env-file .env pull
docker compose -f docker-compose.acr.yml --env-file .env up -d
```

升级时，在构建机重新推送新版本，把飞牛 `.env` 的 `ACR_TAG` 改成新版本，再执行
`docker compose -f docker-compose.acr.yml pull && docker compose -f docker-compose.acr.yml up -d`。

### 方式 B：只用 ACR 基础镜像，飞牛仍本地构建

构建机同样先执行 `tools/push-acr.sh`（至少推送 php / mysql / redis），然后在飞牛 `.env` 中设置：

```ini
PHP_BASE_IMAGE=crpi-e8ivxiujwrjswkb4.cn-beijing.personal.cr.aliyuncs.com/<命名空间>/php:8.2-fpm-bookworm
MYSQL_IMAGE=crpi-e8ivxiujwrjswkb4.cn-beijing.personal.cr.aliyuncs.com/<命名空间>/mysql:8.0
REDIS_IMAGE=crpi-e8ivxiujwrjswkb4.cn-beijing.personal.cr.aliyuncs.com/<命名空间>/redis:7-alpine
```

之后仍执行 `docker compose up -d --build`。

注意事项：

- `ACR_NAMESPACE` 是 ACR 控制台里创建的命名空间，不是登录用户名；首次推送前先创建。
- ACR 个人版默认私有仓库，飞牛必须先 `docker login` 才能拉取。
- 如果构建机也访问不了 Docker Hub，先在 ACR 控制台使用「镜像同步/镜像加速」导入基础镜像，
  或让构建机走代理；`push-acr.sh` 本身无法凭空下载镜像。
- 不要把 ACR 用户名/密码写进仓库；建议为本项目单独创建只具备推送权限的 RAM 用户。
- ACR 个人版可能有存储/流量限制，推送前确认套餐余量。
