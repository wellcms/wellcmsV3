---
name: wellcms_development
description: WellCMS 3.0 核心开发、插件编写、服务注册、路由配置与主题定制规范
---

# WellCMS 3.0 开发核心规约 (Core Standards)

本手册由 `SKILL.md` (核心铁律) 与 `references/` (专项规范) 共同组成。

## 🔴 核心二十四项铁律 (The 24 Iron Laws)

1.  **优先使用主程序工具类**：编写任何工具方法前，必须先查阅 `Framework_Utils_Reference.md`，优先使用 `src/Utils/` 和 `app/Utils/` 中已实现的工具类（如 `DirectoryHelper::rmdirRecursive()`、`ZipUtility::zip()` 等），严禁重复造轮子。
2.  **ResponseFormatter 唯一性**：严禁绕过 `render()` 或 `message()`，严禁直接 `exit` 或 `json_encode`。**插件控制器严禁定义任何 `jsonResponse()` 包装方法**，必须直接调用主程序已提供的 `$this->responseFormatter->jsonResponseFormat(array $data)`；标准 API 响应结构 `{success, code, message, data, timestamp}` 在调用点显式组装，禁止通过中间层委托。
3.  **多维度 API 自动识别**：完全依赖框架对 Header、URL 参数及路由元数据的自动识别。
4.  **全局异常驱动**：所有核心错误必须抛出具名 Exception，由中间件统一接管。
5.  **统一响应结构**：Api 输出必须符合 `{status, code, message, data, timestamp}`，其中 `status` 取值仅为 `'success'` 或 `'error'`，严禁使用布尔型 `success`。
6.  **大数据表分离模式**：1000W+ 表必须采用"主表 + 独立索引表"架构。
7.  **容器服务注册模式**：必须使用"类名数组 + foreach 遍历"模式注册，禁止逐行 bind。
8.  **路由元数据驱动**：通过 `Routes.php` 定义 `meta` 元数据，控制器通过元数据指令调度。权限控制（`requiresUserPerm`）必须在路由声明，由 `UserPermMiddleware` 统一拦截。**控制器内严禁调用 `groupService->access()` 做重复权限判断**。CSRF 同理：**所有 POST 路由必须声明 `'requiresCsrf' => ['enable' => true, 'ttl' => 3600]`**，`CsrfMiddleware` 在路由层自动校验，**控制器内严禁调用 `verifyCsrfToken()` 做重复验证**，模板仅需传参。路由中间件是权限与安全控制的唯一入口。
9.  **RequestUtils 使用限制**：控制器外严禁调用。Service 必须使用 `StatefulTrait` 管理协程状态。<br>**`RequestUtils::param()` 类型转换铁律**：`param()` 内部已根据 `$defval` 类型自动强制转换（`0`→`int`、`0.0`→`float`、`''`→`string`、`false`→`bool`、`null`→原始值）。**严禁**在调用前追加强制类型转换，如 `(int)RequestUtils::param('id', 0)`、`(float)RequestUtils::param('price', 0)`。应直接写成 `RequestUtils::param('id', 0)`、`RequestUtils::param('price', 0.0)`。
10. **视图"绝对被动"原则**：模板严禁直访容器。必须执行"协同文本封装 (Synergy Text Encapsulation)"。
11. **编译驱动与物理压平**：插件碎片在编译阶段烧录，运行时 O(1) 加载，严禁磁盘探测。
12. **生产环境 I/O 冻结**：`DEBUG === 0` 时启用静态清单，不再执行 `filemtime` 检查。
13. **严禁修改主程序核心**：所有扩展必须通过钩子、路由注入或 `overwrite` (Rank 竞争) 实现。
14. **模板渲染路由驱动铁律**：控制器中**严禁硬编码模板文件名**。模板名必须从路由元数据读取：`$layout = $request->getAttributes()['_route_meta']['layout'] ?? '默认模板名';`，再传入 `$this->render($layout, $data, '插件目录名')`。`render()` 第三个参数传**插件目录名字符串**（如 `'well_forum'`），严禁传 `true`（系统后台模板）或第四个 `$id` 参数（除非主题继承需要）。后台控制器使用 `AdminTrait`，前台使用 `FrontendTrait`。
15. **服务层访问隔离**：控制器/Service 严禁跨插件直连 Model，必须通过对应的 Service 层。
16. **非主键聚合统计冗余化**：严禁实时 `COUNT(*)`，必须在实体表建立冗余统计并异步/同步更新。
17. **业务中枢逻辑解耦**：复合 Service 严禁直连数据库，必须通过对应的"基础原子服务"。
18. **原子服务一表一导原则**：每个表必须有且仅有一个原子 Service，遵循标准接口签名。
19. **强制索引匹配查询**：所有查询 `$condition` 必须严格对齐 `install.php` 定义的索引顺序。
20. **游标分页铁律 (Cursor Law)**：千万级数据严禁使用 `OFFSET`，必须统一采用"游标分页 + 锚点保护"。
21. **插件权限原子化更新**：严禁在 `install.php` 中使用 raw SQL 更新用户组权限，严禁修改主程序 `install/install.sql`。涉及 `well_group` 字段权限更新，必须调用 `GroupService` 封装类实现。
22. **控制器 Action 签名铁律**：所有 public Action 方法必须接收 `\Framework\Http\Interfaces\ServerRequestInterface $request` 参数，并声明 `: \Framework\Http\Interfaces\ResponseInterface` 返回类型。严禁在 Action 方法中省略 `$request` 参数或返回类型声明。构造函数 `__construct` 必须严格对齐 `BaseController` 签名，新增注入参数排在父类参数之后。
23. **WHERE IN 数组入参强制连续索引（P2 铁律）**：`Builder::where()` 使用 `isset($v[0])` 判定 IN 列表，非连续键数组（如 `array_diff` 返回 `[1=>20, 2=>30]`）会被误判为关联数组，导致 SQL 语法错误。任何经过 `array_diff()`、`array_unique()`、`array_intersect()` 处理的数组，在传入 `find()` / `delete()` / `update()` / `read()` 等方法的 `where` 条件作为 IN 列表前，**必须先执行 `array_values()` 重新索引**。严禁对关联数组条件（如 `['>=' => 10, '<=' => 20]`）使用 `array_values()`。
24. **路由路径对齐方法名铁律**：路由 URL 路径（path segments）**严禁使用横杠 `-` 或下划线 `_`**，必须使用 **PascalCase 驼峰**且**严格对齐控制器类名与方法名**，实现"见路径即知方法"的零成本定位。例如 `BatchSyncController::index()` → `/BatchSync`，`SettingController::save()` → `/PostSetting`。**知名 URL 前缀（`admin`、`api`）保留小写**，如 `/admin/MultiSite/BatchSync`、`/api/Sync/User/Receive`。路由路径 = 控制器缩写 + Action 方法名的 PascalCase 拼接，横杠和下划线破坏这种一一映射关系，导致上下游站点同步时无法通过路径反向索引到具体方法。横杠仅允许出现在域名或物理文件名后缀。

