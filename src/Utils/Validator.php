<?php

declare(strict_types=1);
/*
 * Copyright (C) www.wellcms.com
 */

namespace Framework\Utils;

use Framework\Exception\ValidationException;
use Framework\Database\Interfaces\DatabaseInterface;

/**
 * 统一验证类 - 支持类型检查、必填字段、长度限制、自定义规则
 *
 * 特点：
 * - 支持链式调用
 * - 支持自定义验证规则
 * - 详细的错误信息
 * - 性能优化（缓存验证规则）
 *
 * @package Framework\Utils
 */
class Validator
{
    /** @var array 验证规则 */
    protected $rules = [];

    /** @var array 自定义错误消息 */
    protected $messages = [];

    /** @var array 验证错误 */
    protected $errors = [];

    /** @var mixed 当前验证值 */
    protected $value;

    /** @var string 当前字段名 */
    protected $field;

    protected /** @var array */
    static $ruleCache = [];

    /** @var DatabaseInterface|null 数据库连接 */
    protected $db;

    protected /** @var int */
    static $gcCallCount = 0;

    /**
     * 静态创建验证器
     * @param array $data 待验证数据
     * @param array $rules 验证规则
     * @param array $messages 自定义错误消息
     * @param DatabaseInterface|null $db 数据库连接
     * @return Validator
     */
    public static function make(array $data, array $rules, array $messages = [], ?DatabaseInterface $db = null): Validator
    {
        $validator = new static();
        if ($db) $validator->setDatabase($db);
        $validator->setRules($rules);
        $validator->setMessages($messages);
        $validator->validate($data);
        return $validator;
    }

    /**
     * 批量验证数据
     * @param array $data 待验证数据
     * @return bool
     * @throws ValidationException
     */
    public function validate(array $data): bool
    {
        $this->errors = [];

        foreach ($this->rules as $field => $ruleString) {
            $this->field = $field;
            $this->value = $data[$field] ?? null;

            $rules = $this->parseRuleString($ruleString);

            foreach ($rules as $rule => $params) {
                if (!$this->validateRule($rule, $params)) {
                    break; // 当前规则验证失败，跳出
                }
            }
        }

        if (!empty($this->errors)) {
            throw new ValidationException($this->getErrorMessage());
        }

        return true;
    }

    /**
     * 验证单个字段
     * @param mixed $value 字段值
     * @param string $ruleString 规则字符串
     * @param string $field 字段名（用于错误信息）
     * @return bool
     * @throws ValidationException
     */
    public function validateField($value, string $ruleString, string $field = 'field'): bool
    {
        $this->value = $value;
        $this->field = $field;
        $this->errors = [];

        $rules = $this->parseRuleString($ruleString);

        foreach ($rules as $rule => $params) {
            if (!$this->validateRule($rule, $params)) {
                break;
            }
        }

        if (!empty($this->errors)) {
            throw new ValidationException($this->getErrorMessage());
        }

        return true;
    }

    /**
     * 解析规则字符串
     * @param string $ruleString
     * @return array
     */
    protected function parseRuleString(string $ruleString): array
    {
        if (isset(self::$ruleCache[$ruleString])) {
            return self::$ruleCache[$ruleString];
        }

        $rules = [];
        $ruleList = explode('|', $ruleString);

        foreach ($ruleList as $ruleItem) {
            if (strpos($ruleItem, ':') !== false) {
                [$rule, $param] = explode(':', $ruleItem, 2);
                $params = explode(',', $param);
            } else {
                $rule = $ruleItem;
                $params = [];
            }

            $rule = trim($rule);
            $rules[$rule] = $params;
        }

        self::$ruleCache[$ruleString] = $rules;
        return $rules;
    }

    /**
     * 验证单个规则
     * @param string $rule 规则名
     * @param array $params 参数
     * @return bool
     */
    protected function validateRule(string $rule, array $params): bool
    {
        // 跳过空值验证（除 required 外）
        if ($this->value === null || $this->value === '' || $this->value === []) {
            if ($rule !== 'required') {
                return true;
            }
        }

        $method = 'validate' . ucfirst($rule);

        if (method_exists($this, $method)) {
            return $this->$method($params);
        }

        // 自定义验证规则
        return $this->validateCustom($rule, $params);
    }

    /**
     * 必填验证
     * @param array $params
     * @return bool
     */
    protected function validateRequired(array $params): bool
    {
        $valid = $this->value !== null && $this->value !== '' && $this->value !== [];
        if (!$valid) {
            $this->addError("{$this->field} is required");
        }
        return $valid;
    }

    /**
     * 字符串验证
     * @param array $params
     * @return bool
     */
    protected function validateString(array $params): bool
    {
        $valid = is_string($this->value);
        if (!$valid) {
            $this->addError("{$this->field} must be a string");
        }
        return $valid;
    }

