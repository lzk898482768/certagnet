<?php
namespace app\service\push;

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as MailException;

/**
 * 邮件驱动（SMTP，基于 PHPMailer）
 *
 * 说明：
 * - 缺少收件邮箱 -> 直接判失败（旧实现判成功，掩盖了「接收人为空」这一主要故障）
 * - 缺少 SMTP 配置 -> 模拟发送（status=1 + simulated=true），保证链路可跑通
 */
class EmailDriver implements PushDriverInterface
{
    protected $config;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    public function send(array $receiver, string $content): array
    {
        if (empty($receiver['email'])) {
            return ['status' => 2, 'msg' => '缺少接收邮箱', 'simulated' => false, 'response' => ''];
        }
        if (empty($this->config['smtp_host'])) {
            return [
                'status'    => 1,
                'msg'       => '未配置SMTP，已记录(模拟发送)',
                'simulated' => true,
                'response'  => '',
            ];
        }
        try {
            $mail = new PHPMailer(true);
            $mail->isSMTP();
            $mail->Host       = $this->config['smtp_host'];
            $mail->SMTPAuth   = true;
            $mail->Username   = $this->config['username'] ?? '';
            $mail->Password   = $this->config['password'] ?? '';
            $mail->SMTPSecure = $this->config['secure'] ?? 'ssl';
            $mail->Port       = (int)($this->config['smtp_port'] ?? 465);
            $mail->CharSet    = 'UTF-8';
            $mail->Timeout    = 10;

            $mail->setFrom($this->config['username'] ?? '', $this->config['from_name'] ?? '证书预警系统');
            $mail->addAddress($receiver['email']);
            $mail->isHTML(true);
            $mail->Subject = '证书到期预警通知';
            $mail->Body    = nl2br($content);
            $mail->send();
            return [
                'status'    => 1,
                'msg'       => '邮件发送成功',
                'simulated' => false,
                'response'  => '',
            ];
        } catch (MailException $e) {
            return [
                'status'    => 2,
                'msg'       => '邮件发送失败: ' . $e->getMessage(),
                'simulated' => false,
                'response'  => '',
            ];
        } catch (\Throwable $e) {
            return [
                'status'    => 2,
                'msg'       => '邮件发送异常: ' . $e->getMessage(),
                'simulated' => false,
                'response'  => '',
            ];
        }
    }
}
