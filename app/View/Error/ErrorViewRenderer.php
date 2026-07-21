<?php

declare(strict_types=1);

/*
 * Copyright (C) www.wellcms.com
 */

namespace App\View\Error;

use App\Core\Compile;

/**
 * 统一错误视图渲染器。
 *
 * 职责：唯一负责 include 错误模板、注入 $view、清理输出缓冲、异常降级。
 * 本类不直接操作 header() 或 echo，只返回 body 字符串，保持纯函数式。
 */
class ErrorViewRenderer
{
    /**
     * 渲染指定错误模板。
     *
     * @param string $templateFile 模板文件的绝对路径。
     * @param ErrorViewModelInterface $view 视图模型。
     * @return string 渲染后的 HTML 字符串；失败时返回安全兜底 HTML。
     */
    public function render(string $templateFile, ErrorViewModelInterface $view): string
    {
        if (!$templateFile || !file_exists($templateFile)) {
            return $this->createFallbackHtml();
        }

        $obLevel = ob_get_level();
        ob_start();
        try {
            // 通过 extract 显式注入 $view，避免隐式变量污染
            extract(['view' => $view], EXTR_SKIP);
            include Compile::include($templateFile);
            $body = ob_get_clean();
            return $body !== false ? $body : $this->createFallbackHtml();
        } catch (\Throwable $renderError) {
            // 确保任何异常路径下都清理缓冲，避免缓冲泄漏
            while (ob_get_level() > $obLevel) {
                ob_end_clean();
            }
            error_log('ErrorViewRenderer render failed: ' . $renderError->getMessage());
            return $this->createFallbackHtml();
        }
    }

    /**
     * 模板不可用时返回的最终兜底 HTML。
     */
    private function createFallbackHtml(): string
    {
        return '<html><head><title>Internal Server Error</title><meta charset="utf-8"></head>'
            . '<body style="font-family:sans-serif;padding:2rem;text-align:center">'
            . '<h1>500 Internal Server Error</h1>'
            . '<p>We are sorry, but something went wrong.</p>'
            . '</body></html>';
    }
}