## 📂 专项规约资源索引 (Detailed Standards)

*   [Framework_Utils_Reference.md](./references/Framework_Utils_Reference.md) : **优先查阅！主程序工具类速查手册 (`src/Utils/`, `app/Utils/`)**。编写插件前先查此文档，避免重复造轮子。
*   [Common_Pitfalls.md](./skills/wellcms_development/references/Common_Pitfalls.md) : **常见陷阱与教训** - URL路由、请求路径等关键认知。
*   [Database_Standards.md](./references/Database_Standards.md) : **原子方法、BaseModel 提取策略、`find()` `$key` 语义、缓存锁、排列顺序(ORDER BY)、大数据分区(Partition)**。
*   [Pagination_Standards.md](./references/Pagination_Standards.md) : **锚点锁 (maxId)、双向游标探测、分页链接构建准则**。
*   [Plugin_Framework_Standards.md](./references/Plugin_Framework_Standards.md) : **config.json 指令、Assets 聚合、Rank 排序、私有 Vendor**。
*   [Middleware_and_Meta.md](./references/Middleware_and_Meta.md) : **Meta 矩阵表、自定义 Meta Resolver 开发模板**。
*   [Admin_and_Theme_Standards.md](./references/Admin_and_Theme_Standards.md) : **setting.php 推送逻辑、主题继承与扁平化寻址**。
*   [Architecture_Patterns.md](./references/Architecture_Patterns.md) : **DI 注入标准、构造函数复用铁律、容器自动装配继承构造函数、分布式锁、命名隔离**。
*   [Security_and_Data_Integrity.md](./references/Security_and_Data_Integrity.md) : **双态 CSRF、ID 强转、IP 存储、协程状态隔离**。
*   [UI_Interaction_Standards.md](./references/UI_Interaction_Standards.md) : **声明式交互 (ajax-post)、wellcms JS 工具集、后台视觉美学**。
*   [I18n_and_Hooks.md](./references/I18n_and_Hooks.md) : **语言包排他原则、动态 Language 钩子、异步 Job 任务规范**。
*   [Compatibility_and_Tools.md](./references/Compatibility_and_Tools.md) : **PHP 7.2 兼容标准、继承覆盖签名铁律、RequestUtils 类型转换逻辑、DEBUG 分级**。
*   [AI_Agent_Standards.md](./references/AI_Agent_Standards.md) : **流式响应、RAG 向量分区、智能系统记忆隔离理论**。
*   [Plugin_Install_Cleanup.md](./references/Plugin_Install_Uninstall_Standards.md) : **install.php 幂等性标准、物理资源冷迁移与卸载强力清理**。
*   [Project_Structure.md](./references/Project_Structure.md) : **WellCMS 3.0 全局物理目录树速查**。

---
*Last Updated: 2026-06-02 - 铁律 #8 强化：所有 POST 路由必须声明 `requiresCsrf`，CSRF 校验由中间件统一拦截，控制器零代码。铁律 #24：路由路径 PascalCase + admin/api 小写前缀。*
