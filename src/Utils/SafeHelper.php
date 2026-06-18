<?php

declare(strict_types=1);
/*
 * Copyright (C) www.wellcms.com
*/

namespace Framework\Utils;

/**
 * 安全过滤
 * ENT_COMPAT 仅转义双引号
 * ENT_QUOTES 转义双引号和单引号
 * ENT_NOQUOTES 不转义任何引号
 *
 * 纯文本（标题正文等） → htmlspecialchars($str, ENT_NOQUOTES | ENT_HTML5, 'UTF-8');
 * textarea → htmlspecialchars($str, ENT_NOQUOTES | ENT_HTML5, 'UTF-8')
 * 保留单引号，转义双引号，value 值双引号包裹
 * input → htmlspecialchars($str, ENT_COMPAT | ENT_HTML5, 'UTF-8')
 * API → htmlspecialchars($str, ENT_NOQUOTES | ENT_HTML5, 'UTF-8');
 * HTML 标签属性值 → htmlspecialchars($str, ENT_QUOTES | ENT_HTML5, 'UTF-8');
 * 富文本 → 先用 HTMLPurifier/自家白名单过滤，再原样输出。
 */
class SafeHelper
{
    /**
     * 安全过滤字符串，仅仅保留 [a-zA-Z0-9_] ，并截取指定长度
     * @param string $s 字符串
     * @param int $len
     * @return string
     */
    public static function safeWord(string $s, int $len = 32)
    {
        $s = preg_replace('#\W+#', '', $s);
        $s = substr($s, 0, $len);
        return $s;
    }

    /**
     * 过滤敏感词
     * @param array $arr = [a, b, c, d, e] 敏感词数组
     * @param string $keyword 需要过滤的关键词
     * @return bool true 含有敏感词 / false 没有敏感词(正常词)
     */
    public static function filterKeyword(array $arr = [], string $keyword = ''): bool{
        if (empty($arr) || !$keyword) return false;
        $arr = array_unique($arr);
        foreach ($arr as $_keyword) {
            if (false !== strpos(strtolower($keyword), strtolower($_keyword))) return true;
        }
        return false;
    }

    /**
     * txt 转换到 html
     * @param string $s 字符串
     * @return string
     */
    public static function txtToHtml(string $s)
    {
        $s = htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
        $s = strtr($s, [
            "\r\n" => "\n",                              // Win 回车换行 → \n
            "\n"   => '<br>',                            // \n → <br>
            "\t"   => ' &nbsp; &nbsp; &nbsp; &nbsp;',    // Tab → 4 空格
            ' '    => '&nbsp;',                          // 单空格 → &nbsp;
        ]);
        return $s;
    }

    // html换行转换为\r\n
    public static function brToChars(string $data)
    {
        //$data = htmlspecialchars_decode($data);
        return strtr($data, ['<br>' => "\r\n"]);
    }

    // 过滤全部html标签
    public static function filterAllHtml(string $text, bool $specialchars = false)
    {
        $text = trim($text);
        $text = stripslashes($text);
        $text = strip_tags($text);
        $text = str_replace(array('&nbsp;', '/', "\t", "\r\n", "\r", "\n", '  ', '   ', '    ', '	'), '', $text);
        $specialchars && $text = htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8'); // 入库前保留干净，入库时转码 输出时无需htmlspecialchars_decode()
        return $text;
    }

    /**
     * 过滤html标签，并保留允许使用html标签
     * @param string $text 需要过滤的字符串
     * @param string $html_tag 允许使用html标签 自行设定需要保留的标签，如<b><hr><div><b>
     * @return string
     */
    public static function filterHtml(string $text, string $html_tag = '')
    {
        if (!$html_tag) return '';

        $html_tag = htmlspecialchars_decode($html_tag);

        $text = trim($text);
        $text = stripslashes($text);
        $text = strip_tags($text, "$html_tag"); // 需要保留的字符
        $text = str_replace(array("\r\n", "\r", "\n", '  ', '   ', '    ', '	'), '', $text);
        //$text = preg_replace('#\s+#', '', $text);//空白区域 会过滤图片等
        //$text = preg_replace("#<(.*?)>#is", "", $text);
        // 过滤所有的style (处理有引号和无引号情况)
        $text = preg_replace('/\bstyle\s*=\s*(\'[^\']*\'|"[^"]*"|[^\s>]+)/i', '', $text);
        // 过滤所有的class
        $text = preg_replace('/\bclass\s*=\s*(\'[^\']*\'|"[^"]*"|[^\s>]+)/i', '', $text);
        // 获取img= 过滤标签中其他属性 (修正引号处理和正则性能)
        $text = preg_replace('/(<img.*?)\s+(?:class|data-src|data-type|data-ratio|data-s|data-fail|crossorigin|data-w|_width|_height|style|width|height)\s*=\s*(?:\'[^\']*\'|"[^"]*"|[^\s>]+)/i', '$1', $text);

        return $text;
    }

