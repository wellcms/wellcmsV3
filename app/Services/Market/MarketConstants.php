<?php
/**
 * MarketClient 客户端专用常量
 *
 * 注意：此为客户端核心常量，严禁引用服务端插件类。
 * 所有部署的客户端共用这个文件，与服务端代码完全解耦。
 */

declare(strict_types=1);

namespace App\Services\Market;

class MarketConstants
{
    /** 会话密钥有效期（秒）- 与服务端协商一致 */
    public const SESSION_TTL = 300;

    /** 会话提前续约阈值（秒）- 剩余2分钟时触发续约 */
    public const SESSION_RENEW_THRESHOLD = 120;

    /** 客户端会话最小剩余时间（秒）- 低于此值视为无效，需重新申请 */
    public const CLIENT_SESSION_MIN_REMAINING = 60;
}
