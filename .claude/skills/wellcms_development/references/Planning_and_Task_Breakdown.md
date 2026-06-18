# WellCMS 3.0 规划与任务分解规范 (Planning & Task Breakdown)

**定位**：流程式参考文档，不注册为 Claude Code skill。多文件/多模块任务前由 AI 参照此流程分解任务。

---

## 适用场景

**使用条件**（满足任一）：
- 任务涉及 5 个以上文件
- 任务跨多个模块（路由 + 控制器 + Service + Model + 模板）
- 任务是插件级别的功能模块开发
- 任务需要多轮迭代

**不需要的场景**：单文件改动、纯配置修改、语言包补充。

---

## 依赖图映射

WellCMS 典型的调用链：

```
Routes.php (路由声明)
  ↓ meta 中定义了 requiresAuth / requiresUserPerm / requiresCsrf
Middleware (中间件校验)
  ↓ 放行后
Controller (接收 $request → 调用 Service → 渲染模板)
  ↓ 通过 $this->container->get()
Service (业务逻辑)
  ↓ 通过 Model
BaseModel (insert/update/delete/read/find/count)
  ↓
Database (PdoDriver)
```

**任务顺序必须是自底向上或自顶向下**，严禁打乱依赖顺序：
1. 先声明路由（定义 path + meta）
2. 再实现中间件（如果自定义）
3. 再实现 Service（数据库层前提）
4. 再实现 Controller
5. 再写模板
6. 最后验收

---

## 垂直切片原则

**错误的分层切法（水平）**：
```
第一层：所有数据库表设计
第二层：所有 Service
第三层：所有 Controller
第四层：所有模板
```
→ 问题：前 3 层无法独立验证，到最后才能跑通。

**正确的垂直切片（WellCMS 适配）**：

```
切片 1: 「用户能查看列表」
  路由: Router::get('/PageList', ...)        → 1 条路由
  控制器: list()                               → 1 个 Action
  Service: find()                              → 1 个方法
  Model: 继承 BaseModel                        → 1 行声明
  模板: page_list.htm                          → 1 个模板
  → 验证：访问 /PageList.html 看到列表 ✅

切片 2: 「用户能新增条目」
  路由: Router::post('/PostPage', ...)         → 1 条 POST 路由
  控制器: postPage()                            → 1 个 Action
  Service: insert()                             → 1 个方法
  模板: page_edit.htm                           → 1 个模板
  → 验证：提交表单后数据入库 ✅

切片 3: 「用户能编辑条目」
  ...
```

**每个切片都是一个完整的、可独立验证的功能路径**。

---

## 任务任务分解标准

### 任务模板

```markdown
## 任务: {动词 + 名词，如"新建页面列表路由与控制器"}

**依赖**: {本任务之前的任务编号}

**涉及文件**（< 5 个）:
- `plugins/well_xxx/Routes.php`
- `plugins/well_xxx/Controllers/Frontend/PageController.php`
- `plugins/well_xxx/views/htm/page_list.htm`

**验收标准**:
- `/PageList.html` 返回 HTTP 200
- 页面正确显示分页列表
- 未登录用户看到空白页（因为 `requiresAuth`）

**验证步骤**:
1. 清除 `storage/tmp/` 编译缓存
2. 浏览器访问 `/PageList.html`
3. 确认 HTTP 200 且列表数据正确
```

### 任务粒度标准

| 规格 | 文件数 | 适合场景 | WellCMS 示例 |
|------|--------|---------|-------------|
| XS | 1 | 单函数/单配置 | 修改 `config.json` 的 `rank` 值 |
| S | 1-2 | 单路由 + 控制器 Action | 新增一个 GET 路由 + Action |
| **M（推荐）** | **3-5** | **一个功能切片** | **路由 + 控制器 + Service + 模板 → 列表页** |
| L | 5-8 | 多组件功能 | 新增一个完整 CRUD 功能（需 4-5 个切片） |
| XL | 8+ | **必须再拆分** | 整个插件 → 拆为 3+ 个 M 级别任务 |

### 需要拆分的信号

- 任务标题中包含"和"、"与"、"、"（逗号）
- 难以用 ≤3 条验收标准描述
- 涉及多个独立子系统（如同时改数据库和前端 JS）
- 估计耗时超过 2 小时

---

## 检查点策略 (Verification Checkpoints)

每完成 2-3 个任务，执行一次检查点验证：

**检查点内容（WellCMS 专用）**：
```
□ 清除 storage/tmp/classes/、views/、langs/ 编译缓存
□ 访问新增路由，确认 HTTP 状态码正常
□ 确认 JSON 响应结构 {status, code, message, data, timestamp}
□ 确认模板渲染使用路由元数据中的 layout
□ 确认 POST 路由 CSRF 校验正常（不提交 _csrf_token 看是否拦截）
□ 确认控制器内无 `??` 兜底
□ 确认 Hook 文件格式正确（<?php exit; + FQCN）
□ 确认增量 update 使用键名后缀（field+ / field-）
```

---

## 并行与串行决策

| 场景 | 策略 | 说明 |
|------|------|------|
| 独立的不相关的插件功能 | ✅ 并行 | 两个功能不共享数据/路由 |
| Shared Service 接口 + Controller | ⚠️ 先定义接口契约 | 双方围绕接口开发，确认契约后再独立并行 |
| 数据库迁移 | ❌ 必须串行 | 一个任务一个 DDL，不能并行建表 |
| 路由 + 控制器 + 模板（同一功能） | ❌ 必须串行 | Controller 依赖 Service，Service 依赖 Model |
| 前端 JS + 后端 API | ✅ 可并行（API 契约先行） | 先定 JSON 响应结构，前后端各自实现 |
| 语言包翻译 | ✅ 可并行 | 与代码开发独立 |

---

## 风险前置

**高风险任务优先做**，而不是留到最后：

| 风险类型 | 识别信号 | 应对 |
|---------|---------|------|
| 技术风险 | 不确定框架是否支持某个能力 | 先写最小原型验证 |
| 依赖风险 | 某个功能依赖另一个插件的未发布接口 | 先确认接口契约，提供 Mock |
| 数据风险 | 大数据量下的查询性能 | 先建索引、写基准查询测试 |
| 安全风险 | 涉及权限/CSRF/Token | 先写好路由 meta 声明，中间件先行验证 |
| 兼容风险 | PHP 7.2 ~ 8.5 兼容 | 不写 `??=`、`match`、命名参数、只读属性 |

---

## 产出要求

每个规划阶段完成后输出：
- 依赖图（文字或 ARD 格式）
- 3-8 个垂直切片任务（每个 M 级，3-5 文件）
- 2-3 个检查点（位置 + 验证内容）
- 1 个风险表

---

**检查清单（开始实现前）**：
- [ ] 每个任务有验收标准
- [ ] 每个任务有验证步骤
- [ ] 依赖顺序正确
- [ ] 没有任务超过 5 个文件
- [ ] 检查点已标记在任务序列中
- [ ] 高风险项已排在前面

---

*本文档改编自 agent-skills `planning-and-task-breakdown`，2026-06-15 适配 WellCMS 3.0 专有约束（垂直切片适配 WellCMS 路由→控制器→Service→Model→模板调用链）*
