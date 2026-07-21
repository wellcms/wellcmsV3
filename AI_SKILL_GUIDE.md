# WellCMS 3.0 — AI 技能调用自然语言指南

> **目的**：你不需要记命令，只需要说清楚你想做什么，AI 会自动匹配对应的技能和规范来执行。
> **用法**：找到你要做的事→照着"你可以说"列的任意一句说就行。也可以自由组合、换说法。

---

## 快速索引

| 常见需求 | 直接说这句 |
|---------|-----------|
| 写一段 WellCMS 代码 | "帮我写个插件控制器" / "新增一个路由" |
| 调试错误 | "帮我排查这个 500 错误" / "为什么页面白屏" |
| 拆任务 | "把这件事分解成任务" / "做个开发规划" |
| 先写规格再开发 | "先出个 spec" / "写需求规格文档" |
| 代码审查 | "帮我审查这段代码" / "做 code review" |
| 安全审查 | "安全检查一下" / "做安全审计" |
| 准备发布 | "准备上线" / "走发布检查清单" |
| 跑项目 | "启动项目看看" / "跑起来验证一下" |
| 查工具类 | "查一下有没有现成的工具方法" / "Framework 工具类" |
| 改配置 | "把模型切到 opus" / "允许 git 命令" |
| 调研技术方案 | "帮我调研一下这个方案" / "做深度研究" |

---

## 一、WellCMS 开发（最常用）

### 1.1 写控制器 / Action

**触发能力**：控制器开发规范、Action 签名铁律（#22）、模板渲染铁律（#14）、Data 合约、JSON 响应格式（#5）
**参考文件**：`SKILL.md`（铁律 #5/#8/#14/#22）、`references/Architecture_Patterns.md`

| 你可以说 | AI 会做什么 |
|---------|-----------|
| "帮我写一个前台控制器" | 生成继承 BaseController、使用 FrontendTrait 的控制器，包含完整 Action 签名和 Data 合约 |
| "新增一个列表页的 Action" | 生成 Controller::list()，自动匹配路由声明的 layout 模板名 |
| "这个 POST 接口需要返回 JSON" | 生成使用 `$this->responseFormatter->jsonResponseFormat()` 的 Action，含 `{status,code,message,data,timestamp}` 结构 |
| "写一个后台管理页面的控制器" | 生成使用 AdminTrait 的控制器，包含后台菜单配置和权限校验 |

### 1.2 写路由

**触发能力**：路由声明规范（#8）、PascalCase 路径铁律（#24）、Meta 矩阵
**参考文件**：`SKILL.md`（铁律 #8/#24）、`references/Middleware_and_Meta.md`

| 你可以说 | AI 会做什么 |
|---------|-----------|
| "新增一个 GET 路由" | 生成 `Router::get('/PageName', ...)`，路径自动对齐控制器名 |
| "声明一个需要登录的页面路由" | 生成带 `requiresAuth => true` meta 的路由 |
| "新增一个 POST 接口，需要 CSRF 校验" | 生成带 `requiresCsrf => ['enable' => true, 'ttl' => 3600]` 的路由 |
| "写一个管理员才能访问的路由组" | 生成 `Router::group(['prefix' => '/admin', 'meta' => ['requiresAdminSignIn' => true, ...]], ...)` |
| "这个路由需要权限控制，角色是 administer" | 生成带 `requiresUserPerm => ['enable' => true, 'role' => ['administer']]` 的路由 |

### 1.3 写数据库 / Model / Service

**触发能力**：BaseModel 规范、原子服务一表一导（#18）、索引匹配（#19）、游标分页（#20）
**参考文件**：`references/Database_Standards.md`、`references/Pagination_Standards.md`、`references/API_Reference.md`

