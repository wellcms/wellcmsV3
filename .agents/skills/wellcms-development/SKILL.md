---
name: wellcms-development
description: WellCMS 3.0 核心开发、插件编写、架构审计、服务注册、路由配置、主题定制与 PHP 7.2 代码质量规范
---

# WellCMS 3.0 开发核心规约 (Core Standards)

本手册由 `SKILL.md` (核心铁律) 与 `references/` (专项规范) 共同组成。用户明确要求和根目录 [AGENTS.md](../../../AGENTS.md) 优先；技能更新不授权修改现有业务、上传或 Session 链路。审计以已确认方案和实际调用链为基线，禁止仅为统一风格迁移可用代码、增加表字段或扩大测试范围。

## 🔴 核心三十三项铁律 (The 33 Iron Laws)

1.  **优先使用主程序工具类**：编写任何工具方法前，必须先用 `rg` 检索主程序、目标插件及 `Framework_Utils_Reference.md`，优先复用 `src/Utils/`、`app/Utils/` 和已有 Service（如 `DirectoryHelper::rmdirRecursive()`、`ZipUtility::zip()` 等）。已有实现能够满足契约时严禁另写同义工具、包装层或旁路实现。
2.  **BaseController 响应唯一性**：插件控制器必须直接继承主程序 `BaseController`，严禁建立插件基础控制器继承层，严禁用 `exit`、`json_encode` 或 `$this->responseFormatter->jsonResponseFormat()` 自行输出响应。成功/失败响应必须直接调用 `BaseController::successMessage()` / `errorMessage()`；页面响应必须在 Action 末尾读取 `_route_meta` 后直接调用 `render()`。用于模板参数或领域哈希的普通数据序列化不属于响应输出，按当前论坛实现处理。插件控制器格式以当前 `plugins/well_forum/Controllers/Frontend/CollectionController.php` 为准。
3.  **多维度 API 自动识别**：完全依赖框架对 Header、URL 参数及路由元数据的自动识别。
4.  **全局异常驱动**：所有核心错误必须抛出具名 Exception，由中间件统一接管。
5.  **统一响应结构**：成功/失败输出必须由 `successMessage()` / `errorMessage()` 统一生成 `{status, code, message, data, timestamp}`，`status` 取值仅为 `'success'` 或 `'error'`，严禁插件自行组装或绕过 `BaseController`。
6.  **大数据表分离模式**：1000W+ 表必须采用"主表 + 独立索引表"架构。
7.  **容器服务注册模式**：必须使用"类名数组 + foreach 遍历"模式注册，禁止逐行 bind。
8.  **路由元数据驱动**：通过 `Routes.php` 定义 `meta` 元数据，控制器通过元数据指令调度。权限控制（`requiresUserPerm`）必须在路由声明，由 `UserPermMiddleware` 统一拦截。**控制器内严禁调用 `groupService->access()` 做重复权限判断**。安全令牌必须与当前框架、论坛实现一致：登录态 POST 声明 `'requiresCsrf' => ['enable' => true, 'ttl' => 3600]` 并携带 `_csrf_token`；允许访客访问的 POST 声明 `'requiresToken' => ['enable' => true, 'ttl' => 600]` 并携带 `_token`，不得叠加会拒绝访客的 `requiresCsrf`。控制器严禁手工调用 `verifyCsrfToken()` 或 `TokenService::verifyToken()`；路由中间件是权限与安全控制的唯一入口。
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
19. **数据表、字段与索引唯一合同铁律**：插件 `install.php` 的控制流必须以当前 `plugins/well_forum/install.php` 为准：逐表调用 `findTable('无前缀表名')`，不存在时直接执行 `CREATE TABLE `{$db->prefix}表名``，禁止 `%s` 表名和通用 DDL 包装器。`install.php` 必须定义当前完整 schema，包含历次升级新增的表和字段；已有安装的增量变更由 `upgrade.php` 处理代码、文件、表和字段，使用实际存在性检查并可重复执行。禁止另建迁移框架或运行期兼容分支。凡字段承担一条实体、操作或资料记录的主键 ID 语义，字段名必须严格为 `id`；严禁把它命名为 `operation_id`、`user_id` 等带业务前缀的 ID。关联字段、外键及业务定位字段允许并应使用 `operation_id`、`article_id`、`user_id` 等明确名称。严格一对一扩展表必须让 `id` 直接复用父实体 ID，禁止再创建数值相同的冗余关联 ID 字段。非分区记录主键使用 `PRIMARY KEY (id)`；分区表仅可为满足数据库约束在 `id` 后追加分区键。自然维度键是否作为主键按其数据合同判断，不得把“ID 命名规则”机械套用到 `month` 等非 ID 字段。每张表必须有紧邻建表分支的 PHP 语义注释，每个字段必须以 SQL 同行尾 `-- ...` 写清语义，严禁 SQL `COMMENT '...'`；`tinyint` 枚举字段必须列出全部 `0:xx 1:xx ...` 值域。字符串字段最长 `varchar(255)`，严禁更长 `varchar` 和数据库 `json` 类型；同一张表最多一个 `text`/`mediumtext`/`longtext` 字段，需要保存结构化数组时统一序列化到该字段。状态、类型、动作、事件、对象、结果、原因等分类码在 MySQL 必须使用 `tinyint`，禁止用 `varchar`。索引只能为已存在的真实查询创建；所有查询 `$condition` 的键顺序必须与对应索引前缀 100% 一致，排序仅允许一个已进入该索引的字段。字段、Model、原子 Service、查询、写入、读取和输出必须是同一当前合同，禁止运行期旧字段映射、双读、双写、别名或兼容路径；安装与升级阶段的结构处理遵循上述生命周期合同。
20. **游标分页铁律 (Cursor Law)**：千万级数据严禁使用 `OFFSET`，必须统一采用"游标分页 + 锚点保护"。
21. **插件权限原子化更新**：严禁在 `install.php` 中使用 raw SQL 更新用户组权限，严禁修改主程序 `install/install.sql`。涉及 `well_group` 字段权限更新，必须调用 `GroupService` 封装类实现。
22. **控制器格式与 Action 签名铁律**：所有插件控制器必须直接 `extends BaseController`；前台使用框架 `FrontendTrait`，后台使用框架 `AdminTrait`，API 不使用界面 Trait。所有 public Action 必须接收 `\Framework\Http\Interfaces\ServerRequestInterface $request` 参数，并声明 `: \Framework\Http\Interfaces\ResponseInterface` 返回类型。严禁在 Action 方法中省略 `$request` 参数或返回类型声明。构造函数 `__construct` 必须严格对齐 `BaseController` 签名，新增注入参数排在父类参数之后。实现格式以当前 `well_forum` 控制器为准。
23. **WHERE IN 数组入参强制连续索引（P2 铁律）**：`Builder::where()` 使用 `isset($v[0])` 判定 IN 列表，非连续键数组（如 `array_diff` 返回 `[1=>20, 2=>30]`）会被误判为关联数组，导致 SQL 语法错误。任何经过 `array_diff()`、`array_unique()`、`array_intersect()` 处理的数组，在传入 `find()` / `delete()` / `update()` / `read()` 等方法的 `where` 条件作为 IN 列表前，**必须先执行 `array_values()` 重新索引**。严禁对关联数组条件（如 `['>=' => 10, '<=' => 20]`）使用 `array_values()`。
24. **路由与类命名及全链对齐铁律**：路由名及固定路径段必须使用**小驼峰（lowerCamelCase，如 `multiSite`，首字母小写）**，自有 PHP 类名必须使用**大驼峰（PascalCase）**。路由与控制器类名不要求同名或按名称推导，路由声明必须绑定实际存在的 Controller/Action。固定路由名称/路径段及命名参数严禁使用 `-`、`_` 或首字母大写。路由声明、Controller/Action、`urlGenerator`、模板 URL、JS 请求、回调及文档必须同步；已有不合规路径应在授权范围内全链修正，不得以旧风格为由保留或增加迁移、别名、兼容路由。路由注册和权限机制复用当前框架/论坛实现，但不复制不合规旧名称。插件目录、Hook/模板名、权限/令牌字段及动态内容参数值遵循各自数据合同，不混同为固定路由名称。
25. **钩子归属与禁止自 hook 铁律**：钩子文件名中的命名空间路径**仅标识 hook 点所在的宿主类，不代表实现者**。**禁止插件 hook 自己的类**；跨插件能力（如消息通知、搜索索引、多站点同步）**必须由提供该能力的插件**实现对应 hook。宿主插件只负责在自身类中暴露 `// hook xxx` 扩展点，不得在自己的 `Hooks/` 目录下实现应由其它插件提供的扩展。例如文章评论/审核的消息通知必须由 `well_message` 实现，而非 `well_article` 自己实现。
26. **模板严禁拼接路由铁律**：模板（`views/htm/*.htm`）中**严禁**以任何形式拼接路由路径或 URL，包括路径段拼接（如 `$action . '/' . $id`）和查询字符串拼接（如 `$slug . '?tag=' . urlencode(...)`）。所有 URL 必须由控制器或服务层通过 `$this->urlGenerator->url()` 生成，并以完整变量形式传入模板；模板仅允许直接输出已封装好的 URL。重复犯错将导致路由与 `url_rewrite_on` 模式不同步、URL 重写失效及安全转义缺口。
27. **模板输出统一转义铁律**：HTML 文本和属性必须通过 `$view->e('key.subkey')` 输出，严禁模板自行调用 `htmlspecialchars()`、`htmlentities()`。`$view->raw('key', $default)` 用于遍历和判断；需要输出的循环项仍按原视图键路径调用 `e()`。控制器生成完整 URL 后，模板同样用 `e()` 输出属性值。页面底部脚本若直接输出 PHP，必须按 JavaScript 数据语法使用 `json_encode()` 及 `JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT`，不得把 HTML 转义当作 JS 字符串转义，详见交互规范。
28. **页面脚本归属铁律**：只有单页面使用的 JS 可以写在对应 HTM 底部，以 `<script>` 包裹，禁止为此另建 JS 文件或迁出已有可用页面。两个及以上页面共用代码写成 JS 文件，复用插件自身 `config.json` 的 `assets` 编译及 `<asset-js>` 加载机制；引用主程序已有公用模块使用 `@core`。非公用代码禁止放入 core 或使用 `@core`。已有单页面 JS 文件按用户既定要求直接在页面底部引用，不借规范更新强制迁移。页面底部脚本允许输出 PHP，但为避免格式化破坏语法不建议使用，优先从 HTML 属性或 `application/json` 配置节点读取；独立 JS 文件严格禁止直接输出 PHP。`@core` 是资产引用机制，不是 HTML 内联事件语法；具体解析范围见[插件资产规范](./references/Plugin_Framework_Standards.md#3-前端资产聚合-assets-aggregator)。`rte.js` 保持直接引用现有文件，不改用 `@core`，不复制编辑器实现。先加载依赖，沿用版本机制；交互复用声明式属性或 `wellcms.*`，禁止重封装 `fetch/XMLHttpRequest` 或引入外部 AJAX 库。不得仅因已有页面包含内联脚本或事件处理器而重写。

29. **零冗余与单一实现铁律**：新增代码前必须确认调用链中不存在同义能力；同一规则、状态转换、协议解析和 I/O 操作只能有一个权威实现。严禁无效分支、空操作赋值、不可达代码、未使用变量/导入/方法、复制粘贴实现和仅转发而不增加契约的包装层。兼容代码必须对应可证明的现有协议，不得以“可能有用”为由增加兜底。
30. **三元表达式优先铁律**：无副作用的二选一赋值、返回值、参数值或数组字段，只要一个三元表达式即可清晰完成，就绝不写 `if/else`。三元表达式必须保持单层、短小且两分支类型一致；禁止嵌套三元。涉及异常、提前返回、循环控制、多步状态变更或副作用时使用守卫语句，不得为了形式强塞三元。
31. **if 嵌套深度上限铁律**：`if` 最多允许嵌套 3 层，严禁出现第 4 层及以上；`elseif` 属于同层分派，不增加嵌套深度。接近上限时优先使用失败前置的 guard clause、`continue`、具名私有方法、策略映射或多态压平控制流。禁止用嵌套三元规避深度限制。
32. **运行效率铁律**：在保证契约正确前提下选择最少扫描、最少分配、最少 I/O 的实现。单次请求内严禁重复读同一文件、重复解析同一 Token/JSON、重复计算同一哈希、重复容器查找或执行可合并的数据库查询；缓存局部结果并优先单遍算法。高频链路修改必须检查时间复杂度、内存上界和外部 I/O 次数，禁止以抽象名义增加热路径层级。 涉及服务注册、启动 Hook、构造函数依赖或请求上下文注入时，必须读取并遵循 [服务加载性能约束](./references/Service_Loading_Performance.md)：注册与实例化分离，按需返回真实类型，保留副作用与 Swoole 生命周期，验证后清理探针。
33. **完整链路与功能保全铁律**：任何功能审计、修复或重构前，必须同时吃透客户端、服务端、路由、中间件、Service、持久化、回调、任务与生命周期脚本的数据流。即使任务只点名客户端或服务端，也必须反查另一端及请求、响应、状态转换、失败、补偿、回调和清理链路；未完成全链校对前不得给出“冗余、删除、拆分”结论。已有文件、路由、方法、回调和兼容协议均视为有效契约；不理解用途时先追踪全部调用方并询问，严禁从单端视角移除功能。只有复现缺陷并证明修复不破坏全链路后才能改动。

## 缓存与 KV 职责边界

缓存及业务锁复用主程序 `CacheManager` 提供的 `CacheInterface`；持久配置使用 `App\Services\System\KeyValueService` 的 `settingGet()`、`settingSet()`、`settingDelete()`，插件配置按自身 `well_***` 键隔离。禁止用缓存接口的 `set/delete/clear` 代替 KV 配置读写或删除，也不得直接操作共享 `setting` 缓存键来处理插件配置。插件不得因此重写 KV 服务内部实现或另建配置存储路径。容器入口及调用示例见 [API_Reference.md](./references/API_Reference.md)。

## 📂 专项规约资源索引 (Detailed Standards)

*   [Framework_Utils_Reference.md](./references/Framework_Utils_Reference.md) : **优先查阅！主程序工具类速查手册 (`src/Utils/`, `app/Utils/`)**。编写插件前先查此文档，避免重复造轮子。
*   [Common_Pitfalls.md](./references/Common_Pitfalls.md) : **常见陷阱与教训** - URL路由、请求路径等关键认知。
*   [Database_Standards.md](./references/Database_Standards.md) : **原子方法、BaseModel 提取策略、`find()` `$key` 语义、缓存锁、排列顺序(ORDER BY)、大数据分区(Partition)**。
*   [Pagination_Standards.md](./references/Pagination_Standards.md) : **锚点锁 (maxId)、双向游标探测、分页链接构建准则**。
*   [Plugin_Framework_Standards.md](./references/Plugin_Framework_Standards.md) : **config.json 指令、Assets 聚合、Rank 排序、私有 Vendor**。
*   [Middleware_and_Meta.md](./references/Middleware_and_Meta.md) : **Meta 矩阵表、自定义 Meta Resolver 开发模板**。
*   [Admin_and_Theme_Standards.md](./references/Admin_and_Theme_Standards.md) : **setting.php 推送逻辑、主题继承与扁平化寻址**。
*   [Service_Loading_Performance.md](./references/Service_Loading_Performance.md) : **服务注册、启动 Hook、按需依赖、真实类型工厂、请求上下文与 Swoole 性能验收**。
*   [Architecture_Patterns.md](./references/Architecture_Patterns.md) : **DI 注入标准、构造函数复用铁律、容器自动装配继承构造函数、分布式锁、命名隔离**。
*   [Security_and_Data_Integrity.md](./references/Security_and_Data_Integrity.md) : **双态 CSRF、ID 强转、IP 存储、协程状态隔离**。
*   [UI_Interaction_Standards.md](./references/UI_Interaction_Standards.md) : **声明式交互 (ajax-post)、wellcms JS 工具集、后台视觉美学**。
*   [I18n_and_Hooks.md](./references/I18n_and_Hooks.md) : **语言包排他原则、动态 Language 钩子、异步 Job 任务规范**。
*   [Compatibility_and_Tools.md](./references/Compatibility_and_Tools.md) : **PHP 7.2 兼容标准、继承覆盖签名铁律、RequestUtils 类型转换逻辑、DEBUG 分级**。
*   [AI_Agent_Standards.md](./references/AI_Agent_Standards.md) : **流式响应、RAG 向量分区、智能系统记忆隔离理论**。
*   [Plugin_Install_Uninstall_Standards.md](./references/Plugin_Install_Uninstall_Standards.md) : **install.php 幂等性标准、物理资源冷迁移与卸载强力清理**。
*   [Project_Structure.md](./references/Project_Structure.md) : **WellCMS 3.0 全局物理目录树速查**。

---
*Last Updated: 2026-10-29 - 补齐缓存与KV职责边界、原生配置接口及服务路径；既有业务合同和用户授权边界保持。*
