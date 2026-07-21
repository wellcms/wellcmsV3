# WellCMS 3.0 — AI 开发指南

## 项目背景

WellCMS 3.0 是一个**企业级 PHP 内容管理框架**，核心定位是高性能、可扩展的社区/论坛 SaaS 平台。通过插件钩子系统 + IoC 容器实现无侵入式功能扩展，支持千万级数据的分区归档、游标分页、协程安全隔离以及 Swoole/FPM 双运行模式。

- **版权**: Copyright (C) www.wellcms.com
- **许可证**: MIT
- **出口**: `public/index.php` → 定义 `IN_WELLCMS` 全局安全常量
- **DEBUG 分级**: `0`=生产环境（I/O 冻结，零 filemtime 检查），`1`=调试，`2`=开发

## 技术栈

| 层次 | 技术 | 版本要求 |
|------|------|----------|
| 语言 | PHP | 7.2+ (兼容至 8.5) |
| 运行时 | FPM (默认) / Swoole (协程) | — |
| 数据库 | MySQL 5.6+ / MariaDB / PostgreSQL | — |
| 缓存 | APCu / Redis / Memcached / Yac | — |
| 前端 | Tailwind CSS 3.4 + 原生 JS (无框架) | Node 16+ |
| 包管理 | 无 Composer 依赖！自研 Autoload + Compile | — |
| 测试 | PHPUnit (well_store_server 插件) | — |

## 请求生命周期 (Request Lifecycle)

```
public/index.php
  ├─ define('APP_PATH', ...), define('IN_WELLCMS', true)
  ├─ define('DEBUG', 2)
  ├─ require app/Core/Compile.php   → 收集 Hook、构建 Overwrite 缓存、语言包压平
  ├─ require app/Core/Autoload.php  → 前缀映射 classmap 自动加载
  ├─ App\Bootstrap::init($container)
  │    ├─ ConfigServiceProvider       → 注册 app/db/plugin/view/config 等 9 个配置数组
  │    ├─ DatabaseServiceProvider     → PdoDriver + ProxyDriver + Pool 绑定
  │    ├─ ModelServiceProvider        → 所有 Model 绑定（+ 插件钩子注入）
  │    ├─ ApplicationServiceProvider  → 路由、Session、上传、Store、分区管理等绑定
  │    ├─ RouteServiceProvider        → 加载 Routes.php → CompiledRouter
  │    ├─ MetaServiceProvider         → 注册 Meta Resolver 列表
  │    ├─ MiddlewareServiceProvider   → 构造中间件队列
  │    └─ $container->preResolve()   → 实例化所有非延迟服务
  ├─ ServerRequestFactory::createFromGlobals()
  ├─ App\Core\Kernel::run($container, $request)
  │    └─ Pipeline::handle($request)
  │         ├─ ErrorHandlerMiddleware
  │         ├─ RequestProcessorMiddleware   → 处理 URL rewrite 模式、路由参数解析
  │         ├─ LanguageMiddleware           → 注入 locale / LanguageLoader
  │         ├─ SessionMiddleware            → Session 初始化
  │         ├─ RuntimeMiddleware            → 协程上下文快照
  │         ├─ ThrottleMiddleware           → API 限流
  │         ├─ RouterMiddleware             → 路由匹配
  │         ├─ XssFilterMiddleware          → XSS 过滤
  │         └─ MetaDispatcherMiddleware     → 解析路由 meta → 注入 Auth/Csrf/UserPerm 等中间件
  │              ├─ AuthMiddleware (requiresAuth)
  │              ├─ AdminSignInMiddleware (requiresAdminSignIn)
  │              ├─ CsrfMiddleware (requiresCsrf)
  │              ├─ TokenMiddleware (requiresToken)
  │              └─ UserPermMiddleware (requiresUserPerm)
  └─ ResponseSender::send($response)
```

## 目录结构总览

