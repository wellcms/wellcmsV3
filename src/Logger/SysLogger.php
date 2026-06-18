<?php

declare(strict_types=1);
/*
 * Copyright (C) www.wellcms.com
*/

namespace Framework\Logger;

use Framework\Logger\LogLevel;

/**
 * 使用 PHP syslog 扩展记录日志
 */
class SysLogger implements \Framework\Logger\LoggerInterface
{
    /** @var string */
    protected $ident;
    /** @var int */
    protected $facility;

    public function __construct(array $loggerConfig)
    {
        $syslog = $loggerConfig['syslog'] ?? [];
        $this->ident    = $syslog['ident'] ?? 'wellcms';
        $this->facility = $syslog['facility'] ?? LOG_USER;
        openlog($this->ident, LOG_ODELAY | LOG_PID, $this->facility);
    }

    public function log(string $level, string $message, array $context = []): void
    {
        $pri = $this->mapLevelToPriority($level);
        syslog($pri, $this->interpolate($message, $context));
    }

    protected function mapLevelToPriority(string $level): int
    {
        switch ($level) {
            case LogLevel::EMERGENCY:
            case LogLevel::ALERT:
            case LogLevel::CRITICAL:
            case LogLevel::ERROR:
                return LOG_ERR;
            case LogLevel::WARNING:
                return LOG_WARNING;
            case LogLevel::NOTICE:
                return LOG_NOTICE;
            case LogLevel::INFO:
            case LogLevel::DEBUG:
            default:
                return LOG_INFO;
        }
    }

    protected function interpolate(string $message, array $context): string
    {
        foreach ($context as $k => $v) {
            $message = str_replace("{{$k}}", (string)$v, $message);
        }
        return $message;
    }

    public function emergency(string $message, array $context = []): void
    {
        $this->log(LogLevel::EMERGENCY, $message, $context);
    }
    public function alert(string $message, array $context = []): void
    {
        $this->log(LogLevel::ALERT, $message, $context);
    }
    public function critical(string $message, array $context = []): void
    {
        $this->log(LogLevel::CRITICAL,  $message, $context);
    }
    public function error(string $message, array $context = []): void
    {
        $this->log(LogLevel::ERROR, $message, $context);
    }
    public function warning(string $message, array $context = []): void
    {
        $this->log(LogLevel::WARNING,   $message, $context);
    }
    public function notice(string $message, array $context = []): void
    {
        $this->log(LogLevel::NOTICE,    $message, $context);
    }
    public function info(string $message, array $context = []): void
    {
        $this->log(LogLevel::INFO,  $message, $context);
    }
    public function debug(string $message, array $context = []): void
    {
        $this->log(LogLevel::DEBUG, $message, $context);
    }
}