| 你可以说 | AI 会做什么 |
|---------|-----------|
| "新建一个表对应的 Model" | 生成继承 BaseModel 的类，仅声明 `$table = 'name'` |
| "写一个原子 Service" | 生成包含 insert/update/delete/read/find/count 标准接口的 Service |
| "这个 Service 需要一个分页查询方法" | 生成游标分页的 find 方法，使用 fetchPaged 和 buildPaginationLinks |
| "给这个表设计分区" | 根据数据量推荐分区策略（RANGE/HASH/组合），生成 PartitionConfig 注册代码 |
| "这个查询条件需要加索引" | 分析查询模式，给出索引定义建议，对齐 install.php 索引顺序 |

### 1.4 写插件

**触发能力**：插件目录结构、config.json 规范、Hook 机制、Overwrite 机制、Rank 排序
**参考文件**：`references/Plugin_Framework_Standards.md`、`references/Plugin_Install_Uninstall_Standards.md`

| 你可以说 | AI 会做什么 |
|---------|-----------|
| "创建一个新插件的脚手架" | 生成完整的插件目录结构（Controllers/Hooks/Models/Services/views 等） |
| "写一个插件的 install.php" | 生成幂等性 install：`CREATE TABLE IF NOT EXISTS` + GroupService 权限注册 + 分区声明 |
| "写一个 Hook 注入" | 生成以 `<?php exit;` 开头的 Hook 文件，全 FQCN 无 use 语句，编译后注入到目标位置 |
| "插件需要覆盖主程序的模板" | 生成 Overwrite 目录下的覆盖文件，配置 overwrites_rank |
| "配置插件的前端资源" | 生成 config.json 的 assets 配置（global/admin 的 css/js 声明） |
| "给插件加后台设置页" | 生成 setting.php（轻量 ≤10 页）或独立控制器（重型 >10 页） |

### 1.5 写模板 / 前端交互

**触发能力**：模板渲染规范、$view 数据访问、声明式交互（ajax-form/GlobalClickHandler）、wellcms JS API
**参考文件**：`references/Template_Variables.md`、`references/UI_Interaction_Standards.md`、`references/Admin_and_Theme_Standards.md`

| 你可以说 | AI 会做什么 |
|---------|-----------|
| "写一个列表页模板" | 生成 .htm 模板，包含 `$view->e()` 安全输出、分页链接、csrf_token |
| "这个表单需要 ajax 提交" | 添加 `class="ajax-form"`、`data-loading-text`、`data-timeout` 等声明式属性 |
| "做一个弹窗确认删除" | 使用 `data-confirm` + `ajax-post` 属性，调用 wellcms.modal() |
| "模板里怎么渲染语言键" | 使用 `$view->e('language.key')`，指出前后台语言包分离规则 |
| "这个模板需要继承父主题" | 配置主题继承关系，列出 3 层寻址优先级 |

---

## 二、流程式开发（先规划再动手）

### 2.1 写 Spec（需求规格）

**触发能力**：Spec → Plan → Task → Implement 四阶段门控流程
**参考文件**：`references/Spec_Driven_Development.md`

| 你可以说 | AI 会做什么 |
|---------|-----------|
| "先出个 spec 再开发" | 生成六要素规格文档：目标、路由声明、Controller 签名、数据库变更、服务接口、边界条件，含 Always/Ask First/Never 分类 |
| "做需求分析" | 暴露隐含假设，逐条让你确认，确认后输出规格文档 |
| "写一个功能规格文档" | 使用标准模板，包含成功标准和开放问题 |
| "这个需求模糊，帮理清" | 引导你澄清需求，识别不确定点，输出规格草案 |

### 2.2 拆任务 / 做规划

**触发能力**：依赖图映射、垂直切片、任务粒度标准
**参考文件**：`references/Planning_and_Task_Breakdown.md`

| 你可以说 | AI 会做什么 |
|---------|-----------|
| "把这件事分解成可执行的任务" | 画依赖图，按垂直切片拆成 3-5 个 M 级任务，每个含验收标准和验证步骤 |
| "做个开发规划" | 输出任务列表、检查点位置、风险表 |
| "这个功能怎么拆" | 按 WellCMS 调用链（路由 → 控制器 → Service → Model → 模板）逐一分解 |
| "排一下优先级和依赖" | 分析任务依赖图，标识可并行和必须串行的部分 |

