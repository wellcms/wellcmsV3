<?php

declare(strict_types=1);
/*
 * Copyright (C) www.wellcms.com
*/

namespace App\Utils;


class I18nDateFormatter
{
    use \Framework\Core\Traits\StatefulTrait;


    /** @var array<string, array{date: array, time: array}> */
    protected static $customFormats = []; // 开放注册的格式表（优先级最高）
    /** @var string */
    protected $locale;
    /** @var string */
    protected $timezone;


    /** 语言默认地区映射，用于 locale 缺地区时自动补全 */
    /** @var array */
    protected static $defaultRegion = [
        'en' => 'US', // 美国
        'zh' => 'CN', // 中国
        'tw' => 'TW', // 台湾
        'hk' => 'HK', // 香港
        'mo' => 'MO', // 澳门
        'de' => 'DE', // 德国
        'fr' => 'FR', // 法国
        'ja' => 'JP', // 日本
        'nl' => 'NL', // 荷兰
        'ko' => 'KR', // 韩国
        'es' => 'ES', // 西班牙
        'pt' => 'BR', // 巴西
        'it' => 'IT', // 意大利
        'ru' => 'RU', // 俄罗斯
        'ar' => 'SA', // 阿拉伯
        'tr' => 'TR', // 土耳其
        'pl' => 'PL', // 波兰
        'th' => 'TH', // 泰国
        'vi' => 'VN', // 越南
        'id' => 'ID', // 印度尼西亚
        'ph' => 'PH', //菲律宾
        'my' => 'MY', //马来西亚
        'sg' => 'SG', //新加坡
        'ro' => 'RO', //罗马尼亚
        // 需要更多可在项目里扩展
        // hook app_Utils_I18nDateFormatter_defaul.php
    ];

    /**
     * 构造
     */
    public function __construct(?string $lang = null, string $timezone = 'UTC')
    {
        $lang = $lang ? \App\I18n\LocaleMapper::toICU($lang) : null;
        $this->setLang($lang);
        $this->setTimezone($timezone);
    }

    // 对外扩展：注册/覆盖某个 locale 的日期与时间格式（降级分支使用）
    public static function registerFormats(string $locale, array $dateFormats, array $timeFormats): void
    {
        $icu = self::toICULocale($locale); // 统一成 zh_CN / en_US
        self::$customFormats[$icu] = [
            'date' => $dateFormats,
            'time' => $timeFormats,
        ];
    }

    /**
     * 主接口：格式化
     * @param mixed $datetime  时间戳/DateTimeInterface/字符串
     * @param string|int $dateType  short|medium|long|full|none 或 Intl 常量
     * @param string|int $timeType  同上
     */
    public function format($datetime, string $dateType = 'medium', string $timeType = 'none'): string
    {
        // 归一化时间对象
        if ($datetime instanceof \DateTimeInterface) {
            $dt = (new \DateTimeImmutable('@' . $datetime->getTimestamp()))
                ->setTimezone($datetime->getTimezone() ?: new \DateTimeZone($this->getTimezone()));
        } elseif (is_numeric($datetime)) {
            $dt = (new \DateTimeImmutable('@' . $datetime))->setTimezone(new \DateTimeZone($this->getTimezone()));
        } else {
            $dt = new \DateTimeImmutable((string)$datetime, new \DateTimeZone($this->getTimezone()));
        }

        // 优先使用 Intl
        if (class_exists('IntlDateFormatter')) {
            $dateTypeInt = is_int($dateType) ? $dateType : $this->getIntlConstant($dateType);
            $timeTypeInt = is_int($timeType) ? $timeType : $this->getIntlConstant($timeType);

            $fmt = new \IntlDateFormatter(
                $this->getLocale(), // ICU/BCP47 都可，这里传 ICU 形式
                $dateTypeInt,
                $timeTypeInt,
                $this->getTimezone(),
                \IntlDateFormatter::GREGORIAN
            );
            $out = $fmt->format($dt);
            if ($out === false) {
                // 极端情况下 ICU 数据不完整时兜底
                return $this->formatFallback($dt, $dateType, $timeType);
            }
            return $out;
        }

        // 降级表
        return $this->formatFallback($dt, $dateType, $timeType);
    }