    /**
     * 整数验证
     * @param array $params
     * @return bool
     */
    protected function validateInt(array $params): bool
    {
        $valid = filter_var($this->value, FILTER_VALIDATE_INT) !== false;
        if (!$valid) {
            $this->addError("{$this->field} must be an integer");
        }
        return $valid;
    }

    /**
     * 整数验证 (别名)
     * @param array $params
     * @return bool
     */
    protected function validateInteger(array $params): bool
    {
        return $this->validateInt($params);
    }

    /**
     * 数字验证
     * @param array $params
     * @return bool
     */
    protected function validateNumeric(array $params): bool
    {
        $valid = is_numeric($this->value);
        if (!$valid) {
            $this->addError("{$this->field} must be numeric");
        }
        return $valid;
    }

    /**
     * 邮箱验证
     * @param array $params
     * @return bool
     */
    protected function validateEmail(array $params): bool
    {
        $valid = filter_var($this->value, FILTER_VALIDATE_EMAIL) !== false;
        if (!$valid) {
            $this->addError("{$this->field} must be a valid email address");
        }
        return $valid;
    }

    /**
     * URL验证
     * @param array $params
     * @return bool
     */
    protected function validateUrl(array $params): bool
    {
        $valid = filter_var($this->value, FILTER_VALIDATE_URL) !== false;
        if (!$valid) {
            $this->addError("{$this->field} must be a valid URL");
        }
        return $valid;
    }

    /**
     * 最小长度验证
     * @param array $params
     * @return bool
     */
    protected function validateMin(array $params): bool
    {
        $min = (int)($params[0] ?? 0);
        $length = is_string($this->value) ? strlen($this->value) : count((array)$this->value);
        $valid = $length >= $min;
        if (!$valid) {
            $this->addError("{$this->field} must be at least {$min} characters");
        }
        return $valid;
    }

    /**
     * 最大长度验证
     * @param array $params
     * @return bool
     */
    protected function validateMax(array $params): bool
    {
        $max = (int)($params[0] ?? PHP_INT_MAX);
        $length = is_string($this->value) ? strlen($this->value) : count((array)$this->value);
        $valid = $length <= $max;
        if (!$valid) {
            $this->addError("{$this->field} must not exceed {$max} characters");
        }
        return $valid;
    }

    /**
     * 范围验证（数值）
     * @param array $params
     * @return bool
     */
    protected function validateBetween(array $params): bool
    {
        $min = (int)($params[0] ?? 0);
        $max = (int)($params[1] ?? PHP_INT_MAX);
        $valid = filter_var($this->value, FILTER_VALIDATE_INT) !== false &&
            $this->value >= $min &&
            $this->value <= $max;
        if (!$valid) {
            $this->addError("{$this->field} must be between {$min} and {$max}");
        }
        return $valid;
    }

    /**
     * 数组验证
     * @param array $params
     * @return bool
     */
    protected function validateArray(array $params): bool
    {
        $valid = is_array($this->value);
        if (!$valid) {
            $this->addError("{$this->field} must be an array");
        }
        return $valid;
    }

    /**
     * 布尔值验证
     * @param array $params
     * @return bool
     */
    protected function validateBool(array $params): bool
    {
        $valid = in_array($this->value, [true, false, 0, 1, '0', '1'], true);
        if (!$valid) {
            $this->addError("{$this->field} must be a boolean");
        }
        return $valid;
    }

    /**
     * 正则表达式验证
     * @param array $params
     * @return bool
     */
    protected function validateRegex(array $params): bool
    {
        $pattern = $params[0] ?? '';
        $valid = preg_match($pattern, $this->value) === 1;
        if (!$valid) {
            $this->addError("{$this->field} format is invalid");
        }
        return $valid;
    }

    /**
     * 日期验证
     * @param array $params
     * @return bool
     */
    protected function validateDate(array $params): bool
    {
        $format = $params[0] ?? 'Y-m-d';
        $date = \DateTime::createFromFormat($format, $this->value);
        $valid = $date && $date->format($format) === $this->value;
        if (!$valid) {
            $this->addError("{$this->field} must be a valid date");
        }
        return $valid;
    }

    /**
     * IP验证
     * @param array $params
     * @return bool
     */
    protected function validateIp(array $params): bool
    {
        $valid = filter_var($this->value, FILTER_VALIDATE_IP) !== false;
        if (!$valid) {
            $this->addError("{$this->field} must be a valid IP address");
        }
        return $valid;
    }