```
wellcms-3.0/
├── public/index.php              # 唯一 Web 入口
├── src/                          # Framework\ 命名空间 — 框架核心层
│   ├── Core/                     # Container, Config, LazyLoadingProxy
│   ├── Database/                 # PdoDriver, ProxyDriver, Query/Builder, Partition, Pool, Sharding
│   ├── Http/                     # PSR-7 实现 (Request/Response/Stream/Uri)
│   │   ├── Middleware/           # Pipeline, MiddlewareFactory, RequestProcessorMiddleware
│   │   ├── Router/               # Router, Route, CompiledRouter
│   │   └── Routing/              # CoreUrlGenerator (UrlGeneratorInterface)
│   ├── Cache/                    # CacheManager + 4 驱动 (Redis/Memcached/APCu/Yac)
│   ├── Scheduler/                # TaskManage, Task, TaskExecutor, RedisTaskQueue
│   ├── Session/                  # SessionInterface + SessionHandler
│   ├── Logger/                   # FileLogger, SysLogger
│   ├── Utils/                    # 16 个工具类 (见 Framework_Utils_Reference.md)
│   ├── Exception/                # Http/Business/Validation/Infra 异常体系
│   ├── Providers/                # ServiceProviderInterface + LoggerServiceProvider
│   └── Config/                   # 9 个 .default.php 默认配置
├── app/                          # App\ 命名空间 — 应用层
│   ├── Bootstrap.php             # 服务注册 + 中间件队列编排
│   ├── Core/                     # Autoload, Compile, Kernel, ExceptionHandler
│   ├── Controllers/
│   │   ├── Base/                 # BaseController, ResponseFormatter, TemplateManager, MessageController
│   │   ├── Frontend/             # 前台控制器 (Index, Auth, User, My, Upload, Manage, Error)
│   │   ├── Admin/                # 后台控制器 (Index, User, Group, Plugin, Theme, Store, Setting 等)
│   │   └── Api/                  # API 控制器 (LinkPreview)
│   ├── Services/                 # 业务服务层
│   │   ├── Auth/                 # UserService, GroupService, SessionService, TokenService
│   │   ├── System/               # CacheService, LogService, MailService, MenuService, IpListService
│   │   ├── Storage/              # UploadService, AttachmentService, FileStorageService, StorageManager
│   │   ├── Content/              # NavigationService, TempContentService, RecycleService
│   │   ├── Upgrade/              # UpgradeService, Downloader, Deployer, ScriptRunner
│   │   ├── Market/               # MarketClient, MarketCircuitBreaker, MarketFallbackService
│   │   ├── Extension/            # ExtensionManager, ExtensionInstaller
│   │   └── Stats/                # RuntimeStats
│   ├── Models/                   # 数据模型 (均继承 BaseModel)
│   ├── Providers/                # 8 个 ServiceProvider
│   ├── Middleware/                # 12 个中间件
│   ├── Meta/                     # MetaRegistry + Resolver (Auth/Csrf/Token/UserPerm/AdminSignIn)
│   ├── Routes/                   # Routes.php — 全部路由定义
│   ├── Traits/                   # AdminTrait, FrontendTrait
│   ├── I18n/                     # LanguageManager, LocaleMapper
│   ├── Jobs/                     # 异步任务 (ImageCleanup, UploadToCloud, SessionGC, PartitionMaintain 等)
│   ├── Utils/                    # FileValidator, HtmlParseHelper, I18nDateFormatter, HttpLink, Paginator
│   ├── Factory/                  # ControllerFactory, CacheFactory
│   └── views/                    # 核心模板 (htm/css/js/font/icon/image)
├── plugins/                      # 4 个官方插件
│   ├── well_forum/               # 官方论坛 (核心插件)
│   ├── well_message/             # 消息通知
│   ├── well_i18n/                # 国际化插件
│   └── well_login2x/             # 登录增强
├── themes/                       # 主题 (well_demo, test_parent, test_child)
├── config/                       # 应用配置 (App.php, Database.php, Cache.php 等)
├── install/                      # 安装程序 (index.php + install.sql + install.lock)
├── storage/tmp/                  # 编译缓存 (classes/views/langs/configs)
├── public/static/runtime/        # 资产聚合产物 (8 位哈希指纹)
├── bin/                          # generate_classmap.php
├── scripts/                      # 迁移脚本
└── docs/                         # 技术文档
```