---

## 三、调试与错误排查

### 3.1 调试问题

**触发能力**：停线协议、七层诊断法、错误模式对照表
**参考文件**：`references/Debugging_and_Error_Recovery.md`

| 你可以说 | AI 会做什么 |
|---------|-----------|
| "帮我排查这个 500 错误" | 按七层诊断法逐层定位：编译层 → 路由层 → 中间件层 → 控制器层 → 服务层 → 模型层 → 模板层 |
| "页面白屏了" | 建议先切 DEBUG=2，检查编译缓存、路由匹配、模板路径 |
| "Hook 不生效" | 检查 compile_manifest.php 中 Hook 是否存在，检查 <?php exit; 头、FQCN、注释含不含 <?php |
| "这个错误是 Array to string conversion" | 定位到 update() 第二个参数用了嵌套数组，建议改为键名后缀语法 |
| "报 LazyLoadingProxy not found" | 检查控制器是否声明了具体 Service 返回类型的 getter |
| "为什么控制器拿不到数据" | 检查 read() 和 find() 是否混用、查询条件是否对齐索引顺序、array_values() 是否漏了 |

### 3.2 常见陷阱自查

**触发能力**：18 个已记录陷阱 + 反合理化表
**参考文件**：`references/Common_Pitfalls.md`

| 你可以说 | AI 会做什么 |
|---------|-----------|
| "这个写法有没有坑" | 对照 18 个常见陷阱逐一检查 |
| "看看我这段代码有没有踩坑" | 对代码做陷阱扫描，覆盖路由/模板/Hook/数据库/安全每类 |
| "这个场景常见的错误是什么" | 检索反合理化表，输出对应场景的借口→反驳→后果 |

---

## 四、代码质量与审查

### 4.1 代码审查

**触发能力**：五轴代码审查（正确性/可读性/架构/安全/性能）
**参考命令**：`/code-review`（注册 skill，不冲突）

| 你可以说 | AI 会做什么 |
|---------|-----------|
| "帮我 review 这段代码" | 五轴审查：正确性（边界/错误路径）、可读性（命名/复杂度）、架构（职责边界/重复）、安全（输入/注入/权限）、性能（N+1/分页/缓存） |
| "审查这个 PR 的改动" | 审查 diff，按严重级别分类问题，给出修改建议 |
| "检查代码规范" | 对照 WellCMS Iron Law 逐条检查：无 ?? 兜底、无 JOIN、路径 PascalCase、CSRF 声明等 |

### 4.2 安全审查

**触发能力**：威胁模型三步法 + STRIDE 遍历
**参考文件**：`references/Security_and_Data_Integrity.md`
**参考命令**：`/security-review`

| 你可以说 | AI 会做什么 |
|---------|-----------|
| "做安全审计" | 威胁模型三步法：画信任边界 → 命名资产 → STRIDE 遍历，逐条检查防护手段 |
| "检查这个接口有没有安全问题" | 分析接口的认证/鉴权/CSRF/输入校验/输出编码，对照 9 个信任边界 |
| "这个上传功能安全吗" | 检查 MIME 校验、magic bytes、文件大小限制、路径遍历防护 |

### 4.3 代码简化

**触发能力**：复用/可读性/效率审查
**参考命令**：`/simplify`

| 你可以说 | AI 会做什么 |
|---------|-----------|
| "简化这段代码" | 检查重复代码、提取公共逻辑、简化控制流、删除死代码 |
| "这代码能不能更简洁" | 应用铁律 #1：优先使用主程序工具类（16 个 src/Utils、5 个 app/Utils） |

### 4.4 验证功能

**触发能力**：运行项目 + 观察行为
**参考命令**：`/verify`、`/run`

