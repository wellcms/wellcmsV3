<?php

declare(strict_types=1);
/*
 * Copyright (C) www.wellcms.com
*/

namespace App\Middleware;

/**
 * XSS 输入过滤中间件（PSR-15）
 * - 递归清洗 Query/ParsedBody/JSON
 * - 默认对字符串进行 htmlspecialchars
 * - 指定字段（白名单）允许 HTML，并做轻量净化（移除 <script>/on/危险协议等）
 * - 清洗结果写入 request attributes：xss.sanitized.query/body/json
 * - 可选：replace_original=true 时直接替换原始 query/body/json
 */
class XssFilterMiddleware implements \Framework\Http\Interfaces\MiddlewareInterface
{
    /** @var array<string,mixed> */
    private $options;

    /**
     * @param array<string,mixed> $options
     */
    public function __construct(array $options = [])
    {
        $this->options = array_merge([
            'allow_html_fields' => [],     // 允许 HTML 的字段（支持点语法）
            'replace_original'  => false,  // 是否替换 request 原始数据
            'max_depth'         => 32,     // 避免极深嵌套
        ], $options);
    }

    public function process(\Framework\Http\Interfaces\ServerRequestInterface $request, \Framework\Http\Interfaces\RequestHandlerInterface $handler): \Framework\Http\Interfaces\ResponseInterface
    {
        // hook app_Middleware_XssFilterMiddleware_process_start.php

        // 检查路由属性中是否显式标记跳过 XSS 过滤
        if ($request->getAttribute('xss_skip') === true) {
            return $handler->handle($request);
        }

        // Query
        $query = $request->getQueryParams() ?: [];
        $queryClean = $this->sanitize($query);

        // Parsed Body（表单）
        $parsedBody = $request->getParsedBody();
        $parsedBodyArray = is_array($parsedBody) ? $parsedBody : (is_object($parsedBody) ? (array)$parsedBody : []);
        $bodyClean = $this->sanitize($parsedBodyArray);

        // JSON
        $jsonClean = null;
        $contentType = $request->getHeaderLine('Content-Type');
        if ($this->isJson($contentType)) {
            $raw = (string)$request->getBody();
            if ($raw !== '') {
                $json = json_decode($raw, true);
                if (is_array($json)) {
                    $jsonClean = $this->sanitize($json);
                }
            }
            $request->getBody()->rewind();
        }

        // hook app_Middleware_XssFilterMiddleware_process_before.php

        // 附加清洗后的副本
        $request = $request
            ->withAttribute('xss.sanitized.query', $queryClean)
            ->withAttribute('xss.sanitized.body', $bodyClean)
            ->withAttribute('xss.sanitized.json', $jsonClean);

        // hook app_Middleware_XssFilterMiddleware_process_after.php

        // 可选：替换原始 request 数据
        if (!empty($this->options['replace_original'])) {
            $request = $request->withQueryParams($queryClean)
                ->withParsedBody($bodyClean);
            if ($jsonClean !== null) {
                // 使用 StreamFactory 创建流
                $stream = \Framework\Http\Psr7\Factories\StreamFactory::getInstance()->createStream(json_encode($jsonClean, JSON_UNESCAPED_UNICODE));
                $request = $request->withBody($stream);
            }
        }

        // hook app_Middleware_XssFilterMiddleware_process_end.php

        return $handler->handle($request);
    }

