<?php

declare(strict_types=1);

namespace App\Services\System;

class MailService
{
    use \Framework\Core\Traits\StatefulTrait;

    /** @var array */
    private $appConfig;
    /** @var \Framework\Logger\LoggerInterface */
    private $logger;

    public function __construct(array $appConfig, \Framework\Logger\LoggerInterface $logger)
    {
        $this->appConfig = $appConfig;
        $this->logger = $logger;
    }

    /**
     * 发送邮件 (支持单发与群发)
     *
     * @param string|array $to 收件人。支持：
     *                         - 字符串: "user@example.com" 或 "user1@a.com,user2@b.com" (逗号/分号分隔)
     *                         - 索引数组: ["user1@a.com", "user2@b.com"]
     *                         - 关联数组: ["user1@a.com" => "Name1", "user2@b.com" => "Name2"]
     * @param string $subject 主题
     * @param string $body 正文 (HTML)
     * @param array $options 扩展选项:
     *                        - 'batch_mode': 'separate' (独立发送，默认，防垃圾) 或 'bcc' (密送)
     *                        - 'cc': 抄送地址 (数组或逗号分隔字符串)
     *                        - 'reply_to': 回复地址
     *                        - 'plain_text': 纯文本正文 (如果不提供则根据 body 自动提取)
     * @return array{status: string, message: string, detail: array}
     */
    public function send($to, string $subject, string $body, array $options = ['batch_mode' => 'separate']): array
    {
        try {
            // 1. 加载 SMTP 配置
            $smtplist = include \App\Core\Compile::include(APP_PATH . 'config/Smtp.php');
            if (empty($smtplist)) {
                return ['status' => 'error', 'message' => 'mailbox_not_configured', 'detail' => []];
            }

            // 2. 随机选择一个 SMTP 服务器进行负载均衡
            $n = array_rand($smtplist);
            $smtp = $smtplist[$n];
            if (empty($smtp['host']) || empty($smtp['port']) || empty($smtp['username']) || empty($smtp['password'])) {
                return ['status' => 'error', 'message' => 'configuration_error', 'detail' => []];
            }

            // 3. 解析收件人
            $recipients = $this->parseRecipients($to);
            if (empty($recipients)) {
                return ['status' => 'error', 'message' => 'empty_recipients', 'detail' => []];
            }

            // 4. 初始化 PHPMailer 并设置基础抗垃圾邮件配置
            $mail = $this->initMailer($smtp);
            $mail->Subject = $subject;
            $mail->Body = $body;
            $mail->AltBody = $options['plain_text'] ?? strip_tags(str_replace(['<br>', '<br/>', '</p>'], ["\n", "\n", "\n\n"], $body));

            // 设置回复地址
            if (!empty($options['reply_to'])) {
                $mail->addReplyTo($options['reply_to']);
            }

            // 5. 应用 DKIM 签名 (如果配置中存在)
            $this->applyDKIM($mail, $smtp);

            // 发件人
            $mail->setFrom($smtp['username'], $this->appConfig['sitename'] ?? 'WellCMS');

            $batchMode = $options['batch_mode'] ?? 'separate';
            $results = [];

            if ($batchMode === 'bcc' && count($recipients) > 1) {
                // 模式 A: 密送
                foreach ($recipients as $email => $name) {
                    $mail->addBCC($email, is_string($name) ? $name : '');
                }
                $mail->addAddress($smtp['username'], 'Undisclosed-recipients');
                $status = $mail->send();
                $results['all'] = $status;
                if (!$status) {
                    return ['status' => 'error', 'message' => $mail->ErrorInfo, 'detail' => $results];
                }
            } else {
                // 模式 B: 独立发送
                $successCount = 0;

                // 如果超过 2 位收件人，启用保持连接模式，提高效率并减少被目标服务器拉黑的权重
                if (count($recipients) > 1) {
                    $mail->SMTPKeepAlive = true;
                }

                foreach ($recipients as $email => $name) {
                    $mail->clearAddresses();
                    $mail->addAddress($email, is_string($name) ? $name : '');

                    if (!empty($options['cc'])) {
                        $this->addAddressesToMailer($mail, 'cc', $options['cc']);
                    }

                    // 发信抖动：防止突发大流量发信触发频率限制 (Anti-Spam Jitter)
                    if ($successCount > 0 && !empty($options['jitter'])) {
                        usleep(random_int(200000, 800000)); // 随机延迟 200-800ms
                    }

                    if ($mail->send()) {
                        $successCount++;
                        $results[$email] = true;
                    } else {
                        $results[$email] = $mail->ErrorInfo;
                        // 如果中途连接断开，尝试恢复
                        if (!$mail->getSMTPInstance()->connected()) {
                            $mail->getSMTPInstance()->connect($smtp['host'], $smtp['port']);
                        }
                    }
                }

                // 关闭连接
                if ($mail->SMTPKeepAlive) {
                    $mail->smtpClose();
                }

                if ($successCount === 0) {
                    return ['status' => 'error', 'message' => 'all_failed', 'detail' => $results];
                }
            }

            return ['status' => 'success', 'message' => 'send_success', 'detail' => $results];
        } catch (\Throwable $e) {
            $this->logger->error("MailService Send Error: " . $e->getMessage());
            return ['status' => 'error', 'message' => $e->getMessage(), 'detail' => []];
        }
    }