    /**
     * 从任意语言的字符串创建 tag/slug。
     * 规则：
     *  - 仅保留字母、数字（所有语种）、可组合音标、空白、下划线和连字符
     *  - 把空白/下划线/连字符合并为单个分隔符（默认 -）
     *  - 去掉两端分隔符、可选小写化、可选 ASCII 化、可选长度限制（按“用户可见字符”截断）
     *
     * 依赖（可选但推荐）：
     *  - ext-intl：Normalizer、Transliterator、grapheme_*（更好的 Unicode 处理）
     *  - ext-mbstring：mb_*（更好的大小写/长度）
     *
     * 选项：
     *  - sep: 分隔符（默认 '-'）
     *  - lowercase: 是否小写化（默认 true）
     *  - ascii: 是否转为 ASCII（默认 false；适合 URL）
     *  - maxlen: 最长长度（默认 80，null 表示不限制）
     *
     * echo SafeHelper::makeTag('中文 标签★！'); // 输出：中文-标签
     * echo SafeHelper::makeTag('Café déjà-vu!!'); // 输出：café-déjà-vu
     * echo SafeHelper::makeTag('مرحبا، بالعالم!!!'); // 输出：مرحبا-بالعالم
     * echo SafeHelper::makeTag('Привет, мир — тест'); // 输出：привет-мир-тест
     * echo SafeHelper::makeTag('Emoji 👀🔥 should go'); // 输出：emoji-should-go
     * echo SafeHelper::makeTag('中文 标签★！', ['ascii' => true]); // 输出：zhong-wen-biao-qian
     */
    public static function makeTag(string $input, array $opts = []): string
    {
        $sep      = isset($opts['sep']) ? $opts['sep'] : '-';
        $lower    = isset($opts['lowercase']) ? (bool)$opts['lowercase'] : true;
        $ascii    = isset($opts['ascii']) ? (bool)$opts['ascii'] : false;
        $maxlen   = array_key_exists('maxlen', $opts) ? $opts['maxlen'] : 80;
        $title    = isset($opts['titlecase']) ? (bool)$opts['titlecase'] : false;
        $locale   = isset($opts['locale']) ? (string)$opts['locale'] : null;

        $s = trim($input);

        // Unicode 规范化
        if (class_exists('Normalizer')) {
            $s = \Normalizer::normalize($s, \Normalizer::FORM_C);
        }

        // 可选：转 ASCII
        if ($ascii) {
            if (class_exists('Transliterator')) {
                $tr = \Transliterator::create('Any-Latin; Latin-ASCII; NFD; [:Nonspacing Mark:] Remove; NFC');
                if ($tr) $s = $tr->transliterate($s);
            } elseif (function_exists('iconv')) {
                $tmp = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
                if ($tmp !== false) $s = $tmp;
            }
        }

        // 过滤特殊字符
        $s = preg_replace('/[^\p{L}\p{N}\p{M}\s_-]+/u', '', $s);

        // 统一分隔符
        $s = preg_replace('/[\s_-]+/u', $sep, $s);

        // 去首尾分隔符
        $s = trim($s, $sep);

        // 小写化（Title Case 前置：先统一小写，后做首字母大写）
        if ($lower) {
            $s = function_exists('mb_strtolower') ? mb_strtolower($s, 'UTF-8') : strtolower($s);
        }

        // 可选：多语言 Title Case（对以 $sep 分隔的“词”进行首字母大写）
        if ($title && $s !== '') {
            // 可选临时设置 Locale（影响 ICU 的大小写转换，如土耳其语 I/ı）
            $prevLocale = null;
            if ($locale && class_exists('Locale')) {
                $prevLocale = \Locale::getDefault();
                \Locale::setDefault($locale);
            }
            // 将分隔符替换为空格，做 Title Case，再恢复分隔符
            $tmp = ($sep !== ' ') ? str_replace($sep, ' ', $s) : $s;

            if (class_exists('Transliterator')) {
                // ICU 的 Any-Title 更智能，识别多语边界
                $trTitle = \Transliterator::create('Any-Title');
                if ($trTitle) {
                    $tmp = $trTitle->transliterate($tmp);
                } else {
                    // 回退：mb_convert_case
                    if (function_exists('mb_convert_case')) {
                        $tmp = mb_convert_case($tmp, MB_CASE_TITLE, 'UTF-8');
                    } else {
                        // 最后兜底（ASCII）
                        $tmp = ucwords($tmp);
                    }
                }
            } else {
                if (function_exists('mb_convert_case')) {
                    $tmp = mb_convert_case($tmp, MB_CASE_TITLE, 'UTF-8');
                } else {
                    $tmp = ucwords($tmp);
                }
            }

            // 恢复分隔符并清理多余空格
            $tmp = preg_replace('/\s+/u', $sep, trim($tmp));
            $s = $tmp;

            // 恢复原 Locale
            if ($locale && class_exists('Locale') && $prevLocale) {
                \Locale::setDefault($prevLocale);
            }
        }

        // 可选长度限制（按“可见字符”）
        if ($maxlen !== null) {
            if (function_exists('grapheme_strlen') && grapheme_strlen($s) > $maxlen) {
                $s = grapheme_substr($s, 0, $maxlen);
            } elseif (function_exists('mb_strlen') && mb_strlen($s, 'UTF-8') > $maxlen) {
                $s = mb_substr($s, 0, $maxlen, 'UTF-8');
            } elseif (strlen($s) > $maxlen) {
                $s = substr($s, 0, $maxlen);
            }
            $s = trim($s, $sep);
        }

        return $s;
    }