## 插件系统架构

### 插件目录标准结构

```
plugins/well_xxx/            # 每个开发者都应该且必须有属于自己的前缀，前缀为唯一 `my_xxx`，`well_`为官方前缀
├── config.json              # 元数据、assets、hooks_rank、overwrites_rank、install/uninstall 声明
├── install.php              # 建表 + PartitionManager 注册 + 权限字段注册
├── uninstall.php            # DROP TABLE + 注销分区 + 清理设置
├── setting.php              # 轻量后台设置 (< 10 页)
├── Controllers/
│   ├── Frontend/            # 前台控制器 (use FrontendTrait)
│   └── Admin/               # 后台控制器 (use AdminTrait)
├── Services/                # 原子服务 + 业务服务
├── Models/                  # 数据模型 (继承 BaseModel)
├── Hooks/                   # 钩子注入文件 (编译阶段物理烧录)
├── Language/{locale}/       # language.php (前台) + admin.php (后台)
├── views/htm/               # 插件模板 (必须在 views/htm/ 下)
├── static/                  # 静态资源 (js/css)
├── Overwrite/               # 物理覆盖文件 (Rank 竞争)
├── Jobs/                    # 异步任务
├── Middleware/               # 自定义中间件
├── Meta/                    # 自定义 Meta Resolver
├── Providers/               # 自定义 ServiceProvider
├── vendor/                  # 插件私有 Vendor (严禁修改主 composer.json)
└── scripts/                 # 迁移脚本
```

### Hook 机制 — 编译驱动 (Iron Law #11)

- **文件头格式**: 所有 Hook 文件必须以 `<?php exit;` 开头，`exit;` 之后的内容在编译阶段被 `Compile::compile()` 物理烧录到主程序目标位置
- **禁止 use 语句**: 必须使用 `\` 开头的全限定类名 (FQCN)，因为 Hook 内容直接注入到已有 `use` 导入的目标文件
- **禁止注释含 `<?php`**: `sanitizeHookCode()` 的安全正则 `#<\?(php|=|\s)#i` 会拦截注释中的 `<?`
- **安全校验**: `validateHookPhpCode()` 执行 Token 级 AST 分析，禁止 `eval`/`exec`/`system` 等危险函数
- **钩子命名**: 文件名即为钩子名称，多个插件通过 `hooks_rank` 值排序 (Rank 大者优先注入)

### config.json 核心字段

```json
{
  "name": "插件名",
  "type": "0",                  // 0=插件, 1=主题
  "version": "1.0.0",
  "software_version": "3.0.0",
  "installed": 0,  // installed和enable必须双重条件才启用
  "enable": 0,
  "rank": 90,                    // Rank 越大优先级越高 (覆盖者排在后面)
  "install": {"file": "install.php"},
  "uninstall": {"file": "uninstall.php"},
  "assets": { "global": {"css":[], "js":[]}, "admin": {"css":[], "js":[]} },
  "hooks_rank": {"hook_name": 10}, // 为空时 []
  "overwrites_rank": {"index.htm": 5} // 为空时 []
}
```

### Overwrite 机制 (Iron Law #13)

插件 `Overwrite/` 目录下的文件通过 `overwrites_rank` 决定覆盖优先级。当多个插件覆盖同一源文件，仅采纳 Rank 最高者的物理文件。覆盖路径映射关系：`plugins/xxx/Overwrite/views/home/index.htm` → 覆盖 `APP_PATH/views/home/index.htm`。

## 数据库规范

### 表名与前缀

- Model 的 `protected $table` **严禁带前缀**，DB 层自动拼接 `well_` 前缀
- install.php 中使用 `{$db->prefix}表名`

### BaseModel 标准方法

```php
class MyModel extends BaseModel {
    protected $table = 'my_table';  // 只需声明这一行
}
// 继承获得: insert(), update(), delete(), read(), find(), count(), maxid(), bulkInsert(), bulkUpdate()
```

### 原子服务一表一导 (Iron Law #18)