    /**
     * 递归清洗
     * @param mixed              $value
     * @param array<int,string>  $path
     * @param int                $depth
     * @return mixed
     */
    private function sanitize($value, array $path = [], int $depth = 0)
    {
        if ($depth > (int)$this->options['max_depth']) {
            return $value;
        }

        // 跳过上传文件
        if ($value instanceof \Framework\Http\Interfaces\UploadedFileInterface) {
            return $value;
        }

        if (is_array($value)) {
            $out = [];
            foreach ($value as $k => $v) {
                $seg = is_int($k) ? (string)$k : (string)$k;
                $out[$k] = $this->sanitize($v, array_merge($path, [$seg]), $depth + 1);
            }
            return $out;
        }

        if (is_string($value)) {
            $fieldPath = implode('.', $path);
            if ($this->isHtmlAllowedField($fieldPath)) {
                return $this->purifyHtml($value);  // 白名单字段：净化
            }
            return $this->htmlEncode($value);      // 其它字段：编码
        }

        if (is_int($value) || is_float($value) || is_bool($value) || $value === null) {
            return $value;
        }

        if (is_object($value) && method_exists($value, '__toString')) {
            return $this->htmlEncode((string)$value);
        }

        return $value;
    }

    private function isHtmlAllowedField(string $fieldPath): bool
    {
        if ($fieldPath === '') return false;
        $whitelist = (array)$this->options['allow_html_fields'];
        foreach ($whitelist as $allowed) {
            $allowed = (string)$allowed;
            if ($allowed === $fieldPath) return true;
            // 尾段匹配（如允许 'content'，匹配 'article.content'）
            if (strpos($allowed, '.') === false && preg_match('#(^|\.)(?:' . preg_quote($allowed, '#') . ')$#', $fieldPath)) {
                return true;
            }
        }
        return false;
    }

