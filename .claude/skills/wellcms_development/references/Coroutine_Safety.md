# WellCMS 3.0 协程安全开发规范 (High-Concurrency Coding Rules)

为了确保系统在 Swoole 或其他常驻内存环境下的稳定性，开发者必须遵守以下“红线”。

## 1. 🚫 超全局变量与原生会话禁令 (Global State)
- **严禁使用**：`$_GET`, `$_POST`, `$_SERVER`, `$_COOKIE`, `$_SESSION`。
- **严禁调用原生函数**：`session_start()`, `session_decode()`, `session_encode()`, `session_id()`, `session_status()`。
- **替代方案**：统一使用注入的 `ServerRequestInterface $request` 对象获取输入，以及 `SessionInterface` 管理会话。
- **原因**：超全局变量在多协程共享的进程空间内会发生覆盖，原生会话函数会导致「Session is not active」错误或全局状态串扰。

## 2. 🏗️ 服务类（Service）成员属性隔离
- **核心风险**：Service 是单例（Singleton），其成员属性在常驻内存环境下会被所有协程共享。
- **规范要求**：
    - **禁止**：定义用于存储请求相关数据、临时查询结果、逻辑快照的成员属性（如 `private $data = [];`）。
    - **允许**：仅限在构造函数中初始化且运行期不变的对象（如 `protected $dbModel`, `protected $cache`）。
- **解决方案**：必须使用 `Framework\Core\Traits\StatefulTrait`。
    - 通过 `$this->getState('name')` 获取隔离状态。
    - 通过 `$this->setState('name', $value)` 设置隔离状态。

## 3. 慎用 `exit` 和 `die`
- **建议**：在控制器中统一 `return $response`，或者抛出异常由 `ErrorHandlerMiddleware` 统一处理。
- **原因**：在长连接模式下，`exit` 会强制 Worker 进程重启，导致性能剧烈波动。

## 4. 快照捕获机制 (Snapshot)
- **实践**：在 Service 中实现 `captureContext(ServerRequestInterface $request)`。
- **目的**：将请求中的 IP、UA、Session 等“瞬间状态”打成快照存入协程上下文，消除对 `RequestUtils` 等静态工具类的依赖，确保深层逻辑的协程安全性。

## 5. 文件 IO 与目录扫描
- **原则**：运行期严禁执行 `scandir` 或频繁的 `file_exists`。
- **实践**：利用 `Compile` 引擎预生成的配置 Map 或 `class_map.php`。

## 6. 参考实现 (Reference Implementation)
*   **协程状态机维护标准**：[StatefulTrait.php](/app/Traits/StatefulTrait.php)
*   **请求级上下文捕获**：[BaseController::captureContext](/app/Controllers/Base/BaseController.php)