    /**
     * 降级实现（无 intl 扩展）
     * @param int $timeType
     */
    protected function formatFallback(\DateTimeInterface $dt, $dateType, $timeType): string
    {
        // 统一成字符串标识
        $dateTypeStr = is_string($dateType) ? strtolower($dateType) : $this->intlConstToString($dateType);
        $timeTypeStr = is_string($timeType) ? strtolower($timeType) : $this->intlConstToString($timeType);

        [$dateFormats, $timeFormats] = $this->getFormatsForLocale($this->getLocale());

        $dateFormat = $dateFormats[$dateTypeStr] ?? $dateFormats['long'] ?? 'Y-m-d';
        $timeFormat = $timeFormats[$timeTypeStr] ?? $timeFormats['short'] ?? 'H:i';

        $result = '';
        if ($dateTypeStr !== 'none' && $dateFormat !== '') {
            $result = $dt->format($dateFormat);
        }
        if ($timeTypeStr !== 'none' && $timeFormat !== '') {
            // 如果没有日期部分，时间直接输出；有日期则空格拼接
            $result = $result ? ($result . ' ' . $dt->format($timeFormat)) : $dt->format($timeFormat);
        }
        return $result;
    }

    /**
     * 将字符串 short/medium/long/full/none 转 Intl 常量
     */
    protected function getIntlConstant(string $type): int
    {
        $type = strtolower((string)$type);
        switch ($type) {
            case 'short':
                return \IntlDateFormatter::SHORT;
            case 'medium':
                return \IntlDateFormatter::MEDIUM;
            case 'long':
                return \IntlDateFormatter::LONG;
            case 'full':
                return \IntlDateFormatter::FULL;
            case 'none':
            default:
                return \IntlDateFormatter::NONE;
        }
    }

    protected function intlConstToString($const): string
    {
        // 仅用于降级分支的映射回字符串
        $map = [
            \IntlDateFormatter::NONE   => 'none',
            \IntlDateFormatter::SHORT  => 'short',
            \IntlDateFormatter::MEDIUM => 'medium',
            \IntlDateFormatter::LONG   => 'long',
            \IntlDateFormatter::FULL   => 'full',
        ];
        return $map[$const] ?? 'long';
    }

    /**
     * 语言检测（从快照获取，确保协程安全）
     */
    protected function detectUserLang(string $default = 'en_US'): string
    {
        // 优先从 IpHelper 统一快照中获取，避免直接访问 $_SERVER
        $language = \Framework\Utils\IpHelper::acceptLanguage();
        if (empty($language)) return $default;
        // 拿第一段
        $lang = trim(explode(',', $language)[0]);
        return $lang ?: $default;
    }

    /**
     * 设定时区
     */
    public function setTimezone(string $timezone): self
    {
        $this->setState('timezone', $timezone);
        return $this;
    }

    public function getTimezone(): string
    {
        return $this->getState('timezone', 'UTC');
    }

    /**
     * 设定语言（支持 zh-CN/zh_CN/en-US/en_US 等；会做规范化与回退）
     * 返回 ICU 风格（zh_CN）
     */
    public function setLang(string $lang): string
    {
        $lang = $lang ?: $this->detectUserLang();

        // 1) 正规化成 ICU 风格 zh_CN
        $icu = self::toICULocale($lang);

        // 2) 回退策略：精确匹配（地区）→ 语言级（zh）→ 默认 en_US
        $resolved = $this->resolveLocaleForFormats($icu);

        $this->setState('locale', $resolved);
        return $resolved;
    }

    public function getLocale(): string
    {
        return $this->getState('locale', 'en_US');
    }

    /**
     * 获取某个 ICU locale 的格式表（先找注册表 → 语言级 → 内建默认 → 英文默认）
     * @return array {0: array $dateFormats, 1: array $timeFormats}
     */
    protected function getFormatsForLocale(string $icu): array
    {
        // 优先：自定义注册（地区级）
        if (isset(self::$customFormats[$icu])) {
            return [self::$customFormats[$icu]['date'], self::$customFormats[$icu]['time']];
        }

        // 尝试语言级（如 'zh')
        $lang = strtok($icu, '_') ?: $icu;
        if (isset(self::$customFormats[$lang])) {
            return [self::$customFormats[$lang]['date'], self::$customFormats[$lang]['time']];
        }

        // 内置
        $builtIn = $this->getBuiltInFormats();

        if (isset($builtIn[$icu])) {
            return [$builtIn[$icu]['date'], $builtIn[$icu]['time']];
        }
        if (isset($builtIn[$lang])) {
            return [$builtIn[$lang]['date'], $builtIn[$lang]['time']];
        }

        // 英文
        return [$builtIn['en_US']['date'], $builtIn['en_US']['time']];
    }