    private function initMailer(array $smtp): \App\PHPMailer\PHPMailer
    {
        $mail = new \App\PHPMailer\PHPMailer(true);
        $mail->CharSet = 'utf-8';
        $mail->Encoding = 'base64'; // 使用 Base64 编码，避免特殊字符导致的评分降低
        $mail->SMTPDebug = 0;
        $mail->isSMTP();

        // 基础配置
        $mail->Host = $smtp['host'];
        $mail->SMTPAuth = true;
        $mail->Username = $smtp['username'];
        $mail->Password = $smtp['password'];
        $mail->SMTPSecure = 'ssl';
        $mail->Port = $smtp['port'];
        $mail->SMTPAutoTLS = false;
        $mail->Timeout = 15;

        // 设置 Return-Path，确保退信处理链路完整
        $mail->Sender = $smtp['username'];

        // 抗垃圾邮件 (Anti-Spam) Header 优化
        $mail->XMailer = 'WellCMS Mailer';
        $mail->Priority = 3;

        // 尝试从应用配置中提取 Hostname，有助于通过 HELO/EHLO 检查
        if (!empty($this->appConfig['domain'])) {
            $host = parse_url($this->appConfig['domain'], PHP_URL_HOST);
            if ($host) $mail->Hostname = $host;
        }

        $mail->isHTML(true);

        return $mail;
    }

    /**
     * 应用 DKIM 签名
     * 需在 smtp.config.php 的 server 配置中添加:
     * 'dkim_domain' => 'example.com',
     * 'dkim_private' => '/path/to/private.key',
     * 'dkim_selector' => 'default',
     */
    private function applyDKIM(\App\PHPMailer\PHPMailer $mail, array $smtp): void
    {
        if (!empty($smtp['dkim_domain']) && !empty($smtp['dkim_private']) && file_exists($smtp['dkim_private'])) {
            $mail->DKIM_domain = $smtp['dkim_domain'];
            $mail->DKIM_private = $smtp['dkim_private'];
            $mail->DKIM_selector = $smtp['dkim_selector'] ?? 'default';
            $mail->DKIM_passphrase = $smtp['dkim_passphrase'] ?? '';
            $mail->DKIM_identity = $mail->From;
        }
    }

    /**
     * 解析各种格式的收件人为统一格式: [email => name]
     */
    private function parseRecipients($to): array
    {
        $recipients = [];
        if (is_string($to)) {
            // 支持 "email1,email2" 或 "email1;email2"
            $to = preg_split('/[,;]/', $to);
        }

        if (is_array($to)) {
            foreach ($to as $key => $val) {
                if (is_numeric($key)) {
                    // 索引数组: [email1, email2]
                    $email = trim((string)$val);
                    if ($email) $recipients[$email] = '';
                } else {
                    // 关联数组: [email => name]
                    $email = trim((string)$key);
                    if ($email) $recipients[$email] = trim((string)$val);
                }
            }
        }
        return $recipients;
    }

    /**
     * 辅助方法：向 Mailer 批量添加地址 (CC/BCC)
     */
    private function addAddressesToMailer(\App\PHPMailer\PHPMailer $mail, string $type, $addresses): void
    {
        $parsed = $this->parseRecipients($addresses);
        foreach ($parsed as $email => $name) {
            if ($type === 'cc') {
                $mail->addCC($email, $name);
            } elseif ($type === 'bcc') {
                $mail->addBCC($email, $name);
            }
        }
    }
}

/**
 * MailService 使用指南与示例
 *
 * --- 场景 1: 基础单发 (验证码、系统通知) ---
 * $mailService->send('user@domain.com', '验证码', '您的验证码是 123456');
 *
 * --- 场景 2: 带姓名的单发 ---
 * $mailService->send(['user@domain.com' => '张三'], '欢迎', '<h1>欢迎加入</h1>');
 *
 * --- 场景 3: 工业级群发 (独立发送模式，抗垃圾推荐) ---
 * // 这种模式会为每个收件人生成独立邮件，送达率最高
 * $recipients = [
 *     'a@domain.com' => '用户A',
 *     'b@domain.com', // 也可以不填姓名
 *     'c@domain.com' => '用户C'
 * ];
 * $result = $mailService->send($recipients, '批量通知', '内容', [
 *     'batch_mode' => 'separate', // 默认即为 separate
 *     'jitter' => true,            // 开启随机抖动延迟，防止突发流量触发服务商封禁
 * ]);
 *
 * --- 场景 4: 快速群发 (BCC 密送模式) ---
 * // 仅推荐在内部通知或收件人极少的情况下使用
 * $mailService->send($recipients, '会议提醒', '内容', ['batch_mode' => 'bcc']);
 *
 * --- 场景 5: 高级配置 (CC、回复、纯文本) ---
 * $mailService->send('to@a.com', '复杂邮件', 'HTML内容', [
 *     'cc' => 'manager@a.com',      // 抄送
 *     'reply_to' => 'help@a.com',   // 设置回复地址（有助于提高评分）
 *     'plain_text' => '这是纯文本摘要', // 覆盖自动生成的 AltBody
 * ]);
 *
 * --- 核心反垃圾邮件 (Anti-Spam) 说明 ---
 * 1. DKIM 签名:
 *    请在 config/smtp.config.php 的服务器配置项中添加:
 *    'dkim_domain' => 'yourdomain.com',
 *    'dkim_private' => '/path/to/dkim_private.key',
 *    'dkim_selector' => 'default',
 *    系统会自动识别并为每一封邮件进行高强度加签。
 *
 * 2. 自动 AltBody:
 *    系统会自动转换 HTML 到纯文本，满足反垃圾扫描器的“双格式”检查。
 *
 * 3. 动态 Hostname:
 *    自动关联 AppConfig 中的域名作为 HELO 身份，通过 ISP 完整性检查。
 *
 * 4. SMTP 保持连接:
 *    群发时自动启用 SMTPKeepAlive，维持长连接，表现更像真实的邮件服务器而非脚本。
 */
