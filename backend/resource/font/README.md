# 内置字体（验证码 / 图片水印用）

本目录的字体随代码仓库分发，**生产环境（宝塔）无需依赖服务器安装的系统字体**，
避免 `imagettftext(): Could not find/open font` 报错。

| 文件 | 用途 | 大小 |
|---|---|---|
| `DejaVuSans.ttf` | 验证码默认字体 | ~738 KB |
| `DejaVuSans-Bold.ttf` | 验证码加粗备选 | ~688 KB |

调用位置：`app/controller/api/Captcha.php` 的 `font()` 方法，
它优先查找 `$root/resource/font/`（`$root` 即 `backend/`），
找不到才回退到 `/usr/share/fonts/truetype/dejavu/...` 等系统路径；
若两者都不存在，则退回 GD 内置点阵字体 `imagestring()`，**不再抛错**。

## 许可

DejaVu Fonts 基于 Bitstream Vera Fonts 与 Arev Fonts 衍生，采用宽松许可，
允许自由使用、复制、修改与再分发（含商业用途），仅要求保留版权声明、不得用原作者名义背书。

- 版权 © 2003 Bitstream, Inc.（Vera Fonts）
- 版权 © 2006 Tavmjong Bah（Arev Fonts / DejaVu 增补）

详见 DejaVu Fonts License（与 Bitstream Vera Fonts Copyright 文本一致）。

## 注意

- 请勿删除本目录文件，否则验证码会自动降级为 GD 点阵字体（可显示但字形较粗糙）。
- 如需更换字体，把新的 `.ttf` 放进来，并在 `Captcha::font()` 的 `$candidates` 中排在前面即可。