    /**
     * 回解析：把传入的 ICU locale（可能没有地区）解析为可用的 key
     * 例如：fr → fr_FR；zh → zh_CN
     */
    protected function resolveLocaleForFormats(string $icu): string
    {
        $builtIn = $this->getBuiltInFormats();

        // 地区级已存在
        if (isset(self::$customFormats[$icu]) || isset($builtIn[$icu])) {
            return $icu;
        }

        // 没有地区时，自动补默认地区
        $parts = explode('_', $icu);
        $lang  = strtolower($parts[0] ?? '');
        $region = strtoupper($parts[1] ?? '');

        if ($lang && !$region) {
            $region = self::$defaultRegion[$lang] ?? null;
            if ($region) {
                $icu2 = $lang . '_' . $region;
                if (isset(self::$customFormats[$icu2]) || isset($builtIn[$icu2])) {
                    return $icu2;
                }
            }
        }

        // 语言级存在
        if ($lang && (isset(self::$customFormats[$lang]) || isset($builtIn[$lang]))) {
            return $icu; // 保持原样（语言级）
        }

        return 'en_US';
    }

    /**
     * 内置默认格式（可按需扩展更多语言；也支持仅语言级定义）
     * 说明：这里仍采用 PHP 的 date() pattern（用于降级分支）
     */
    protected function getBuiltInFormats(): array
    {
        return [

            // ===== 中文 =====

            'zh' => [ // 泛中文 fallback
                'date' => [
                    'short'  => 'Y/m/d',
                    'medium' => 'Y年m月d日',
                    'long'   => 'Y年m月d日',
                    'full'   => 'Y年m月d日 l',
                    'none'   => '',
                ],
                'time' => [
                    'short'  => 'H:i',
                    'medium' => 'H:i:s',
                    'long'   => 'H:i:s',
                    'full'   => 'H:i:s',
                    'none'   => '',
                ],
            ],

            'zh_CN' => [ // 中国大陆
                'date' => [
                    'short'  => 'Y/m/d',
                    'medium' => 'Y年m月d日',
                    'long'   => 'Y年m月d日',
                    'full'   => 'Y年m月d日 l',
                    'none'   => '',
                ],
                'time' => [
                    'short'  => 'H:i',
                    'medium' => 'H:i:s',
                    'long'   => 'H:i:s',
                    'full'   => 'H:i:s',
                    'none'   => '',
                ],
            ],

            'zh_TW' => [ // 台湾习惯
                'date' => [
                    'short'  => 'Y/m/d',
                    'medium' => 'Y年m月d日',
                    'long'   => 'Y年m月d日',
                    'full'   => 'Y年m月d日 l',
                    'none'   => '',
                ],
                'time' => [
                    'short'  => 'H:i',
                    'medium' => 'H:i:s',
                    'long'   => 'H:i:s',
                    'full'   => 'H:i:s',
                    'none'   => '',
                ],
            ],

            // ===== 英语 =====

            'en' => [ // 语言级 fallback
                'date' => [
                    'short'  => 'm/d/Y',
                    'medium' => 'M d, Y',
                    'long'   => 'F d, Y',
                    'full'   => 'l, F d, Y',
                    'none'   => '',
                ],
                'time' => [
                    'short'  => 'H:i',
                    'medium' => 'H:i:s',
                    'long'   => 'H:i:s',
                    'full'   => 'H:i:s',
                    'none'   => '',
                ],
            ],

            'en_US' => [ // 美国
                'date' => [
                    'short'  => 'm/d/Y',
                    'medium' => 'M d, Y',
                    'long'   => 'F d, Y',
                    'full'   => 'l, F d, Y',
                    'none'   => '',
                ],
                'time' => [
                    'short'  => 'H:i',
                    'medium' => 'H:i:s',
                    'long'   => 'H:i:s',
                    'full'   => 'H:i:s',
                    'none'   => '',
                ],
            ],

            // ===== 欧洲常见 =====

            'fr_FR' => [
                'date' => [
                    'short'  => 'd/m/Y',
                    'medium' => 'd M Y',
                    'long'   => 'd F Y',
                    'full'   => 'l d F Y',
                    'none'   => '',
                ],
                'time' => [
                    'short'  => 'H:i',
                    'medium' => 'H:i:s',
                    'long'   => 'H:i:s',
                    'full'   => 'H:i:s',
                    'none'   => '',
                ],
            ],

            'de_DE' => [
                'date' => [
                    'short'  => 'd.m.Y',
                    'medium' => 'd. M Y',
                    'long'   => 'd. F Y',
                    'full'   => 'l, d. F Y',
                    'none'   => '',
                ],
                'time' => [
                    'short'  => 'H:i',
                    'medium' => 'H:i:s',
                    'long'   => 'H:i:s',
                    'full'   => 'H:i:s',
                    'none'   => '',
                ],
            ],

            'nl_NL' => [ // 荷兰
                'date' => [
                    'short'  => 'd-m-Y',
                    'medium' => 'd M Y',
                    'long'   => 'd F Y',
                    'full'   => 'l d F Y',
                    'none'   => '',
                ],
                'time' => [
                    'short'  => 'H:i',
                    'medium' => 'H:i:s',
                    'long'   => 'H:i:s',
                    'full'   => 'H:i:s',
                    'none'   => '',
                ],
            ],

            'es_ES' => [ // 西班牙
                'date' => [
                    'short'  => 'd/m/Y',
                    'medium' => 'd M Y',
                    'long'   => 'd F Y',
                    'full'   => 'l d F Y',
                    'none'   => '',
                ],
                'time' => [
                    'short'  => 'H:i',
                    'medium' => 'H:i:s',
                    'long'   => 'H:i:s',
                    'full'   => 'H:i:s',
                    'none'   => '',
                ],
            ],

            'pt_PT' => [ // 葡萄牙
                'date' => [
                    'short'  => 'd/m/Y',
                    'medium' => 'd M Y',
                    'long'   => 'd F Y',
                    'full'   => 'l, d F Y',
                    'none'   => '',
                ],
                'time' => [
                    'short'  => 'H:i',
                    'medium' => 'H:i:s',
                    'long'   => 'H:i:s',
                    'full'   => 'H:i:s',
                    'none'   => '',
                ],
            ],

            'it_IT' => [ // 意大利
                'date' => [
                    'short'  => 'd/m/Y',
                    'medium' => 'd M Y',
                    'long'   => 'd F Y',
                    'full'   => 'l d F Y',
                    'none'   => '',
                ],
                'time' => [
                    'short'  => 'H:i',
                    'medium' => 'H:i:s',
                    'long'   => 'H:i:s',
                    'full'   => 'H:i:s',
                    'none'   => '',
                ],
            ],

            'ru_RU' => [ // 俄罗斯
                'date' => [
                    'short'  => 'd.m.Y',
                    'medium' => 'd M Y',
                    'long'   => 'd F Y',
                    'full'   => 'l, d F Y',
                    'none'   => '',
                ],
                'time' => [
                    'short'  => 'H:i',
                    'medium' => 'H:i:s',
                    'long'   => 'H:i:s',
                    'full'   => 'H:i:s',
                    'none'   => '',
                ],
            ],

            // ===== 亚洲 =====

            'ja_JP' => [ // 日本
                'date' => [
                    'short'  => 'Y/m/d',
                    'medium' => 'Y年m月d日',
                    'long'   => 'Y年m月d日',
                    'full'   => 'Y年m月d日 l',
                    'none'   => '',
                ],
                'time' => [
                    'short'  => 'H:i',
                    'medium' => 'H:i:s',
                    'long'   => 'H:i:s',
                    'full'   => 'H:i:s',
                    'none'   => '',
                ],
            ],

            'ko_KR' => [ // 韩国
                'date' => [
                    'short'  => 'Y.m.d',
                    'medium' => 'Y년 m월 d일',
                    'long'   => 'Y년 m월 d일',
                    'full'   => 'Y년 m월 d일 l',
                    'none'   => '',
                ],
                'time' => [
                    'short'  => 'H:i',
                    'medium' => 'H:i:s',
                    'long'   => 'H:i:s',
                    'full'   => 'H:i:s',
                    'none'   => '',
                ],
            ],

            // ===== 中东 =====

            'ar_SA' => [ // 阿拉伯（常见沙特格式，仍用公历）
                'date' => [
                    'short'  => 'd/m/Y',
                    'medium' => 'd M Y',
                    'long'   => 'd F Y',
                    'full'   => 'l d F Y',
                    'none'   => '',
                ],
                'time' => [
                    'short'  => 'H:i',
                    'medium' => 'H:i:s',
                    'long'   => 'H:i:s',
                    'full'   => 'H:i:s',
                    'none'   => '',
                ],
            ],

            // ===== 土耳其 =====

            'tr_TR' => [
                'date' => [
                    'short'  => 'd.m.Y',
                    'medium' => 'd M Y',
                    'long'   => 'd F Y',
                    'full'   => 'd F Y l',
                    'none'   => '',
                ],
                'time' => [
                    'short'  => 'H:i',
                    'medium' => 'H:i:s',
                    'long'   => 'H:i:s',
                    'full'   => 'H:i:s',
                    'none'   => '',
                ],
            ],
        ];
    }

