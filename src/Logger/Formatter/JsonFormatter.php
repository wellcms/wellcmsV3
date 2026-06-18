<?php

declare(strict_types=1);
/*
 * Copyright (C) www.wellcms.com
*/

namespace Framework\Logger\Formatter;

/**
 * 将日志记录格式化为 JSON 字符串
 */
class JsonFormatter
{
    /**
     * 格式化日志行
     * @param string $level
     * @param string $message
     * @param array  $context
     * @return string  一行 JSON 字符串
     */
    public function format(string $level, string $message, array $context = []): string
    {
        $timestamp = (new \DateTime())->format('c');
        $entry = [
            'timestamp' => $timestamp,
            'level'     => $level,
            'message'   => $this->interpolate($message, $context),
            'context'   => $context,
        ];
        return json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\r\n\r\n";
    }

    /**
     * 将上下文 placeholder 替换到消息中
     */
    protected function interpolate(string $message, array $context): string
    {
        $replace = [];
        foreach ($context as $key => $val) {
            if (is_scalar($val) || (is_object($val) && method_exists($val, '__toString'))) {
                $replace['{' . $key . '}'] = (string)$val;
            } else {
                $replace['{' . $key . '}'] = '[' . gettype($val) . ']';
            }
        }
        return strtr($message, $replace);
    }
}