每个物理表必须有且仅有一个原子 Service，标准接口：
- `insert(array $data): int`
- `update(array $condition, array $update): bool` (增量用 `'field+'` / `'field-'` 键名后缀)
- `delete(array $condition): bool`
- `read(array $condition = []): array` — 返回单行
- `find(array $condition, array $orderby, int $page, int $pageSize): array` — 返回多行
- `count(array $condition = []): int`

### 严禁事项 (铁律)

- **严禁 JOIN/子查询/视图/存储过程** — 多表关联在 PHP 层逐表查询后合并
- **严禁 OFFSET 分页** — 必须使用游标分页 (cursor-based)
- **严禁实时 COUNT(*)** — 必须在实体表建立冗余统计字段
- **WHERE IN 必须 array_values()** — `array_diff` 等返回非连续键的数组在传入 where 前必须重新索引
- **索引匹配**: `$condition` 键名顺序必须与 install.php 索引定义 100% 匹配

### 大数据分区 (PartitionManager)

| 分区类型 | 需要注册 | 说明 |
|---------|---------|------|
| RANGE (created_at) | ✅ | 需要创建未来分区 + 清理过期数据 |
| RANGE + HASH 子分区 | ✅ | RANGE 部分需维护 |
| HASH (user_id/topic_id) | ❌ | 固定分区数，无需维护 |

install.php 建表只写 `PARTITION pmax VALUES LESS THAN MAXVALUE`，未来分区由 `PartitionManager::maintain()` 自动创建。三层维护：请求惰性检查 → 管理员手动 → 定时任务 (03:00)。

## 路由与中间件 (Meta 驱动)

### 路由声明 (Iron Law #8)

```php
// GET 页面 (带 layout)
Router::get('/list', [Controller::class, 'list'], ['layout' => 'list_view']);

// POST 操作 (必须有 requiresCsrf)
Router::post('/PostAction', [Controller::class, 'postAction'], [
    'requiresCsrf' => ['enable' => true, 'ttl' => 3600],
    'requiresUserPerm' => ['enable' => true, 'role' => ['administer', 'plugin']]
]);

// API 路由
Router::group(['prefix' => '/api', 'meta' => ['api' => true]], function () { ... });
```

### 路由路径铁律 (Iron Law #24)

**路径 segment 必须 PascalCase 且对齐 Controller + Method**：`BatchSyncController::index()` → `/BatchSync`。禁止横杠 `-` 和下划线 `_`。知名前缀 `admin`/`api` 保留小写：`/admin/BatchSync`。

### Meta 矩阵

| Meta 键 | 中间件 | 说明 |
|---------|--------|------|
| `requiresAuth: true` | AuthMiddleware | 用户登录态校验 |
| `requiresAdminSignIn: true` | AdminSignInMiddleware | 后台管理员校验 |
| `requiresCsrf: {enable, ttl}` | CsrfMiddleware | CSRF 防护，**控制器的零代码** |
| `requiresUserPerm: {enable, role[]}` | UserPermMiddleware | role 对应 well_group 表字段 |
| `requiresToken: {enable, ttl}` | TokenMiddleware | 令牌验证 |
| `api: true` | — | 强制 JSON 响应 |
| `api_rate_limit: {...}` | ThrottleMiddleware | 声明式限流 |

### CSRF 完整链路 (Iron Law #8)

```
路由声明 requiresCsrf → CsrfMiddleware 自动校验 → 控制器零 CSRF 代码
模板携带 _csrf_token → 中间件比对 → 放行/拦截
```

**控制器内严禁**调用 `verifyCsrfToken()` 或 `RequestUtils::param('_csrf_token')` 手动读取。**表单内禁用 `name="action"`**（会遮蔽 `form.action` 属性），使用 `_action`。

## 控制器开发规范

### Action 签名铁律 (Iron Law #22)

```php
public function actionName(
    \Framework\Http\Interfaces\ServerRequestInterface $request
): \Framework\Http\Interfaces\ResponseInterface {
    // ...
}
```

### 模板渲染铁律 (Iron Law #14)