    /**
     * 统一把传入 locale 转为 ICU 风格（en_US / zh_CN）
     */
    protected static function toICULocale(string $locale): string
    {
        $locale = trim($locale);
        if ($locale === '') return 'en_US';

        // 1) 先试图用 intl 的 canonicalize
        if (class_exists('Locale')) {
            $canon = \Locale::canonicalize($locale); // 可能得到 zh_Hans_CN 等
            if ($canon) {
                // 替换 - 为 _，并确保 language 小写、region 大写
                //$canon = str_replace('-', '_', $canon);
                $canon = strtr($canon, ['-' => '_']);
                $parts = explode('_', $canon);
                $lang = strtolower($parts[0] ?? '');
                $script = isset($parts[1]) && strlen($parts[1]) === 4 ? ucfirst(strtolower($parts[1])) : null;
                $region = strtoupper($parts[$script ? 2 : 1] ?? '');

                if ($lang && $region) return $lang . '_' . $region;

                if ($lang) return $lang; // 语言级
            }
        }

        // 2) 手工规范：把 - 换成 _
        //$locale = str_replace('-', '_', $locale);
        $locale = strtr($locale, ['-' => '_']);
        $chunks = explode('_', $locale);
        $lang   = strtolower($chunks[0] ?? '');
        $region = strtoupper($chunks[1] ?? '');

        if (!$lang) return 'en_US';

        // 没地区则留语言级，后续再补默认地区
        if (!$region) return $lang;

        return $lang . '_' . $region;
    }
}

