# WellCMS 3.0 Agent 智理上下文 (Agent Context)

本文档是 WellCMS 3.0 的核心设计意志快照，专为 AI Agent（如 Antigravity, Cursor, Windsurf）提供高精度的上下文记忆。它记录了项目的逻辑现状、关键历史决策以及未来维护时的红色警戒区。

---

## 🧬 项目 DNA (Project DNA)

- **版本状态**：v3.0.0 Stable (Standard Release Edition)
- **核心哲学**：**"物理压平，逻辑聚合，绝对被动"**。
  - **物理压平**：通过编译引擎将插件钩子、语言包、路由等碎片在运行时前烧录成 O(1) 阶的单体缓存。
  - **逻辑聚合**：不分 Api/Web，统一入口、统一中间件管线、统一响应格式化。
  - **绝对被动**：视图（View）严禁包含逻辑，数据必须在控制器阶段完全协同封装好后再注入。
- **环境底座**：PHP 7.2+ | Swoole 4.8+/FPM | Redis | MySQL/PostgreSQL 100% 驱动兼容。

---

## 🏗️ 核心架构意志 (Architectural Imprints)

### 1. 容器驱动与延迟加载 (DI & Lazy Loading)

- **现状**：全系统基于 `Framework\Core\Container`。插件与核心 Service 均采用"类名数组绑定"模式实现注册，由容器在首次调用时自动实例化并注入依赖。
- **警告**：严禁在构造函数以外直接调用 `Container::get()`，除非是极少数底层钩子点。

### 2. 中间件管线 (Middleware Pipeline)

- **编排顺序**：`ErrorHandler` -> `RequestProcessor` -> `Language` -> `Session` -> `Runtime` -> `Throttle` -> `Router` -> `XssFilter` -> `MetaDispatcher`。
- **重要决策**：在 v3.0 开发过程中，系统**拒绝并清理了**由 AI 尝试执行的"地毯式/机器自动化"钩子注入。
- **维护原则**：中间件必须遵循**"人工精准挂钩 (Precise Manual Hooking)"**原则。仅在核心业务关键点手工预留必要的钩子，严禁通过脚本执行全量重复的占位符填充，以防止由于钩子冗余导致的逻辑冲突和执行效率下降。
- **驱动逻辑**：外部中间件的激活由路由 `meta` 元数据配合 `MetaDispatcher` 实现动态嗅探。中间件内部的扩展权归还给手动管理的高质量钩子。

### 3. 被动视图与协同封装 (Passive View)

- **核心逻辑**：控制器必须在调用 `render()` 前，将 URL、语言包 Label、样式 Class 封装为统一的结构（如 `action_buttons`）。
- **目的**：通过"协同文本封装"，使视图层彻底失去生成 URL 和拼接语言包的能力，从而保证 UI 的 100% 确定性。

---

## 🔒 关键业务逻辑链 (Critical Paths)

### 1. 数据库驱动层 (Database Dialects)

- **现状**：完美兼容 MySQL 与 PostgreSQL。
- **红线**：严禁在业务代码中出现任何 `desc`, `order`, `user` 等保留字作为字段名。所有 WHERE 条件必须匹配 `Model` 中定义的索引顺序。

### 2. 上传与附件系统 (Upload & Attachment)

- **现状**：支持分片上传、MD5/SHA1 秒传检测、云存储（如阿里云 OSS）无缝迁移。
- **审计状态**：`UploadService` 与 `AttachmentModel` 已通过全量逻辑审计，其状态判定与哈希比对链条不可中断。

### 3. 全局锁与 KV 服务 (Locking & KeyValue)

- **现状**：`KeyValueService` 实现了基于 Redis/文件系统的原子级分布式锁。
- **重要修订**：`set` 和 `delete` 操作已强制包含 `lock` 闭环，确保在高并发协程环境下配置项修改的原子性。

---

## ⚠️ 避雷指南 (Warning & Constraints)

- **类属性声明**：为保持 PHP 7.2+ 兼容性，**严禁**使用 PHP 7.4 的类属性强类型声明。必须使用 `@var` 注释，例如：

  ```php
  /** @var string */
  protected $name;
  ```

- **Swoole 协程隔离**：严禁在任何 `Service` 或 `Model` 中使用 `static` 属性存储请求变量。必须使用 `StatefulTrait` 及其 `getState/setState` 接口。
- **模板渲染路由驱动**：控制器中**严禁硬编码模板文件名**。模板名必须从路由元数据读取：`$layout = $request->getAttributes()['_route_meta']['layout'] ?? '默认模板名';`，再传入 `$this->render($layout, $data, '插件目录名')`。`render()` 第三个参数传**插件目录名字符串**（如 `'well_forum'`），严禁传 `true`（系统后台模板路径）或第四个 `$id` 参数（除非主题继承需要）。
- **JSON 响应铁律**：插件控制器**严禁**自定义 `jsonResponse()` 或任何类似包装方法。主程序 `BaseController` 已注入 `$this->responseFormatter`，必须直接调用 `$this->responseFormatter->jsonResponseFormat(array $data)`。标准结构 `{status, code, message, data, timestamp}` 在调用点显式组装，`status` 取值仅为 `'success'` 或 `'error'`，严禁使用布尔型 `success`；禁止通过 Trait、父类或中间层二次封装。
- **Action 签名铁律**：所有 public Action 必须接收 `\Framework\Http\Interfaces\ServerRequestInterface $request` 参数，并声明 `: \Framework\Http\Interfaces\ResponseInterface` 返回类型。后台 `use AdminTrait;`，前台 `use FrontendTrait;`，API 无 Trait。

---

## 🚀 给"未来 Agent"的任务交接 (Agent Handover)

1. **开发规范优先级**：`.agent/skills/wellcms_development/SKILL.md` 的优先级高于一切建议。
2. **重构底线**：你可以优化性能，但严禁改动 `src/` 核心框架的接口签名，除非是为了修复高危 Bug。
3. **插件审核**：辅助用户编写插件时，必须检查 `install.php` 是否具备平滑升级判定逻辑。

---

*Context Saved: 2026-01-30 - WellCMS 3.0 项目开发完成，发布基准达成。*
