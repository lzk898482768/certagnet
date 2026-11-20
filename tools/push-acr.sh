#!/usr/bin/env sh
# 把飞牛版需要的镜像全部推送到阿里云容器镜像服务（ACR）
#
# 需要在一台能访问 Docker Hub 的机器上执行（首次拉取基础镜像需要 Docker Hub；
# 如果构建机也访问不了 Docker Hub，请先在 ACR 控制台配置镜像加速/镜像同步）。
#
# 用法：
#   export ACR_REGISTRY=crpi-e8ivxiujwrjswkb4.cn-beijing.personal.cr.aliyuncs.com
#   export ACR_NAMESPACE=certagent
#   export ACR_APP_REPO=certagent
#   export ACR_USERNAME='你的阿里云账号'
#   read -s ACR_PASSWORD && export ACR_PASSWORD     # 不写进仓库/脚本
#   sh tools/push-acr.sh
#
# 可选：
#   ACR_TAG=2.99.82          应用镜像标签，默认取 VERSION
#   ACR_PLATFORM=linux/amd64 目标架构，飞牛 x86_64 用默认值
#
# 推送内容：
#   <registry>/<namespace>/php:8.2-fpm-bookworm
#   <registry>/<namespace>/mysql:8.0
#   <registry>/<namespace>/redis:7-alpine
#   <registry>/<namespace>/certagent:<VERSION>
#   <registry>/<namespace>/certagent:latest

set -eu

cd "$(dirname "$0")/.."

log() { printf '[acr] %s\n' "$*"; }
die() { printf '[acr] 错误：%s\n' "$*" >&2; exit 1; }

command -v docker >/dev/null 2>&1 || die "未找到 docker，请在装有 Docker 的机器上执行"

ACR_REGISTRY="${ACR_REGISTRY:-crpi-e8ivxiujwrjswkb4.cn-beijing.personal.cr.aliyuncs.com}"
ACR_NAMESPACE="${ACR_NAMESPACE:-certagent}"
ACR_APP_REPO="${ACR_APP_REPO:-certagent}"
ACR_USERNAME="${ACR_USERNAME:-}"
ACR_TAG="${ACR_TAG:-$(cat VERSION 2>/dev/null || echo latest)}"
ACR_PLATFORM="${ACR_PLATFORM:-linux/amd64}"

[ -n "$ACR_NAMESPACE" ] || die "请先 export ACR_NAMESPACE=你的ACR命名空间"
[ -n "$ACR_USERNAME" ] || die "请先 export ACR_USERNAME=你的阿里云账号"

if [ -z "${ACR_PASSWORD:-}" ]; then
    printf 'ACR 密码（输入不回显）：' >&2
    stty -echo 2>/dev/null || true
    read -r ACR_PASSWORD
    stty echo 2>/dev/null || true
    printf '\n' >&2
fi
[ -n "$ACR_PASSWORD" ] || die "ACR 密码为空"

log "登录 $ACR_REGISTRY ..."
printf '%s' "$ACR_PASSWORD" | docker login "$ACR_REGISTRY" --username "$ACR_USERNAME" --password-stdin
unset ACR_PASSWORD

BASE_IMAGES="php:8.2-fpm-bookworm mysql:8.0 redis:7-alpine"

for src in $BASE_IMAGES; do
    name="${src%%:*}"
    tag="${src#*:}"
    dst="$ACR_REGISTRY/$ACR_NAMESPACE/$name:$tag"

    log "拉取 $src ..."
    docker pull --platform "$ACR_PLATFORM" "$src" || die "拉取 $src 失败；请配置 Docker 镜像加速，或在 ACR 控制台使用镜像同步"

    log "推送 $dst ..."
    docker tag "$src" "$dst"
    docker push "$dst" || die "推送失败；请先在 ACR 控制台创建命名空间 $ACR_NAMESPACE，并确认账号有推送权限"
done

APP_IMAGE="$ACR_REGISTRY/$ACR_NAMESPACE/$ACR_APP_REPO"
PHP_IMAGE="$ACR_REGISTRY/$ACR_NAMESPACE/php:8.2-fpm-bookworm"

log "构建应用镜像 $APP_IMAGE:$ACR_TAG（基础镜像使用 ACR 中的 php）..."
docker build \
    --platform "$ACR_PLATFORM" \
    --build-arg "PHP_BASE_IMAGE=$PHP_IMAGE" \
    -t "$APP_IMAGE:$ACR_TAG" \
    -t "$APP_IMAGE:latest" \
    . || die "应用镜像构建失败"

log "推送 $APP_IMAGE:$ACR_TAG ..."
docker push "$APP_IMAGE:$ACR_TAG" || die "推送应用镜像失败"

log "推送 $APP_IMAGE:latest ..."
docker push "$APP_IMAGE:latest" || die "推送 latest 失败"

cat <<EOF

[acr] 全部镜像已推送完成：
  $ACR_REGISTRY/$ACR_NAMESPACE/php:8.2-fpm-bookworm
  $ACR_REGISTRY/$ACR_NAMESPACE/mysql:8.0
  $ACR_REGISTRY/$ACR_NAMESPACE/redis:7-alpine
  $APP_IMAGE:$ACR_TAG
  $APP_IMAGE:latest

在飞牛仓库根目录 .env 中增加：
  ACR_REGISTRY=$ACR_REGISTRY
  ACR_NAMESPACE=$ACR_NAMESPACE
  ACR_APP_REPO=$ACR_APP_REPO
  ACR_TAG=$ACR_TAG

然后在飞牛上执行：
  docker login $ACR_REGISTRY --username '$ACR_USERNAME'
  docker compose -f docker-compose.acr.yml --env-file .env pull
  docker compose -f docker-compose.acr.yml --env-file .env up -d

提示：ACR 个人版默认私有仓库，飞牛必须先 docker login 才能拉取。
EOF