| 你可以说 | AI 会做什么 |
|---------|-----------|
| "启动项目验证一下" | 启动项目 → 检查是否正常运行 → 执行关键流程 → 报告结果 |
| "验证这个修改工作正常" | 运行项目 → 触发修改的代码路径 → 确认预期行为 |
| "跑起来看看效果" | 启动服务 → 截图或输出运行状态 → 确认功能正常 |

---

## 五、发布上线

### 5.1 发布准备

**触发能力**：8 大章节 175 项检查清单 + 反合理化借口表
**参考文件**：`references/Pre_Launch_Checklist.md`

| 你可以说 | AI 会做什么 |
|---------|-----------|
| "准备上线，走一遍检查清单" | 逐项执行 8 大章节检查：代码质量/安全/性能/数据库/I18n/路由/基础设施/首小时验证 |
| "发布前检查" | 重点检查：编译缓存清除、Tailwind 重编、存储缓存清理、CSRF 声明完整、无 ?? 兜底 |
| "做发布前的安全检查" | 运行安全检查子清单：所有 POST 路由已声明 requiresCsrf、无密钥硬编码、Hook 文件格式正确 |
| "看看有没有遗漏的发布步骤" | 对比 Pre_Launch_Checklist 完整清单，标记未完成的检查项 |
| "上线后怎么监控" | 输出首小时验证步骤：健康检查 → 监控错误率/延迟 → 验证核心流程 |

### 5.2 回滚准备

**触发能力**：回滚计划（触发条件、步骤、时间目标）
**参考文件**：`references/Pre_Launch_Checklist.md`（第 7 节）

| 你可以说 | AI 会做什么 |
|---------|-----------|
| "准备回滚方案" | 制定回滚触发条件、执行步骤、时间目标（flag < 1min / 版本 < 5min / DB < 15min） |
| "出问题怎么回滚" | 输出回滚计划：回滚代码 → 清除缓存 → 回滚 DB → 验证 → 通知团队 |

---

## 六、协程安全与高并发

### 6.1 协程安全检查

**触发能力**：协程安全红线、StatefulTrait、captureContext
**参考文件**：`references/Coroutine_Safety.md`

| 你可以说 | AI 会做什么 |
|---------|-----------|
| "检查这段代码的协程安全性" | 检查：超全局变量、静态属性、exit/die、成员属性（需 StatefulTrait）、文件 IO |
| "这个 Service 在 Swoole 下安全吗" | 检查是否使用 StatefulTrait、容器获取方式、协程状态隔离 |
| "帮我改成协程安全的写法" | 将 $_GET/$_POST 替换为 `$request->getQueryParams()/getParsedBody()`，加 StatefulTrait |

---

## 七、国际化与语言包

### 7.1 语言包处理

**触发能力**：前后台语言包分离、14 语言强制、占位符替换
**参考文件**：`references/I18n_and_Hooks.md`

| 你可以说 | AI 会做什么 |
|---------|-----------|
| "新增一个语言键" | 在 `language.php`（前台）和/或 `admin.php`（后台）中添加键值对，标记需要补充的 14 个 locale |
| "语言包审计" | 扫描所有 `$this->language->get('xxx')` 调用，按前后台分类，检查缺失的键和 locale |
| "这个语言键有占位符" | 使用 `$this->language->get('key', ['name' => $value])` 替换，提示禁止用 str_replace 二次替换 |
| "补充所有语言包" | 列出全部 14 个 locale 目录，逐文件生成翻译占位 |

---

## 八、研究与学习

### 8.1 深度调研

**触发能力**：多源搜索、交叉验证、结构化报告
**参考命令**：`/deep-research`

| 你可以说 | AI 会做什么 |
|---------|-----------|
| "帮我调研一下这个技术方案" | 多源搜索 → 交叉验证 → 输出结构化报告（含来源链接） |
| "对比 MySQL 和 PostgreSQL 做分区的优劣" | 搜索 + 比对 → 输出对比表、适用场景、WellCMS 兼容性评估 |

