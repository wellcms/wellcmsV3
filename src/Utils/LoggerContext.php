<?php

declare(strict_types=1);

namespace Framework\Utils;

class LoggerContext
{
    /** @var array FPM / CLI 非协程环境使用 */
    private static $context = [];

    public static function set(string $key, $value): void
    {
        if (self::inCoroutine()) {
            $ctx = \Swoole\Coroutine::getContext();
            if (!isset($ctx['logger_context']) || !\is_array($ctx['logger_context'])) {
                $ctx['logger_context'] = [];
            }
            $ctx['logger_context'][$key] = $value;
            return;
        }

        self::$context[$key] = $value;
    }

    public static function setMultiple(array $values): void
    {
        if (self::inCoroutine()) {
            $ctx = \Swoole\Coroutine::getContext();
            if (!isset($ctx['logger_context']) || !\is_array($ctx['logger_context'])) {
                $ctx['logger_context'] = [];
            }
            foreach ($values as $k => $v) {
                $ctx['logger_context'][$k] = $v;
            }
            return;
        }

        foreach ($values as $k => $v) {
            self::$context[$k] = $v;
        }
    }

    /**
     * @param mixed $default
     * @return mixed
     */
    public static function get(string $key, $default = null)
    {
        $ctx = self::all();
        return isset($ctx[$key]) ? $ctx[$key] : $default;
    }

    public static function all(): array
    {
        if (self::inCoroutine()) {
            $ctx = \Swoole\Coroutine::getContext();
            return (isset($ctx['logger_context']) && \is_array($ctx['logger_context']))
                ? $ctx['logger_context']
                : [];
        }

        return self::$context;
    }

    public static function clear(): void
    {
        if (self::inCoroutine()) {
            $ctx = \Swoole\Coroutine::getContext();
            $ctx['logger_context'] = [];
            return;
        }

        self::$context = [];
    }

    private static function inCoroutine(): bool
    {
        return \extension_loaded('swoole') && \Swoole\Coroutine::getCid() > 0;
    }
}