    /**
     * 生成“hashtag 风格”的 tag：
     *  - 仅保留：\p{L}\p{N}\p{M}（所有语言的字母/数字/组合音标）
     *  - 空格/下划线/连字符/标点等一律剔除（不保留分隔符）
     *  - style: 'lower'（默认）、'camel'、'pascal'
     *  - ascii: 是否转 ASCII（适合只允英文数字的平台）
     *  - prefix: 可加 '#' 作为展示
     *  - maxlen: 最长可见长度（按 Unicode 截断）
     *
     * 建议安装 ext-intl 与 ext-mbstring（更好的 Unicode 处理）
     * echo SafeHelper::makeHashtag('机器 学习_入门-2025!');                 // 机器学习入门2025
     * echo SafeHelper::makeHashtag('Café déjà-vu!', ['style' => 'camel']); // caféDéjàVu
     * echo SafeHelper::makeHashtag('مرحبا بالعالم', ['style' => 'pascal']); // مرحبابالعالم
     * echo SafeHelper::makeHashtag('中文 标签到 ASCII', ['ascii' => true, 'prefix' => '#']); // #zhongwenbiaoqiandaoASCII
     */
    public static function makeHashtag(string $input, array $opts = []): string
    {
        $style  = isset($opts['style']) ? $opts['style'] : 'lower';
        $ascii  = isset($opts['ascii']) ? (bool)$opts['ascii'] : false;
        $prefix = isset($opts['prefix']) ? $opts['prefix'] : '';
        $maxlen = array_key_exists('maxlen', $opts) ? $opts['maxlen'] : 80;

        $s = trim($input);

        if (class_exists('Normalizer')) {
            $s = \Normalizer::normalize($s, \Normalizer::FORM_C);
        }

        if ($ascii) {
            if (class_exists('Transliterator')) {
                $tr = \Transliterator::create('Any-Latin; Latin-ASCII; NFD; [:Nonspacing Mark:] Remove; NFC');
                if ($tr) $s = $tr->transliterate($s);
            } elseif (function_exists('iconv')) {
                $tmp = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
                if ($tmp !== false) $s = $tmp;
            }
        }

        // 用“非字母/数字/组合标记”切词
        $parts = preg_split('/[^\p{L}\p{N}\p{M}]+/u', $s, -1, PREG_SPLIT_NO_EMPTY);
        if (empty($parts)) return '';

        // 清理每段
        foreach ($parts as &$p) {
            $p = preg_replace('/[^\p{L}\p{N}\p{M}]+/u', '', $p);
        }
        unset($p);

        // 工具函数：小写 & 首字母大写
        $lower = function ($t) {
            return function_exists('mb_strtolower') ? mb_strtolower($t, 'UTF-8') : strtolower($t);
        };

        $ucfirst = function ($t) {
            if ($t === '') return $t;
            if (function_exists('mb_substr') && function_exists('mb_strtoupper') && function_exists('mb_strlen')) {
                $f = mb_substr($t, 0, 1, 'UTF-8');
                $r = mb_substr($t, 1, mb_strlen($t, 'UTF-8'), 'UTF-8');
                return mb_strtoupper($f, 'UTF-8') . $r;
            }
            return ucfirst($t);
        };

        switch ($style) {
            case 'camel':
                $parts = array_values($parts);
                $res = $lower($parts[0]);
                for ($i = 1; $i < count($parts); $i++) {
                    $res .= $ucfirst($lower($parts[$i]));
                }
                break;
            case 'pascal':
                $res = '';
                foreach ($parts as $p) $res .= $ucfirst($lower($p));
                break;
            default: // lower
                $res = implode('', array_map($lower, $parts));
        }

        // 限长（按“可见字符”）
        if ($maxlen !== null) {
            if (function_exists('grapheme_strlen') && grapheme_strlen($res) > $maxlen) {
                $res = grapheme_substr($res, 0, $maxlen);
            } elseif (function_exists('mb_strlen') && mb_strlen($res, 'UTF-8') > $maxlen) {
                $res = mb_substr($res, 0, $maxlen, 'UTF-8');
            } elseif (strlen($res) > $maxlen) {
                $res = substr($res, 0, $maxlen);
            }
        }

        return $prefix . $res;
    }

