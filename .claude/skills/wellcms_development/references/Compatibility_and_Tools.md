# WellCMS 3.0 编码兼容性与调试工具 (Tools & Compatibility)

## 1. PHP 7.2+ 兼容性标准
*   **属性声明限制**：严禁使用 PHP 7.4+ 的类属性强类型声明（如 `protected string $name;`）。必须使用 `/** @var Type */` PHPDoc 注解代替。示例：`/** @var PageModel */ private $model;`。这在不引入 typed properties 语法（PHP 7.2 兼容）的同时，为 IDE 和静态分析提供完整类型信息，消除无类型声明蓝线警告。
*   **全局类限定**：内置全局类（如 `\Exception`, `\Throwable`）必须带前缀反斜杠，防止命名空间冲突。
*   **IDE 友好**：代码必须消除所有 IDE 级警告，红色报错为禁令。

### 1.1 继承与方法覆盖兼容性铁律（PHP 7.2 限制）
PHP 7.2 **不支持**协变返回类型与逆变参数类型。子类覆盖父类方法时，签名必须**完全兼容**：

| 维度 | 规则 | 示例 |
|------|------|------|
| **参数数量** | 必须相同（父类默认参数不计入强制参数） | 父类 `maxid(string $field = 'id')`，子类不可声明 `maxid()` |
| **参数类型** | 必须完全相同 | 父类 `array $data = []`，子类不可改为 `array $data`（无默认值） |
| **返回类型** | 必须完全相同；**父类无返回类型时子类也不能有** | 父类无 `: int`，子类不可加 `: int` |
| **默认值** | 允许不同 | 父类 `$key = ''`，子类可覆盖为 `$key = 'id'` |

**实践影响**：
*   提取 BaseModel 时，**不得**在 BaseModel 的方法上声明返回类型（`: int`、`: bool`、`: array`），否则所有子类都无法声明返回类型，且原来已声明返回类型的子类必须全部去掉。
*   若子类需要特殊默认参数（如 `find(..., string $key = 'id', ...)`），允许覆盖，但方法体应直接代理 `parent::find(...)`，禁止复制实现。
*   若子类方法签名与父类完全不兼容（如多一个 `$replace` 参数），**不得**继承 BaseModel，保持独立实现。

## 2. RequestUtils 类型自动转换
`RequestUtils` 根据第二个参数（默认值）的类型自动执行安全过滤。
*   `null`：返回原始（带转义）数据。
*   **0 (数字)**：自动执行 `(int)` 强制转换。
*   **'' (空字符串)**：自动执行 `(string)` 转换并应用 `htmlspecialchars`。
* **[] (数组)**：自动对数组内每个元素应用对应转换。
* **[0] (数组)**：自动对数组内每个元素执行 `(int)` 强制转换。
* **[''] (数组)**：自动对数组内每个元素执行 `(string)` 转换并应用 `htmlspecialchars`。

## 3. 调试与性能调优 (Debug & Optimization)
*   **DEBUG 模式**：
    *   `1`：记录日志，显示友好错误页面。
    *   `2`：显示完整条用栈，记录 SQL 查询明细。
*   **慢 I/O 监控**：日志会自动记录超过 200ms 的外部调用。
*   **数据驱动透明**：严禁耦合 MySQL 特有语法，必须通过底层的 `Grammar` 适配器保证兼容性。

## 4. 工具类迁移
*   **验证器**：优先使用 `Framework\Utils\Validator`。
*   **随机安全**：使用 `random_int()` 或 `random_bytes()`。

## 5. 参考实现 (Reference Implementation)
*   **RequestUtils 类型转换标准**：[RequestUtils.php](/src/Http/Psr7/RequestUtils.php)
*   **Validator 校验器标准**：[Validator.php](/src/Utils/Validator.php)

## 6. HttpClient open_basedir 兼容（生产环境）
PHP 8.0+ 中 `file_exists()` 在路径超出 `open_basedir` 限制时会抛出 `\ValueError`（非 `\Exception`）。`HttpClient::detectCaBundle()` 会检查系统 openssl 默认证书路径（如 `/usr/local/openssl/cert.pem`），该路径常在 `open_basedir` 之外。

任何使用 `HttpClient->request()` 的调用，无论 `verifySSL` 为何值，**必须同时传入 `'caBundle' => '/tmp/'`** 以短路 `detectCaBundle()` 的路径探测，防止因 `open_basedir` 违规引发 `\ValueError`。

```php
$client = new \Framework\Utils\HttpClient();
$response = $client->request([
    // ...
    'verifySSL' => false,
    'caBundle' => '/tmp/',   // 必须：短路 detectCaBundle()，防止 open_basedir 违规
]);
```

原理：`detectCaBundle('/tmp/', false)` → `file_exists('/tmp/')` 返回 `true`（目录存在且在 `open_basedir` 白名单内）→ 立即返回，不再检查 openssl 系统路径。`$ca` 结果仅在 `verifySSL=true` 时才作为 CURLOPT_CAINFO 使用，传入 `/tmp/` 无副作用。`/tmp/` 在 Linux 上始终存在且通常被 `open_basedir` 允许。