```php
// ✅ 正确：从路由元数据读取模板名
$routeMeta = $request->getAttribute('_route_meta') ?? ['layout' => 'default'];
return $this->render($routeMeta['layout'], $data, false);       // 主程序 - 前台: false / 后台: true
return $this->render($routeMeta['layout'], $data, 'well_xxx');  // 插件 - 前台和后台: 插件目录名
```

### 插件控制器铁律

- **严禁定义构造函数** — 必须完全继承 BaseController
- **严禁私有 getter 声明返回类型** — 容器返回 LazyLoadingProxy 会触发 TypeError
- Service 在 Action 内通过 `$this->container->get(ServiceClass::class)` 获取
- **严禁定义 jsonResponse() 包装方法** — 必须直接调用 `$this->responseFormatter->jsonResponseFormat($data)`

### 控制器 Data 合约

```php
$data = [
    'header' => ['title' => ..., 'keywords' => ..., 'description' => ...],
    'menu' => $this->getAdminMenu(),                                // 后台
    'navigation' => $this->getNavigation(),                         // 前台
    'menu_fixed' => ['parent' => '...', 'child' => '...'],
    'csrf_token' => $this->getCsrfToken($user['salt']),
    'extra' => $extra,
    'page_link' => $this->urlGenerator->url($pageLinkString, $extra),
    'page_link_string' => $pageLinkString,
    'action' => $this->urlGenerator->url('admin/xxx/PostAction'),   // POST 页面
    'language' => ['key1' => $this->language->get('key1'), ...],
    // ... 业务数据
];
```

### JSON 响应格式 (Iron Law #2, #5)

```php
$data = [
    'status' => 'success',        // 仅 'success' 或 'error'，严禁布尔型
    'code' => 0,
    'message' => 'Operation successful',
    'data' => [...],
    'timestamp' => time(),
];
return $this->responseFormatter->jsonResponseFormat($data);
```

### 游标分页 (Iron Law #20)

```php
// 构建适配器
$adapter = BaseController::makeGenericAdapter(
    [$threadService, 'find'],
    ['orderKey' => 'id', 'indexKey' => 'id', 'baseCondition' => $baseCond, ...]
);

// 执行分页
[$items, $hasMore, $firstId, $lastId] = $this->fetchPaged(
    $adapter, $pageSize, $cursorId, 'id', -1, $dirFlag
);

// 构建链接
$this->buildPaginationLinks($pagination, $page, $firstId, $lastId, $hasMore, $base, $extra);
```

## IoC 容器 (Framework\Core\Container)

### 注册模式 (Iron Law #7)

```php
// $providers 数组 + foreach 注册
$providers = [
    'Logger' => LoggerServiceProvider::class,
    'Config' => ConfigServiceProvider::class,
    // ...
];
foreach ($providers as $providerClass) {
    $prov = new $providerClass();
    $prov->register($container);
    if (method_exists($prov, 'boot')) { $prov->boot($container); }
}
```

### Service 构造函数铁律

```php
class MyService {
    use \Framework\Core\Traits\StatefulTrait;  // 协程安全
    protected $container;

    // ✅ 正确：只接受 Container
    public function __construct(\Framework\Core\Container $container) {
        $this->container = $container;
        $this->myModel = $container->get(MyModel::class);
    }
}
```

### LazyLoadingProxy

容器 bind 时 `defer=true` 创建延迟代理。调用代理方法时自动触发真实对象创建。**控制器不能声明具体 Service 类型的 getter 返回类型声明**，因为容器热路径可能返回 Proxy。

### RedisCache 连接模型（关键架构决策）

WellCMS 中有两条独立的 Redis 连接路径，选择不同的连接模型是**有意为之的设计**，不可混用：

| 场景 | 获取方式 | 连接模型 | 适用原因 |
|------|---------|---------|---------|
| **Scheduler 守护进程** (CLI/Swoole) | `$container->get(RedisCache::class)` → **非池化直连** | 每个 `get()` 创建新 `RedisCache`+TCP 连接；非单例（`bind=false`） | 单进程顺序执行无并发争用，池化带来无谓的借还开销；`cachepre` 需要动态切换以支持多站点隔离 |
| **FPM 管理操作** （低频） | `$cache = CacheManager` → `$cache->original('redis')` → **连接池** | 从 `RedisPool` 借/还连接，单例实例 | Swoole 多协程下防连接冲突；FPM 低频路径无性能诉求 |
| **插件卸载脚本** （一次性） | `$container->get(RedisCache::class)` → **非池化直连** | 同 scheduler | 一次性 FPM 操作，借还无意义 |

