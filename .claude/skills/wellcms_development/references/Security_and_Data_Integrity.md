# WellCMS 3.0 安全与业务严谨性 (Security & Integrity)

## 1. 双态 CSRF Token 闭环
*   **路由声明**：所有 POST 路由必须声明 `'requiresCsrf' => ['enable' => true, 'ttl' => 3600]`，CSRF 校验由 `CsrfMiddleware` 在路由层自动完成。
*   **控制器零代码**：控制器 **严禁** 调用 `verifyCsrfToken()` 手动验证，token 仅需传入模板供前端携带。
*   **访客状态 (Guest)**：表单与 AJAX 参数统一使用 `_token`。
*   **登录状态 (Member)**：表单与 AJAX 参数统一使用 `_csrf_token`。
*   **校验逻辑**：`CsrfMiddleware` 根据用户登录态自动切换对应的 Session Key 进行校验。

## 2. 数据处理与强类型化标准
*   **ID 转化**：所有外部获取的 ID 参数（如 `user_id`, `thread_id`），逻辑应用前 **必须** 强制执行 `(int)` 转换。
*   **配置空值校验**：访问核心配置前必须执行 `empty()` 判定，缺失时抛出异常。
*   **二进制主键与哈希 (Binary Storage)**：
    *   **统一格式**：应用层传递标准字符串（Hex/IP），入库前转二进制，取出后在 `format()` 中还原。
    *   **IP 处理**：入库执行 `IpHelper::normalizeIp()` 或 `IpHelper::ip()`，取出执行 `IpHelper::bin2ip()`。
    *   **UUIDv7 处理**：入库执行 `UuidHelper::toBinary()`，取出执行 `UuidHelper::fromBinary()`。
    *   **SHA256 处理**：入库执行 `hex2bin()` (32字节)，取出执行 `bin2hex()` (64字符)。
    *   **安全合规**：二进制数据存入 Session 或 Cache 前，**必须** 执行 `bin2hex()` 转换为 UTF-8 兼容字符串，防止序列化失败。

## 3. 极致协程隔离 (Strict Isolation)
*   **严禁类静态属性**：严禁在 Service 中使用 `static` 属性存储请求级数据。
*   **上下文快照**：Service 应在 `captureContext` 中锁定请求快照，逻辑层严禁直接调用全局辅助函数。
*   **UUIDv7 十六进制化**：缓存二进制主键时，Key 必须执行 `bin2hex`，输出时前端通过 `format` 补全 `id_hex`。

## 4. 参考实现 (Reference Implementation)
*   **IP 转换标准**：[ForumThreadService.php](/plugins/well_forum/Services/ForumThreadService.php)
*   **UUIDv7 二进制处理**：[ForumMessageService.php](/plugins/well_forum/Services/ForumMessageService.php)
*   **SHA256 二进制与 Session 安全**：[UploadService.php](/app/Services/Storage/UploadService.php)
*   **CSRF 中间件标准**：[CsrfMiddleware.php](/app/Middleware/CsrfMiddleware.php)
*   **权限校验标准**：[AuthMiddleware.php](/app/Middleware/Auth/AuthMiddleware.php)