    // 过滤emoji
    public static function filterStr(string $str)
    {
        return preg_replace_callback('/./u', function ($match) {
            return mb_strlen($match[0], 'UTF-8') >= 4 ? '' : $match[0];
        }, $str);
    }

    //$email = 'example@domain.com';
    //echo maskEmail($email); // 输出: ex****@domain.com
    public static function maskEmail(string $email)
    {
        return preg_replace('/^(.{2})(.*)(@.*)$/', '$1****$3', $email);
    }

    // 判断是否为邮箱
    /* public function isValidEmail($email, &$error = '')
    {
        $len = mb_strlen($email, 'UTF-8');
        if ($len > 32) {
            $error = $this->language->get('email_too_long', ['length' => $len]);
            return false;
        }

        if (!preg_match('#^[a-zA-Z0-9_]+(\.[a-zA-Z0-9_]+)*@[a-zA-Z0-9-]+(\.[a-zA-Z]{2,})+$#', $email)) {
            $error = $this->language->get('email_format_incorrect');
            return false;
        }

        return true;
    } */

    /* function isValidUsername($username, &$error = '')
    {
        $len = mb_strlen($username, 'UTF-8');
        if ($len < 2) {
            $error = $this->language->get('username_is_too_short');
            return false;
        }

        if ($len > 16) {
            $error = $this->language->get('username_too_long', array('length' => $len));
            return false;
        }

        if (!preg_match('#^[\x{4e00}-\x{9fa5}a-zA-Z0-9._-]+$#u', $username)) {
            // 中文、英文、数字、-、_
            $error = $this->language->get('incorrect_username_format');
            return false;
        }

        return true;
    } */

    /**
     * @return bool
     */
    public static function isValidPassword(string $password)
    {
        // 至少一个数字
        $hasDigit = preg_match('/\d/', $password);
        // 至少一个字母或特殊字符
        $hasLower = preg_match('/[a-zA-Z!@#$%^&*(),.?":{}|<>]/', $password);
        return $hasDigit && $hasLower;
    }

    public static function randomSixDigit()
    {
        return random_int(100000, 999999);
    }