### 8.2 查 API / 工具类

**触发能力**：框架工具类速查（16 + 5 个工具类）
**参考文件**：`references/Framework_Utils_Reference.md`、`references/API_Reference.md`

| 你可以说 | AI 会做什么 |
|---------|-----------|
| "查一下有没有处理数组排序的工具" | 检索 Framework_Utils_Reference，找到 ArrayHelper::multiSortKey / multiSort / sortKey |
| "IP 转二进制用哪个方法" | 检索到 IpHelper::normalizeIp/ip2bin/bin2ip，给出完整调用示例 |
| "文件上传校验有哪些方法" | 检索 app/Utils/FileValidator，列出可用方法 |
| "查 API 速查" | 返回核心 API 速查：queryOne/query/count/insert/update/delete + 路由 + 用户 + 缓存 |

---

## 九、Claude Code 系统配置

### 9.1 模型切换

**触发能力**：切换 AI 模型
**参考命令**：`/model`

| 你可以说 | AI 会做什么 |
|---------|-----------|
| "把模型切到 opus" | 执行 `/model claude-opus-4-8` |
| "用最快的模型" | 执行 `/model haiku` |
| "切回默认模型" | 执行 `/model` 查看当前并切换 |

### 9.2 配置修改

**触发能力**：settings.json 配置变更
**参考命令**：`/update-config`

| 你可以说 | AI 会做什么 |
|---------|-----------|
| "允许 npm 命令不弹权限" | 修改 settings.json 添加 npm 命令权限到 allowlist |
| "设置 DEBUG=true" | 配置环境变量或 settings.json |
| "每次启动时自动加载这个文件" | 配置 hooks 实现启动时自动行为 |

### 9.3 权限管理

**触发能力**：减少权限弹窗、工具调用权限
**参考命令**：`/fewer-permission-prompts`

| 你可以说 | AI 会做什么 |
|---------|-----------|
| "每次执行 git 命令都弹窗，处理一下" | 扫描历史记录，将 git 命令加入全局 allowlist |
| "减少权限弹窗" | 扫描常见只读命令，批量加入 allowlist |

### 9.4 快捷键

**触发能力**：键盘快捷键定制
**参考命令**：`/keybindings-help`

| 你可以说 | AI 会做什么 |
|---------|-----------|
| "自定义快捷键" | 读取 keybindings.json 并引导配置 |
| "把 Ctrl+Enter 改成提交快捷键" | 修改 keybindings.json 中的 submit 绑定 |

### 9.5 定时任务

**触发能力**：循环执行任务
**参考命令**：`/loop`

| 你可以说 | AI 会做什么 |
|---------|-----------|
| "每 5 分钟检查一次部署状态" | 设置 CronCreate，每 5 分钟执行一次状态检查 |
| "每天早上 9 点提醒我检查服务器" | 设置定时任务，工作日 9:00 推送通知 |

---

## 十、跨域通用能力

### 10.1 CLAUDE.md 初始化

**触发能力**：项目初始化
**参考命令**：`/init`

| 你可以说 | AI 会做什么 |
|---------|-----------|
| "给这个项目初始化 CLAUDE.md" | 扫描项目结构、语言、依赖、测试框架，生成 CLAUDE.md |

### 10.2 Claude API 参考

**触发能力**：API 调用、模型选择、定价查询
**参考命令**：`/claude-api`

| 你可以说 | AI 会做什么 |
|---------|-----------|
| "Claude 的 API 怎么调用工具函数" | 返回 Claude API 工具调用文档、参数说明、代码示例 |
| "Fable 5 的价格是多少" | 查询最新模型定价 JSON |
| "怎么用缓存" | 返回 Prompt Caching 文档和最佳实践 |

---

## 组合场景示例

