<?php
namespace app\controller\api;

use app\BaseController;
use think\facade\Cache;
use think\facade\Config;

/**
 * 图形验证码
 * GET /api/captcha  =>  { code:0, data:{ key, image:"data:image/png;base64,..." } }
 */
class Captcha extends BaseController
{
    public function index()
    {
        $cfg    = Config::get('security');
        $length = (int)($cfg['captcha_length'] ?? 4);
        $chars  = $cfg['captcha_chars'] ?? '23456789ABCDEFGHJKLMNPQRSTUVWXYZ';
        $expire = (int)($cfg['captcha_expire'] ?? 300);

        $key  = $this->request->get('key', '');
        // v2.99.1（BUG-05）：key 必须是标量。此前 ?key[]=x 会得到数组，
        // 拼进缓存键时触发 Array to string conversion 并返回 500。
        if (is_array($key) || is_object($key)) {
            return json(r_error('参数 key 格式不正确', 400));
        }
        $key = trim((string)$key);
        if ($key !== '' && !preg_match('/^[A-Za-z0-9_\-]{4,64}$/', $key)) {
            return json(r_error('参数 key 格式不正确', 400));
        }
        if (!$key) {
            $key = bin2hex(random_bytes(16));
        }

        // 生成随机码
        $code = '';
        $max  = strlen($chars) - 1;
        for ($i = 0; $i < $length; $i++) {
            $code .= $chars[random_int(0, $max)];
        }
        Cache::set('captcha_' . $key, strtolower($code), $expire);

        $image = $this->draw($code);

        return json([
            'code' => 0,
            'msg'  => 'ok',
            'data' => [
                'key'   => $key,
                'image' => 'data:image/png;base64,' . base64_encode($image),
            ],
        ]);
    }

    /**
     * 用 GD 绘制验证码图片
     */
    private function draw(string $code): string
    {
        $w = 120;
        $h = 44;
        $img = imagecreatetruecolor($w, $h);

        // 背景
        $bg = imagecolorallocate($img, 245, 246, 248);
        imagefill($img, 0, 0, $bg);

        // 干扰线
        for ($i = 0; $i < 4; $i++) {
            $c = imagecolorallocate($img, random_int(160, 220), random_int(160, 220), random_int(160, 220));
            imageline($img, random_int(0, $w), random_int(0, $h), random_int(0, $w), random_int(0, $h), $c);
        }
        // 干扰点
        for ($i = 0; $i < 60; $i++) {
            $c = imagecolorallocate($img, random_int(180, 230), random_int(180, 230), random_int(180, 230));
            imagesetpixel($img, random_int(0, $w), random_int(0, $h), $c);
        }

        // 字符
        $len = strlen($code);
        $colors = [
            [54, 95, 145], [86, 140, 60], [180, 80, 70], [120, 70, 150], [40, 120, 130],
        ];
        // 字体只解析一次；取不到字体文件时退回 GD 内置点阵字体，保证任何环境都不报错
        $font = $this->font();
        for ($i = 0; $i < $len; $i++) {
            $col = $colors[$i % count($colors)];
            $c = imagecolorallocate($img, $col[0], $col[1], $col[2]);
            $size  = random_int(20, 26);
            $angle = random_int(-18, 18);
            $x = 12 + $i * ($w / $len);
            $y = random_int(28, 36);
            if ($font !== '') {
                imagettftext($img, $size, $angle, (int)$x, (int)$y, $c, $font, $code[$i]);
            } else {
                // 内置点阵字体（font 5），无需任何 .ttf 文件
                imagestring($img, 5, (int)$x, (int)random_int(10, 18), $code[$i], $c);
            }
        }

        ob_start();
        imagepng($img);
        $data = ob_get_clean();
        imagedestroy($img);
        return $data;
    }

    /**
     * 字体文件绝对路径（取不到时返回空串，调用方会退回 GD 内置点阵字体）
     *
     * 顺序：
     *   1. 项目内置字体 backend/resource/font/*.ttf（随代码分发，生产环境首选，
     *      避免服务器未安装系统字体导致 imagettftext(): Could not find/open font）
     *   2. 系统自带 TrueType 字体（本地开发 / 其他环境兜底）
     *
     * 注意：本文件位于 backend/app/controller/api/，故 dirname(__DIR__, 3) 即 backend 根目录。
     */
    private function font(): string
    {
        $root = dirname(__DIR__, 3);
        if (!is_dir($root . '/app')) {
            // 目录结构异常时退回框架根路径
            try {
                $root = rtrim((string)app()->getRootPath(), '/\\');
            } catch (\Throwable $e) {
                // 保持 $root 不变
            }
        }

        $candidates = [
            // —— 随仓库分发的内置字体（DejaVu，可自由分发，见 backend/resource/font/README.md）——
            $root . '/resource/font/DejaVuSans-Bold.ttf',
            $root . '/resource/font/DejaVuSans.ttf',
            // —— 系统字体兜底 ——
            'C:/Windows/Fonts/arial.ttf',
            'C:/Windows/Fonts/msyh.ttc',
            '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf',
            '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf',
            '/usr/share/fonts/dejavu/DejaVuSans.ttf',
            '/System/Library/Fonts/Supplemental/Arial.ttf',
            '/System/Library/Fonts/Supplemental/Arial Bold.ttf',
        ];
        foreach ($candidates as $f) {
            if ($f !== '' && is_file($f) && is_readable($f)) {
                return $f;
            }
        }
        return '';
    }
}
