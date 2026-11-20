#!/bin/sh
#
# 飞牛 fnOS 一键部署（阿里云 ACR，全程不本地构建、不访问 Docker Hub）
#
# 用法：
#   sh deploy-acr.sh                 # 使用 .env 中的 ACR_TAG
#   sh deploy-acr.sh 2.99.81         # 直接指定镜像版本（自动写回 .env）
#
# 前置：
#   1. 把整个仓库目录复制到飞牛（例如 /vol1/1000/docker/cert-expiry-manager-fnos）
#   2. 二选一提供 ACR 凭据：
#      a) 在 .env 填写 ACR_USERNAME / ACR_PASSWORD（零交互，.env 不会入库/入镜像）
#      b) 用环境变量 ACR_USERNAME / ACR_PASSWORD 运行本脚本
#      c) 都不提供时，脚本会交互提示输入（私有仓库必须登录）
#
set -eu
cd "$(dirname "$0")"

if [ -n "${1:-}" ]; then
    exec sh install.sh --acr --tag "$1"
fi
exec sh install.sh --acr