| 你的一句话 | AI 会综合调度的能力 |
|-----------|-------------------|
| "帮我写一个插件，先出 spec，再做规划，然后实现" | ① Spec_Driven_Development（写规格）→ ② Planning_and_Task_Breakdown（拆任务）→ ③ 开发插件（SKILL.md + Plugin_Framework_Standards + Database_Standards + 所有相关规范） |
| "实现用户注册功能，review 一下我的代码，然后准备上线" | ① 开发（控制器规范 + 路由 + CSRF + 数据库）→ ② code-review（五轴审查）→ ③ Pre_Launch_Checklist（发布清单） |
| "调试这个 bug，修完之后检查有没有安全漏洞" | ① Debugging_and_Error_Recovery（诊断修复）→ ② Security_and_Data_Integrity（威胁模型分析） |
| "写一个 API 接口，review，然后简化" | ① JSON 响应规范（铁律 #5）→ ② code-review → ③ simplify → ④ Framework_Utils_Reference（复用已有工具） |

---

## 快速对照表：场景 → 技能 → 文件

| 你的场景 | 触发关键词 | 核心技能/文件 |
|---------|-----------|-------------|
| 开发 WellCMS | 插件、控制器、路由、Service、Model、模板 | `SKILL.md`（铁律）+ `references/` 全部 |
| 写规档 | spec、规格、需求分析 | `references/Spec_Driven_Development.md` |
| 拆任务 | 分解、规划、任务、依赖 | `references/Planning_and_Task_Breakdown.md` |
| 调试 | 调试、排查、报错、白屏、500、bug | `references/Debugging_and_Error_Recovery.md` |
| 防坑 | 陷阱、常见错误、pitfall、踩坑 | `references/Common_Pitfalls.md` |
| 安全 | 安全、审计、威胁、漏洞、XSS、CSRF | `references/Security_and_Data_Integrity.md` |
| 发布 | 发布、上线、部署、发布检查 | `references/Pre_Launch_Checklist.md` |
| 协程 | 协程、Swoole、并发、协程安全 | `references/Coroutine_Safety.md` |
| 语言包 | 国际化、语言、翻译、i18n、locale | `references/I18n_and_Hooks.md` |
| 工具类 | 工具、Utils、ArrayHelper、IpHelper | `references/Framework_Utils_Reference.md` |
| API 速查 | API、数据库、query、insert | `references/API_Reference.md` |
| 前端 | 模板、view、ajax、JS、UI | `references/Template_Variables.md`、`references/UI_Interaction_Standards.md` |
| 数据库 | 表、索引、分区、Model、SQL | `references/Database_Standards.md`、`references/Pagination_Standards.md` |
| 插件 | 插件、Hook、Overwrite、config.json | `references/Plugin_Framework_Standards.md`、`references/Plugin_Install_Uninstall_Standards.md` |
| 路由 | 路由、Meta、中间件、CSRF、权限 | `references/Middleware_and_Meta.md` |
| 审计 | 审计、对齐、客户端-服务端 | `references/Audit_Client_Server_Alignment.md` |
| 兼容性 | PHP版本、兼容、7.2、8.x | `references/Compatibility_and_Tools.md` |
| 代码审查 | review、审查、代码质量 | `/code-review` skill |
| 代码简化 | 简化、重构、clean | `/simplify` skill |
| 安全审查 | 安全审查、security-review | `/security-review` skill |
| 验证 | 验证、跑起来、测试功能 | `/verify` skill + `/run` skill |
| 深度调研 | 调研、研究、对比、分析方案 | `/deep-research` skill |
| 模型切换 | 模型、切模型、opus、haiku | `/model` 命令 |
| 配置修改 | 配置、允许、禁止、设置 | `/update-config` skill |
| 快捷键 | 快捷键、键绑定、Ctrl | `/keybindings-help` skill |
| 定时任务 | 定时、循环、每X分钟、每X小时 | `/loop` skill |
| Claude API | Claude API、模型价格、Fable、Sonnet | `/claude-api` skill |

---

> **最后更新**：2026-06-15 — 覆盖 SKILL.md（27 项铁律）+ 26 个 reference 文件 + 14 个系统级 skill/命令