    /**
     * UUID验证
     * @param array $params
     * @return bool
     */
    protected function validateUuid(array $params): bool
    {
        $valid = preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $this->value) === 1;
        if (!$valid) {
            $this->addError("{$this->field} must be a valid UUID");
        }
        return $valid;
    }

    /**
     * 文件MIME类型验证
     * @param array $params
     * @return bool
     */
    protected function validateMime(array $params): bool
    {
        if (!$this->value || !is_array($this->value)) {
            return true; // 跳过空值
        }

        if (!isset($this->value['type'])) {
            $this->addError("{$this->field} is not a valid uploaded file");
            return false;
        }

        $mime = $this->value['type'];
        $allowedMimes = $params;

        $valid = in_array($mime, $allowedMimes, true);
        if (!$valid) {
            $this->addError("{$this->field} file type must be: " . implode(', ', $allowedMimes));
        }
        return $valid;
    }

    /**
     * 文件大小验证（字节）
     * @param array $params
     * @return bool
     */
    protected function validateFileSize(array $params): bool
    {
        if (!$this->value || !is_array($this->value)) {
            return true; // 跳过空值
        }

        if (!isset($this->value['size'])) {
            $this->addError("{$this->field} is not a valid uploaded file");
            return false;
        }

        $size = $this->value['size'];
        $maxSize = (int)($params[0] ?? 2 * 1024 * 1024);  // 默认2MB

        $valid = $size <= $maxSize;
        if (!$valid) {
            $mb = round($maxSize / 1024 / 1024, 2);
            $this->addError("{$this->field} file size must not exceed {$mb}MB");
        }
        return $valid;
    }

    /**
     * 唯一性验证
     * @param array $params [$table, $column, $exceptId, $idColumn]
     * @return bool
     */
    protected function validateUnique(array $params): bool
    {
        if (!$this->db) {
            return true; // 未设置数据库连接，跳过校验
        }

        $table = $params[0] ?? '';
        $column = $params[1] ?? $this->field;
        $exceptId = $params[2] ?? null;
        $idColumn = $params[3] ?? 'id';

        if (!$table) {
            return false;
        }

        $where = [$column => $this->value];
        if ($exceptId !== null) {
            $where["{$idColumn}!="] = $exceptId;
        }

        $exists = $this->db->count($table, $where) > 0;

        if ($exists) {
            $this->addError("The {$this->field} has already been taken");
            return false;
        }

        return true;
    }

    /**
     * 已存在验证（与唯一相反）
     * @param array $params [$table, $column]
     * @return bool
     */
    protected function validateExists(array $params): bool
    {
        if (!$this->db) {
            return true;
        }

        $table = $params[0] ?? '';
        $column = $params[1] ?? $this->field;

        if (!$table) {
            return false;
        }

        $exists = $this->db->count($table, [$column => $this->value]) > 0;

        if (!$exists) {
            $this->addError("The selected {$this->field} is invalid");
            return false;
        }

        return true;
    }

    /**
     * 自定义验证规则
     * @param string $rule
     * @param array $params
     * @return bool
     */
    protected function validateCustom(string $rule, array $params): bool
    {
        // 可以通过扩展添加自定义验证
        // 例如: validatePhone, validateChineseIdCard 等
        $this->addError("Unknown validation rule: {$rule}");
        return false;
    }

    /**
     * 添加错误
     * @param string $message
     */
    protected function addError(string $message): void{
        $this->errors[$this->field][] = $message;
    }

    /**
     * 获取所有错误
     * @return array
     */
    public function getErrors(): array
    {
        return $this->errors;
    }

    /**
     * 获取错误消息字符串
     * @return string
     */
    public function getErrorMessage(): string
    {
        if (empty($this->errors)) {
            return '';
        }

        $messages = [];
        foreach ($this->errors as $field => $errors) {
            foreach ($errors as $error) {
                $messages[] = $error;
            }
        }

        return implode(', ', $messages);
    }

    /**
     * 设置规则
     * @param array $rules
     * @return Validator
     */
    public function setRules(array $rules): Validator
    {
        $this->rules = $rules;
        return $this;
    }

    /**
     * 设置自定义错误消息
     * @param array $messages
     * @return Validator
     */
    public function setMessages(array $messages): Validator
    {
        $this->messages = $messages;
        return $this;
    }

    /**
     * 设置数据库连接
     * @param DatabaseInterface $db
     * @return Validator
     */
    public function setDatabase(DatabaseInterface $db): Validator
    {
        $this->db = $db;
        return $this;
    }

    /**
     * 获取GC调用统计
     * @return int
     */
    public static function getGcCallCount(): int
    {
        return self::$gcCallCount;
    }

    /**
     * 重置GC调用计数
     */
    public static function resetGcCallCount(): void
    {
        self::$gcCallCount = 0;
    }
}