    // 随机长度的字符串
    public static function randomStr(int $length = 16, bool $strtoupper = false)
    {
        $characters = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ';
        false === $strtoupper && $characters .= 'abcdefghjklmnpqrstuvwxyz';

        $charactersLength = mb_strlen($characters, 'UTF-8');
        $randomString = '';

        for ($i = 0; $i < $length; $i++) {
            $idx = random_int(0, $charactersLength - 1);
            $randomString .= mb_substr($characters, $idx, 1, 'UTF-8');
        }

        return $randomString;
    }

    // 唯一身份ID
    public static function uniqid()
    {
        $str = self::randomStr(26);
        return md5(\uniqid() . microtime(true) . $str);
    }

    // 判断一个字符串是否在另外一个字符串里面，分隔符 ,
    public static function inString(string $s, string $str)
    {
        if (!$s || !$str) return false;
        $s = ',' . $s . ',';
        $str = ',' . $str . ',';
        return false !== strpos($str, $s);
    }

    /**
     * 生成高熵任务ID（防碰撞）
     *
     * 使用多熵源组合，确保即使在极高并发下也几乎不可能碰撞：
     * - 微秒级时间戳（时间维度唯一性）
     * - random_bytes(8) 加密安全随机数（64位熵）
     * - uniqid 进程级唯一标识
     * - 可选的上下文标识（如activeKey前缀）
     *
     * 碰撞概率计算：
     * - random_bytes(8) = 64位 = 2^64 种可能
     * - 在每秒 100万次 调用下，碰撞概率约 10^-15（可忽略）
     *
     * @param string $context 上下文标识（如activeKey）
     * @return string 32位十六进制字符串
     */
    public static function generateId(string $context = ''): string
    {
        // 时间分量（微秒精度）
        $timeComponent = microtime(true);

        // 高熵随机分量（64位加密安全随机数）
        $randomComponent = random_bytes(8);

        // 进程级唯一标识
        $uniqueComponent = uniqid('', true);

        // 组合并哈希
        // 格式: bin2hex(random_bytes) + time_hash_fragment
        $baseHash = hash('sha256', $timeComponent . $uniqueComponent . $context . $randomComponent);

        // 取前16字节（32个十六进制字符），足够唯一
        return substr($baseHash, 0, 32);
    }

    /**
     * 过滤 UGC 外链
     * @param string $html 原始 HTML
     * @param array  $whitelist 白名单域名（不区分大小写）
     * @return string 过滤后的 HTML
     * $body_html = '<p>看看 <a href="https://example.com">Example</a> 和 <a href="/local">本地链接</a></p>';
     * 渲染前过滤，白名单中允许保留原样
     * echo filter_ugc_links($body_html, ['myforum.com', 'trusted.com']);
     */
    public static function filterUgcLinks(string $html, array $whitelist = []): string
    {
        // 构造域名正则白名单
        $safeDomains = array_map('strtolower', $whitelist);

        return preg_replace_callback(
            '#<a\s+[^>]*href=["\']?([^"\' >]+)["\']?[^>]*>#i',
            function ($matches) use ($safeDomains) {
                $url = $matches[1];
                $host = parse_url($url, PHP_URL_HOST);
                $host = strtolower($host ?? '');

                // XSS 防护：拦截危险协议 (javascript, data, vbscript 等)
                if (preg_match('/^(javascript|data|vbscript|file|about|chrome):/i', trim($url))) {
                    return str_replace($matches[1], '#', $matches[0]);
                }

                // 是否在白名单
                $isSafe = $host && in_array($host, $safeDomains, true);

                // 如果不是 HTTP 链接 (通常是内部相对路径)，则认为是安全的
                if (!preg_match('#^https?://#i', $url)) {
                    return $matches[0];
                }

                // 如果白名单内，不加 rel
                if ($isSafe) {
                    return $matches[0];
                }

                // 给外链加 rel / target
                $tag = rtrim($matches[0], '>');
                if (!preg_match('/rel=/i', $tag)) {
                    $tag .= ' rel="ugc nofollow noopener noreferrer"';
                }
                if (!preg_match('/target=/i', $tag)) {
                    $tag .= ' target="_blank"';
                }
                return $tag . '>';
            },
            $html
        );
    }
}