**为什么 scheduler 不用连接池（行业标准参照）：**

- **Sidekiq**（Ruby 多线程）：每线程 1 直连，无池
- **Celery**（Python 多进程）：每进程 1 直连，无池
- **Bull**（Node.js event loop）：1 直连，无池
- **Redis 自身**（单线程 event loop）：1 连接

连接池解决的是 **多线程/多协程争用有限连接** 的问题。CLI 单进程顺序执行不存在争用——加池子反而多出三层闭包开销（`borrow → run → release`），且破坏 `cachepre` 多站点隔离。

**Swoole 多协程特殊说明：** 当前 scheduler 通过 `reconnectRedis()` 在每个协程中新建连接来回避并发冲突。这是工作但不优雅的方案。后续如果正式启用 Swoole 模式，scheduler 应该统一走连接池。当前 CLl/FPM 模式下保持直连是正确的。

```php
// ✅ 正确：调度器直连（高频路径）
$container->bind(RedisCache::class, fn($c) => new RedisCache($cfg), false, true);

// ✅ 正确：FPM 管理操作通过 CacheManager 走池化（低频路径）
$cache  = $container->get(CacheInterface::class); // CacheManager
$redis  = $cache->original('redis');               // 池化 RedisCache

// ❌ 禁止：在 FPM 管理操作中直接从容器取 RedisCache 绕开连接池
$redis  = $container->get(RedisCache::class); // 绕过了 CacheManager，丢掉了连接池
```

**结论：** 当前设计（调度核心直连、管理操作走池化）是合理的分层。两项均为工业级标准做法，不需要统一。

## 国际化 (I18n)

### 语言包层级

编译时按 `核心 → 插件 → 主题 → Hook 片段` 物理压平为单体缓存：
- 核心: `app/Language/{locale}/language.php` + `admin.php`
- 插件: 同上结构
- 主题: 同层覆盖
- Hook: `Hooks/language_{locale}_{type}.php` 动态注入

### 命名与审计

- **排他原则**: 优先复用主程序已有词条，禁止重复定义
- **14 语言必须**: 插件新增语言键必须在全部 14 个 locale 目录下补充翻译
- **前后台分离**: `language.php` (前台) 与 `admin.php` (后台) 物理隔离，不存在 fallback
- **审计必须同时检查**: 语言包完整性审计必须扫描 `language.php` + `admin.php` 两个文件

## 零兜底严格模式 (Iron Law #25)

- **严禁 `??` 和 `?:` 兜底**: 数据不存在时应让 PHP Warning/Error 自然暴露
- **catch 块必须记日志**: 禁止空 `catch { return []; }`，必须调用 `LoggerInterface::warning/error`
- **API 失败必须记录原始错误**: 禁止返回空数组让调用方误判

## 编码规范

### PHP

- **declare(strict_types=1)** 写在所有文件首行
- **PHP 7.2 兼容**: 禁止使用 `??=`、match 表达式、命名参数、只读属性、枚举等 8.0+ 语法
- **类属性类型声明**: PHP 7.2 不支持，使用 `/** @var Type */` PHPDoc 注释替代
- **方法覆盖签名**: 子类方法参数类型必须与父类完全一致，不得增删
- **RequestUtils::param() 不加强转**: `param('id', 0)` 已自动返回 int，严禁 `(int)param(...)`
- **Model 继承**: 所有 Model 继承 BaseModel，只需声明 `$table`，禁止重复定义 `__construct`

### 模板 (.htm)

