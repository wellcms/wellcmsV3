# WellCMS 3.0 安全与业务严谨性 (Security & Integrity)

## 0. 威胁模型三步法 (Threat Model First)

**核心原则**：先画威胁模型再写代码。"事后粘贴的安全控制只是猜测。"

### Step 1: 映射信任边界 (Map Trust Boundaries)

识别不可信数据穿越系统边界的位置。WellCMS 中每一条边界都是攻击面：

| 信任边界 | 入口点 | 风险等级 |
|---------|--------|---------|
| HTTP 请求 | 所有 `GET/POST` 路由、API 端点 | 🔴 最高 |
| 表单提交 | 所有 POST 表单（含 `ajax-form`） | 🔴 最高 |
| 文件上传 | `UploadController`、`AttachmentService` | 🔴 最高 |
| Webhook/回调 | 插件外部回调入口 | 🟡 高 |
| 模板渲染 | `$view->raw('key')` 输出用户内容 | 🟡 高 |
| 数据库输入 | `where` 条件、`insert`/`update` 数据 | 🟡 高 |
| 缓存/ Session | Redis/APCu 存储的用户数据 | 🟡 高 |
| 外部 API 调用 | `MarketClient`、第三方服务集成 | 🟡 高 |
| 语言包输入 | 插件语言文件中的动态占位符 | 🟢 中 |
| Hook 文件编译 | 插件 Hook 注入点 | 🟢 中 |

### Step 2: 命名资产 (Name the Assets)

明确需要保护的资产：

- **凭据**：用户密码哈希、Session ID、CSRF Token、API Token
- **用户数据**：手机号、邮箱、IP 地址、个人资料（PII）
- **业务数据**：帖子、回复、订单、积分、权限配置
- **管理操作**：管理员后台操作（插件安装/卸载、主题切换、配置修改）
- **支付/资产**：涉及资金流动或实物资产的业务（如有）

### Step 3: STRIDE 遍历

对每一条信任边界，逐项检查 STRIDE 威胁：

| 威胁类型 | WellCMS 防护手段 | 常见失误 |
|---------|----------------|---------|
| **S**poofing（身份伪造） | `AuthMiddleware` + `requiresAuth` | 路由缺少 `requiresAuth` 声明，未登录可访问需鉴权接口 |
| **T**ampering（数据篡改） | `CsrfMiddleware` + 参数化查询 | POST 路由缺少 `requiresCsrf`，CSRF 中间件不生效 |
| **R**epudiation（抵赖） | 审计日志、操作记录 | 关键操作未记日志，事后无法追溯 |
| **I**nformation Disclosure（信息泄露） | `XssFilterMiddleware` + 输出编码 | `$view->raw('key')` 输出未经转义的用户内容 |
| **D**enial of Service（拒绝服务） | `ThrottleMiddleware` + 分区维护 | 未配置限流或分区计划，大流量冲击数据库 |
| **E**levation of Privilege（权限提升） | `UserPermMiddleware` + `requiresAdminSignIn` | 路由 `requiresUserPerm` 缺少 `'enable' => true`，权限校验被跳过 |

**滥用案例（Abuse Cases）**：在写正常用例的同时，问自己"如果我要攻击这个接口，会怎么做？"——那个答案就是你的第一轮测试用例。

**触发条件**：所有涉及用户输入、认证鉴权、敏感数据存储、外部 API 集成、文件上传/Webhook/支付处理的代码，**必须先做威胁模型分析再编码**。

---

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