    private function htmlEncode(string $s): string
    {
        $s = $this->stripControlChars($s);
        return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    // -- 基于白名单的主动重组净化 --

    private function purifyHtml(string $html): string
    {
        $html = $this->stripControlChars($html);

        // 1. 结构初筛：由于 strip_tags 本身支持白名单，先初步移除危险标签（如 <script>）
        // 与 rte.js sanitizeHtml() 的 allowedTags 保持一致
        $allowedTags = '<img><p><br><b><strong><i><em><u><ul><ol><li><a><span><div><blockquote><code><pre><h1><h2><h3><h4><h5><h6>'
            . '<hr><table><thead><tbody><tr><th><td>'
            . '<section><article><aside><nav><header><footer><figure><figcaption>';
        $html = strip_tags($html, $allowedTags);

        // 2. 标签属性级重组：使用正则捕获每一个合法标签并进行深度属性审计
        return preg_replace_callback('/<([a-z1-6]+)\s*([^>]*)>/i', function ($matches) {
            $tagName = strtolower($matches[1]);
            $attrStr = $matches[2];

            // 解析属性到数组
            $attrs = $this->parseAttributes($attrStr);
            $cleanAttrs = [];

            // 获取该标签允许的属性白名单
            $allowedList = $this->getAllowedAttributes($tagName);

            foreach ($attrs as $name => $val) {
                if (!in_array($name, $allowedList)) continue;

                // 针对特定属性执行深度脱敏
                $val = $this->sanitizeAttributeValue($tagName, $name, $val);
                if ($val !== null) {
                    $cleanAttrs[] = sprintf('%s="%s"', $name, htmlspecialchars((string)$val, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
                }
            }

            return '<' . $tagName . (empty($cleanAttrs) ? '' : ' ' . implode(' ', $cleanAttrs)) . '>';
        }, $html);
    }

    /**
     * 解析 HTML 属性字符串为关联数组 (支持单双引号及无引号)
     */
    private function parseAttributes(string $attrStr): array
    {
        $attrs = [];
        $pattern = '/([a-z0-9_-]+)\s*=\s*(?:["\']([^"\']*)["\']|([^\s>]+))/i';
        if (preg_match_all($pattern, $attrStr, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                $name = strtolower($m[1]);
                $value = $m[2] !== '' ? $m[2] : ($m[3] ?? '');
                $attrs[$name] = $value;
            }
        }
        return $attrs;
    }

    /**
     * 获取标签对应的属性白名单
     */
    private function getAllowedAttributes(string $tagName): array
    {
        $common = ['class', 'id', 'style', 'title']; // 通用安全属性
        $map = [
            'a'   => ['href', 'target', 'rel'],
            'img' => ['src', 'alt', 'width', 'height'],
            'ol'  => ['type', 'start'],
            'ul'  => ['type'],
        ];
        return array_merge($common, $map[$tagName] ?? []);
    }

    /**
     * 针对敏感属性（URL、Style）执行深度脱敏逻辑
     */
    private function sanitizeAttributeValue(string $tag, string $name, string $val): ?string
    {
        // 核心加固 A：协议标准化检测 (彻底解决 html 实体编码绕过)
        if (in_array($name, ['href', 'src', 'action'])) {
            if ($this->isDangerousProtocol($val)) {
                return '#';
            }
        }

        // 核心加固 B：行内样式审计
        if ($name === 'style') {
            return $this->auditInlineStyle($val);
        }

        // 核心加固 C：id 安全校验（格式 + DOM Clobbering 预留名屏蔽）
        if ($name === 'id') {
            if (!preg_match('/^[a-zA-Z][a-zA-Z0-9\-_:.]*\z/', $val)) {
                return null;
            }
            $reserved = ['location', 'closed', 'frames', 'length', 'name', 'opener', 'status'];
            if (in_array(strtolower($val), $reserved)) {
                return null;
            }
        }

        return $val;
    }

    /**
     * 判定是否包含危险协议 (支持实体还原 + 规范化检测)
     */
    private function isDangerousProtocol(string $val): bool
    {
        // 1. 还原 HTML 实体 (jav&#x61;script -> javascript)
        $normalized = html_entity_decode($val, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // 2. 移除所有不可见控制字符及空白符 (j a v a -> java)
        $normalized = preg_replace('/[\s\x00-\x1F\x7F-\x9F\xAD]/u', '', $normalized);
        $normalized = strtolower($normalized);

        // 3. 匹配黑名单协议
        $blackPage = ['javascript:', 'data:text/html', 'vbscript:', 'mocha:', 'livescript:'];
        foreach ($blackPage as $p) {
            if (strpos($normalized, $p) === 0) return true;
        }

        return false;
    }

    /**
     * 对行内单项 style 进行关键字审计
     */
    private function auditInlineStyle(string $style): ?string
    {
        $normalized = strtolower($style);
        // 严禁 CSS 表达式及潜在的 URL 脚本注入
        $blackKeywords = ['expression', 'javascript', 'behavior', 'vmlframe', '-moz-binding', '@import'];
        foreach ($blackKeywords as $word) {
            if (strpos($normalized, $word) !== false) return null;
        }

        // 针对 style 中的 url() 进行二次判定
        if (strpos($normalized, 'url') !== false) {
             // 简单处理：如果包含 url() 则进一步检查 url 内的协议
             if ($this->isDangerousProtocol($style)) return null;
        }

        return $style;
    }

    private function stripControlChars(string $s): string
    {
        return preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $s);
    }

    private function isJson(string $contentType): bool
    {
        return (bool)preg_match('#application/(?:[\w.+-]*\+)?json#i', $contentType);
    }
}

/* // 默认模式（replace_original=false）：控制器里优先取净化后的副本，例如：
// 优先使用净化后的副本
$cleanQuery = $request->getAttribute('xss.sanitized.query', []);
$cleanBody  = $request->getAttribute('xss.sanitized.body', []);
$cleanJson  = $request->getAttribute('xss.sanitized.json'); // 可能为 null

// 如果开启 replace_original=true，可以继续用 getParsedBody()/getQueryParams()

// 允许 HTML 的字段：初始化中间件时配置：
new XssFilterMiddleware([
    'allow_html_fields' => ['content', 'article.body', 'description'],
    'replace_original'  => false,
]);
//如果应用已经广泛依赖 $request->getParsedBody() 的原位数据，可以把 replace_original 设为 true，但务必灰度发布，防止个别场景需要原始值（如签名、富文本编辑）。

//注意：输入过滤不能替代输出编码。模板输出时，对不同上下文（HTML、属性、URL、JS 字面量）仍需做针对性转义。 */
