# WellCMS 3.0 智理系统与 AI Agent 规范 (AI Agent Standards)

面向 AI Agent 场景，系统开发必须在“实时性”、“记忆性”与“并发性”上执行最高标准。

## 1. 流式响应 (Streaming & SSE)
*   AI 响应建议使用 **SSE (Server-Sent Events)** 协议。
*   严禁在控制器的常规循环中执行阻塞等待 LLM 返回。
*   必须通过 `Generators` (yield) 配合 PSR-7 流式响应接口输出 Token。

## 2. 推理状态机 (Agentic State)
*   负责推理任务的 `ActionService` must 全量引入 `StatefulTrait`。
*   严禁使用全局静态变量存储思考上下文（Thinking Context），必须通过 `setState()` 确保协程级隔离。

## 3. RAG 向量管理 (Vector Data)
*   **PostgreSQL 首选**：推荐使用 `pgvector` 扩展。
*   **分区隔离**：向量数据表 must 按 `tenant_id` 或 `collection_id` 进行 **List 分区**，防止索引退化。
*   **语义检索性能**：执行向量搜索时，must 配合权限 tags 建立 **复合 GIN 索引**。

## 4. 记忆系统 (Memory System)
*   **临时上下文**：驻留在协程安全 Session 中。
*   **长短期记忆**：物理层 must 使用 **Range 分区**（基于 `created_at`）。
*   **粒度**：由于交互频率高，分区建议缩短为“月”或“周”。

## 5. 内容净化 (Safety & Cleaning)
*   **深度防御**：在视图渲染前，must 对 AI 生成的内容二次调用 `XssFilterMiddleware` 的净化逻辑，防止因 AI “幻觉”生成的代码包含 XSS 载荷。

## 6. 参考实现 (Reference Implementation)
*   **流式响应示例**：[AiController.php](/app/Controllers/Api/AiController.php) (待建立)
*   **状态隔离标准**：[ActionService.php](/app/Services/AI/ActionService.php) (待建立)
