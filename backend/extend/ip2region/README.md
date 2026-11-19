# ip2region 离线 IP 归属地库（v2.99.50）

本目录随系统分发 IP 归属地数据文件，供 `app/service/IpCityService.php` 在服务端本地解析
登录 IP 归属城市，用于异地登录检测。**解析全程不联网、不向任何第三方发送 IP。**

## 文件

| 文件 | 说明 |
|---|---|
| `ip2region_v4.xdb` | ip2region 官方 IPv4 数据（xdb v2 结构，约 11.1 MB），字段为 `国家\|省份\|城市\|ISP\|国家代码` |

## 来源与许可

- 项目：https://github.com/lionsoul2014/ip2region
- 数据文件：`data/ip2region_v4.xdb`（master 分支，下载日期 2026-11-15）
- 许可：Apache License 2.0（与本系统兼容）

## 升级数据

1. 从官方仓库下载新的 `ip2region_v4.xdb` 覆盖本文件；或
2. 在 `system_config` 中设置 `ip2region_db` 指向其他路径（例如独立挂载的数据盘），
   系统优先使用该路径，文件不存在时回退到本目录。

> 仅支持 IPv4 数据。纯 IPv6 客户端与内网/回环地址会被判为「归属地未知」，不做异地拦截，
> 避免因解析能力缺失误伤正常登录。