/*
需要扩展包支持
if (class_exists('IntlDateFormatter')) {
    // 用你的 IntlDateFormatter 逻辑
} else {
    // 用自定义格式化
}
*/

/*
$dtString = '2026-01-01 00:00:00'; // UTC 时间字符串
$date = new \DateTime($dtString, new \DateTimeZone("UTC"));

$dtString = '2025-08-11 09:30:00'; // UTC 时间字符串
$timestamp = strtotime($dtString); // 时间戳
$dtObj = new DateTimeImmutable($dtString, new DateTimeZone('UTC'));
*/

/*------------------------------------------------------------
 | 1) 最基础：中文与英文，日期/时间组合
 *-----------------------------------------------------------*/
/*
$cn = new I18nDateFormatter('zh-CN', 'Asia/Shanghai');
$en = new I18nDateFormatter('en_US', 'America/New_York');

// 传入“字符串”
echo $cn->format($dtString, 'full', 'short'), "\n"; // 2025年08月11日 星期一 17:30
echo $en->format($dtString, 'full', 'short'), "\n"; // Monday, August 11, 2025 05:30  (NY 夏令时) */

/*------------------------------------------------------------
 | 2) 传入不同的时间类型：时间戳 / DateTimeInterface
 *-----------------------------------------------------------*/
/*
echo $cn->format($timestamp, 'long', 'none'), "\n"; // 2025年08月11日
echo $en->format(new DateTime('2025-08-11 09:30:00', new DateTimeZone('UTC')), 'medium', 'short'), "\n"; // Aug 11, 2025 05:30 */

/*------------------------------------------------------------
 | 3) 使用 Intl 常量（SHORT/MEDIUM/LONG/FULL/NONE）
 *-----------------------------------------------------------*/