- **扁平化**: 仅放在 `/htm/` 根目录，严禁子目录
- **安全输出**: `$view->get('key')` / `$view->e('key')` (自动 htmlspecialchars) / `$view->raw('key')` (原始输出)
- **foreach 变量必须用 htmlspecialchars()**: `$view->e()` 只能访问 $view 数据池，不能用于循环变量
- **资产声明**: `<asset-css group="global" />` `<asset-js group="admin" />`
- **属性访问**: 直接访问一级键 `$view->key`，迭代 `$view->iterate('items')`

### JS

- **ajax-post 声明式交互**: `<form class="ajax-form" action="..." method="POST">`
- **wellcms JS 工具集**: wellcms.ajax()、wellcms.modal() 等全局封装

## 框架工具类速查

优先使用主程序已有工具类，禁止重复造轮子 (Iron Law #1)。

| 命名空间 | 常用类 | 路径 |
|----------|--------|------|
| `Framework\Utils` | ArrayHelper, DirectoryHelper, FileHelper, IpHelper, HttpClient, SafeHelper, SecurityHelper, Validator, UuidHelper, ZipUtility 等 (16 个) | `src/Utils/` |
| `App\Utils` | FileValidator, I18nDateFormatter, Paginator, HtmlParseHelper, HttpLink | `app/Utils/` |

详细 API 参考: `.claude/skills/wellcms_development/references/Framework_Utils_Reference.md`

## 禁止事项

1. **严禁修改主程序核心文件** — 所有扩展必须通过 Hook、路由注入或 Overwrite 实现
2. **严禁修改 install/install.sql** — 插件表在插件自己的 install.php 中创建
3. **严禁 comsumer.json 依赖** — 无 Composer，使用自研 Autoload
4. **严禁跨插件直连 Model** — 必须通过对方的 Service 层
5. **严禁在模板中直接访问容器** (视图绝对被动原则)
6. **严禁在 Service/Model 编写跨表 JOIN**
7. **严禁在控制器中硬编码模板文件名**
8. **严禁在控制器内手动 CSRF 验证**
9. **严禁静态属性存储请求级数据** (协程安全)
10. **严禁路由路径使用横杠或下划线**
11. **严禁 JSON 响应使用布尔型 success** — 必须是 `'success'` | `'error'`
12. **严禁在 Action 中省略 $request 参数或返回类型声明**
13. **严禁插件控制器定义构造函数或声明具体类型的私有 getter**
14. **严禁 Hook 文件中使用 `use` 语句或注释含 `<?php` 字面量**
15. **严禁表单内使用 `name="action"`** (遮蔽 form.action)
16. **不引入新的第三方依赖**，除非经确认

## 参考文档索引

所有专项规范位于 `.claude/skills/wellcms_development/references/`:

| 文档 | 内容 |
|------|------|
| [Framework_Utils_Reference.md] | 主程序工具类 API 速查 **优先查阅** |
| [Common_Pitfalls.md] | 18 个常见陷阱与教训 (路由/Hook/CSRF/模板等) |
| [Database_Standards.md] | 原子服务、BaseModel、分区管理、索引匹配 |
| [Architecture_Patterns.md] | 控制器模式、DI 注入、分布式锁、命名隔离 |
| [Plugin_Framework_Standards.md] | config.json、Rank、Assets 聚合、路由注入 |
| [Middleware_and_Meta.md] | Meta 矩阵、CSRF/权限 Resolver 开发 |
| [Security_and_Data_Integrity.md] | CSRF、ID 强转、二进制存储、协程隔离 |
| [Pagination_Standards.md] | 锚点锁、游标双向探测、分页链接 |
| [I18n_and_Hooks.md] | 语言包编译压平、Job 异步任务 |
| [Admin_and_Theme_Standards.md] | setting.php、主题继承、扁平化寻址 |
| [Plugin_Install_Uninstall_Standards.md] | install.php 幂等性、卸载清理 |
| [Compatibility_and_Tools.md] | PHP 7.2 兼容、RequestUtils 类型转换、DEBUG 分级 |
| [Project_Structure.md] | 全局物理目录树速查 |
| [Audit_Client_Server_Alignment.md] | 客户端-服务端全链路对齐审计 |

---

*本文档基于代码静态分析生成，最后更新: 2026-06-10*
