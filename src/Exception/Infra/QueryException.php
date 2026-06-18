<?php

declare(strict_types=1);

namespace Framework\Exception\Infra;

/**
 * SQL 查询异常
 * 包含 SQL 语句与绑定参数，便于排错
 */
class QueryException extends DatabaseException
{
    /** @var string */
    protected $sql;
    /** @var array */
    protected $bindings;

    public function __construct(string $message, ?string $sql = null, ?array $bindings = null, int $code = 0, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
        $this->sql = $sql;
        $this->bindings = $bindings;
    }

    public function getSql(): ?string { return $this->sql; }
    public function getBindings(): ?array { return $this->bindings; }

    public function __toString(): string
    {
        $str = parent::__toString();
        if ($this->sql) $str .= "\nSQL: " . $this->sql;
        if ($this->bindings) $str .= "\nBindings: " . json_encode($this->bindings, JSON_UNESCAPED_UNICODE);
        return $str;
    }
}