/*
echo $en->format($dtObj, IntlDateFormatter::SHORT, IntlDateFormatter::NONE), "\n";   // 8/11/25
echo $en->format($dtObj, IntlDateFormatter::MEDIUM, IntlDateFormatter::SHORT), "\n"; // Aug 11, 2025 5:30 AM */

/*------------------------------------------------------------
 | 4) 切换时区
 *-----------------------------------------------------------*/
/*
$en->setTimezone('Europe/London');
echo $en->format($dtObj, 'full', 'full'), "\n"; // Monday, August 11, 2025 10:30:00  (伦敦夏令时) */

/*------------------------------------------------------------
 | 5) 仅日期 / 仅时间（使用 "none" 控制）
 *-----------------------------------------------------------*/
/*
echo $cn->format($dtObj, 'long', 'none'), "\n";   // 2025年08月11日
echo $cn->format($dtObj, 'none', 'short'), "\n";  // 17:30   （上海时区下）
echo $en->format($dtObj, 'none', 'full'), "\n";   // 11:30:00 （伦敦时区）根据上一步 setTimezone */

/*------------------------------------------------------------
 | 6) 动态注册一个新地区：es_MX（演示“无 intl”分支也能友好显示）
 *   注意：只有在 intl 不可用或 ICU 格式失败时才会走此降级表
 *-----------------------------------------------------------*/
/*
I18nDateFormatter::registerFormats(
    'es_MX',
    [
        'short'  => 'd/m/Y',
        'medium' => 'd M Y',
        'long'   => 'd \\d\\e F \\d\\e Y',
        'full'   => 'l, d \\d\\e F \\d\\e Y',
        'none'   => '',
    ],
    [
        'short'  => 'H:i',
        'medium' => 'H:i:s',
        'long'   => 'H:i:s',
        'full'   => 'H:i:s',
        'none'   => '',
    ]
);
$mx = new I18nDateFormatter('es-MX', 'America/Mexico_City');
echo $mx->format($dtObj, 'full', 'short'), "\n"; // lunes, 11 de agosto de 2025 04:30 （示例，实际以 intl/降级为准） */

/*------------------------------------------------------------
 | 7) 只给语言不给地区：自动补默认地区（fr -> fr_FR）
 *-----------------------------------------------------------*/
/*
$fr = new I18nDateFormatter('fr', 'Europe/Paris');
echo $fr->format($dtObj, 'long', 'short'), "\n"; // 11 août 2025 11:30  （巴黎夏令时） */

/*------------------------------------------------------------
 | 8) 从 Accept-Language 自动检测（模拟）
 *   你的类里调用 RequestUtils::server('HTTP_ACCEPT_LANGUAGE')
 *   这里只是演示思路：模拟一个请求头
 *-----------------------------------------------------------*/
/*
// 模拟服务端环境变量（你的 RequestUtils::server 会去取它）
$_SERVER['HTTP_ACCEPT_LANGUAGE'] = 'de-DE,de;q=0.9,en;q=0.8';
// 不传 lang，走 detectUserLang():
$auto = new I18nDateFormatter(null, 'Europe/Berlin');
echo $auto->getLocale(), "\n";                           // 按设定会规范为 de_DE 或 de
echo $auto->format($dtObj, 'full', 'short'), "\n";       // Montag, 11. August 2025 11:30 */

/*------------------------------------------------------------
 | 9) 国际化覆盖/回退链演示
 |   逻辑：自定义(地区)→自定义(语言)→内置(地区)→内置(语言)→en_US
 *-----------------------------------------------------------*/
/*
// 这里选一个没有内置也没有注册的 locale，比如 it_IT
$it = new I18nDateFormatter('it_IT', 'Europe/Rome');
echo $it->getLocale(), "\n";                      // 解析/补全后仍是 it_IT
echo $it->format($dtObj, 'medium', 'short'), "\n";// 若 intl 存在则按 ICU；否则会回退到 en_US 降级表 */

/*------------------------------------------------------------
 | 10) 与不同输入混用，批量对比（多语言）
 *-----------------------------------------------------------*/
/*
$locales = ['zh', 'en_US', 'fr_FR', 'de_DE', 'ja_JP', 'ko_KR', 'ru_RU', 'ar_SA'];
foreach ($locales as $loc) {
    $fmt = new I18nDateFormatter($loc, 'UTC');
    printf("%-6s => %s\n", $fmt->getLocale(), $fmt->format($dtObj, 'long', 'short'));
} */
