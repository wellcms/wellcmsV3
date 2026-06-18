# 常见陷阱与教训 (Common Pitfalls & Lessons)

**文档位置**: `.agent/skills/wellcms_development/references/Common_Pitfalls.md`  
**最后更新**: 2026-05-14

---

## 教训 #1: URL 路由匹配必须理解 `processRouteParams` 处理机制

**背景**: WellCMS 3.0 的路由匹配分为两个阶段：
1. `RequestProcessorMiddleware::processRouteParams()` - 处理 URL 路径（包括去掉 `.html` 后缀）
2. `CompiledRouter::match()` - 使用处理后的路径进行路由匹配

**错误模式** (严禁):
```php
// ❌ 错误：认为服务端路由有 .html 后缀
Router::post('/list.html', ...);

// ❌ 错误：认为需要加 plugin/ 前缀
$this->market->request('plugin/list.html', ...);

// ❌ 错误：去掉 .html 后缀
$this->market->request('list', ...);
```

**正确模式** (必须):
```php
// ✅ 服务端路由定义（无 .html 后缀）
Router::post('/list', ...);

// ✅ 客户端请求（带 .html 后缀，MarketClient 会自动拼接）
$this->market->request('list.html', ...);
// 实际拼接过程：
// - MarketClient::apiBasePath = 'api/v1/store'
// - request() 方法：$fullPath = $this->apiBasePath . '/' . ltrim('list.html', '/')
// - 最终结果：'api/v1/store/list.html'

// ✅ 实际匹配流程：
// 1. 客户端调用 $this->market->request('list.html')
// 2. MarketClient 拼接路径：'api/v1/store' + '/' + 'list.html' = 'api/v1/store/list.html'
// 3. 请求 URL：https://store.wellcms.cn/api/v1/store/list.html
// 4. 服务端 processRouteParams(mode=2) 去掉 .html → api/v1/store/list
// 5. CompiledRouter::match() 成功匹配 Router::post('/list')
```

**关键认知**:
- 服务端路由定义 **永远不带** `.html` 后缀
- 客户端请求 **必须带** `.html` 后缀（符合 WellCMS URL 重写模式）
- `processRouteParams` 的 mode 2 (`/user/home/1.html`) 会自动去掉 `.html`
- 客户端 `MarketClient` 的 `apiBasePath = 'api/v1/store'`，请求路径是相对此路径的

**检查清单**:
- [ ] 服务端路由定义无 `.html` 后缀
- [ ] 客户端请求有 `.html` 后缀
- [ ] 客户端请求无额外前缀（如 `plugin/`）
- [ ] 路由匹配失败时，检查 `processRouteParams` 处理后的路径

---

## 教训 #2: 插件视图目录必须是 `views/htm/`，严禁使用 `view/`

**背景**: `TemplateManager::resolvePhysicalPath()` 第 104 行对插件模板的硬编码查找路径为：
```php
$path = $this->pluginsPath . $templateDir . '/views/htm/' . $fileName . '.htm';
```

**错误模式** (严禁):
```
// ❌ 错误：使用单数 view/ 目录
plugins/well_store_server/view/user_sites.htm

// ❌ 错误：模板放到 views/ 根目录而非 views/htm/
plugins/well_store_server/views/user_sites.htm
```

**正确模式** (必须):
```
// ✅ 插件模板必须放在 views/htm/ 下
plugins/well_store_server/views/htm/user_sites.htm
```

**关键认知**:
- 主程序视图目录是 `app/views/htm/`，插件必须遵循同样的 `views/htm/` 结构
- `render('user_sites', ..., 'well_store_server')` 会拼接为 `well_store_server/views/htm/user_sites.htm`
- 任何偏离都会导致模板解析失败（返回 404 或空白页）

**检查清单**:
- [ ] 插件模板放在 `plugins/{plugin_name}/views/htm/` 下
- [ ] 不使用单数 `view/` 目录
- [ ] 不在 `views/` 根目录直接存放 `.htm` 文件

---

## 教训 #3: 插件 Hook 文件头必须是 `<?php exit;`

**背景**: WellCMS 3.0 的 Hook 机制在编译阶段将 `Hooks/` 目录下的文件内容物理注入到主程序对应位置。`<?php exit;` 不是运行时代码，而是**编译期标记**——框架在扫描 Hook 点注释（如 `// hook app_Routes_Routes_end.php`）后，提取 Hook 文件中 `exit;` 之后的物理文本进行烧录。如果缺少此行，Hook 文件被直接 `include` 时会导致 PHP 进程异常终止。

**错误模式** (严禁):
```php
// ❌ 错误：缺少 exit; 或认为它是运行时代码而删除
<?php
Router::get('/sites', ...);

// ❌ 错误：在 exit 之后继续写可执行逻辑（exit 之后才是要烧录的内容）
<?php exit;
return []; // 这行会被烧录到主程序，可能引发语法错误
```

**正确模式** (必须):
```php
// ✅ Hook 文件标准头
<?php exit;

// --- 以下所有内容在编译阶段烧录到主程序 ---
Router::get('/sites', ...);
```

**关键认知**:
- 所有 `plugins/{plugin}/Hooks/*.php` 文件都必须以 `<?php exit;` 开头
- `exit;` 之后的代码不会被 PHP 运行时执行，而是由框架编译器提取并压平到目标位置
- 修改 Hook 文件后必须触发重扫（删除 `compile_manifest.php` 或切换 DEBUG 模式）

**检查清单**:
- [ ] 每个 Hook 文件第一行是 `<?php exit;`
- [ ] `exit;` 之后才是实际要注入的代码/文本
- [ ] 修改 Hook 后清理编译缓存

---

## 教训 #4: `update()` 增量/减量语法必须使用键名后缀（`downloads+`），严禁嵌套数组

**背景**: WellCMS Query Builder 的 `compileUpdate` 遍历 `$update` 数组的**键名**来判断是否为增量/减量操作。它检查键名的最后一个字符是否为 `+` 或 `-`，不支持 Laravel/Eloquent 风格的 `['field' => ['+=' => 1]]` 嵌套数组语法。

**错误模式** (严禁):
```php
// ❌ 错误：嵌套数组语法不被 Query Builder 识别
$this->pluginService->update(['id' => $id], ['downloads' => ['+=' => 1]]);

// ❌ 错误：paramsValueEscape 会把数组强制转成字符串 "Array"
// 生成 SQL: UPDATE store_server_plugin SET `downloads` = 'Array' WHERE `id` = ?
// 在 MySQL strict mode 下，INT 字段写入 'Array' 字符串，PDO 直接抛异常
```

**正确模式** (必须):
```php
// ✅ 增量：键名以 '+' 结尾
$this->pluginService->update(['id' => $id], ['downloads+' => 1]);
// 生成 SQL: UPDATE ... SET `downloads` = `downloads` + ? WHERE `id` = ?

// ✅ 减量：键名以 '-' 结尾
$this->pluginService->update(['id' => $id], ['stock-' => 1]);
// 生成 SQL: UPDATE ... SET `stock` = `stock` - ? WHERE `id` = ?
```

**关键认知**:
- 所有方言编译器（MySQL / PgSQL / SQLite / SqlServer）均遵循相同的键名后缀规则
- `paramsValueEscape()` 只处理 `int` 和 `string`，数组会被强转为 `"Array"` 字符串
- `bulkUpdate` 同理：行数据中使用 `'views+' => 5` 而非 `['views' => ['+=' => 5]]`
- 此错误在 `download()`、`purchase()` 等高频写操作中被反复踩中，必须形成肌肉记忆

**检查清单**:
- [ ] 所有增量/减量更新使用键名后缀语法（`field+` / `field-`）
- [ ] 绝不使用 `['field' => ['+=' => N]]` 或 `['field' => ['-=' => N]]`
- [ ] 代码审查时重点检查 `update()` 的第二个参数中是否出现嵌套数组

---

*本文档由 AGENT.md 分离而来，用于集中记录开发和维护过程中的常见陷阱。*

---

## 教训 #10: 插件控制器严禁定义构造函数，严禁声明返回类型为具体 Service 的私有 getter

**背景**: WellCMS 3.0 的 DI 容器通过 `LazyLoadingProxy` 实现 Service 的延迟加载。当控制器通过构造函数注入 Service 时，容器传递的是 `LazyLoadingProxy` 实例而非真实 Service 对象，导致 PHP `TypeError`（期望 `ConcreteService` 但收到 `LazyLoadingProxy`）。**同理，即使不在构造函数中注入，只要在控制器内部定义返回类型为具体 Service 的私有 getter（如 `private function getPageAdminService(): PageAdminService`），`$this->container->get()` 在特定场景下仍可能返回 `LazyLoadingProxy`，触发相同的 `TypeError`。**

**错误模式** (严禁):
```php
// ❌ 错误 1：构造函数注入，运行时 TypeError
class PageController extends BaseController
{
    private $pageService;

    public function __construct(
        ServerRequestInterface $request,
        // ... 11 个父类参数 ...,
        PageService $pageService    // ← 容器传入 LazyLoadingProxy，类型冲突！
    ) {
        parent::__construct(...);
        $this->pageService = $pageService;
    }
}

// ❌ 错误 2：私有 getter 声明返回类型，LazyLoadingProxy 无法通过类型检查
class PageAdminController extends BaseController
{
    private function getPageAdminService(): PageAdminService
    {
        return $this->container->get(PageAdminService::class); // ← 可能返回 LazyLoadingProxy
    }

    public function index($request): ResponseInterface
    {
        $list = $this->getPageAdminService()->getList(); // TypeError!
    }
}
```

**正确模式** (必须):
```php
// ✅ 正确：完全继承 BaseController，不定义 __construct
class PageController extends BaseController
{
    use \App\Traits\Frontend\FrontendTrait;

    public function show(ServerRequestInterface $request): ResponseInterface
    {
        // 在 Action 方法内直接内联获取 Service，不包装为私有方法
        $pageService = $this->container->get(PageService::class);
        $page = $pageService->findBySlug($slug, $namespace);
        // ...
    }
}

// ✅ 正确：后台控制器直接内联调用
class PageAdminController extends BaseController
{
    public function index($request): ResponseInterface
    {
        $list = $this->container->get(PageAdminService::class)->getList();
        // ...
    }
}
```

**关键认知**:
- `BaseController` 已通过构造函数注入 `$this->container`，子类直接使用
- `$this->container->get(ServiceClass::class)` 返回真实 Service 实例（非 Proxy）
- **但在存在循环依赖或延迟加载场景时，容器会返回 `LazyLoadingProxy`。此时若方法声明了具体 Service 的返回类型，PHP 严格类型检查会直接抛 `TypeError`**
- 参考实现：`plugins/well_forum/Controllers/Admin/PermissionAdminController.php` 直接 `$this->container->get(ForumCategoryService::class)->read(...)`，无包装方法
- Service 和 Model 层可以正常使用构造函数注入（无 LazyLoadingProxy 问题）

**检查清单**:
- [ ] 插件控制器无 `__construct` 定义
- [ ] 插件控制器**无**返回类型为具体 Service 的私有 getter 方法
- [ ] 所有插件 Service 直接在 Action 内通过 `$this->container->get()` 获取并链式调用
- [ ] Service/Model 层使用正常的构造函数注入

---

## 教训 #11: Hook 文件注释中严禁包含 `<?php` 字面量

**背景**: `Compile::sanitizeHookCode()` 在移除 `<?php exit;` 头后，会检查剩余代码中是否包含 `<?` 开头的 PHP 标签。如果注释中包含 `<?php` 字面量（如 `// 必须以 <?php exit; 开头`），安全检测会判定为残留标签，返回 `null` 并跳过该 Hook 文件（"Malformed hook file skipped"）。

**错误模式** (严禁):
```php
<?php exit;
// 所有 Hook 文件必须 <?php exit; 开头     ← 注释中的 <?php 触发安全拦截
// 路由声明对齐 CompiledRouter 兼容格式

use Framework\Http\Router\Router;
Router::get('/docs/{slug}', ...);
```

**正确模式** (必须):
```php
<?php exit;
// 所有 Hook 文件以 exit; 开头（sanitize 会安全移除）
// 注意：注释中不能包含 PHP 开始标签字面量

\Framework\Http\Router\Router::get('/docs/{slug}', ...);
```

**关键认知**:
- `sanitizeHookCode()` 的正则 `#<\?(php|=|\s)#i` 会匹配注释中的 `<?php`
- 安全检测是 AST 级别的，无法区分注释和代码
- 不要在 Hook 文件的任何位置写 `<?`（除了第一行的 `<?php exit;`）

**检查清单**:
- [ ] Hook 文件中第一行之外无 `<?php` 或 `<?` 出现
- [ ] 注释中无 PHP 标签字面量

---

## 教训 #12: Hook 文件必须使用全限定类名（FQCN），严禁 `use` 语句

**背景**: Hook 文件内容在编译阶段物理注入到主程序文件（如 `app/Routes/Routes.php`）。主程序文件已有自己的 `use` 导入（如 `use Framework\Http\Router\Router`）。Hook 文件中的 `use` 语句被直接注入后，与主程序的 `use` 重复声明，导致 PHP Fatal Error（"Cannot use ... as Router because the name is already in use"）。

**错误模式** (严禁):
```php
<?php exit;

// ❌ 错误：use 语句与核心 Routes.php 的 use 冲突
use Framework\Http\Router\Router;
use Plugins\well_page\Controllers\Frontend\PageController;

Router::get('/docs/{slug}', [PageController::class, 'show']);
```

**正确模式** (必须):
```php
<?php exit;

// ✅ 正确：全限定类名，无 use 语句
\Framework\Http\Router\Router::get('/docs/{slug}', [
    \Plugins\well_page\Controllers\Frontend\PageController::class, 'show'
]);
```

**关键认知**:
- Hook 注入是物理文本拼接，不经过 PHP 的导入重解析
- 全部使用 FQCN（以 `\` 开头的完整命名空间路径）
- 类名常量 `::class` 也需要 FQCN（`\Plugins\...\PageController::class`）
- 此规则适用于所有注入到主程序的 Hook 文件

**检查清单**:
- [ ] Hook 文件中无 `use` 语句
- [ ] 所有类引用以 `\` 开头的全限定名
- [ ] `::class` 常量也使用 FQCN

---

## 教训 #13: `BaseModel::read()` 返回单行，`BaseModel::find()` 返回多行——不可混用

**背景**: `BaseModel::read()` 内部调用 `PdoDriver::queryOne()`（`->limit(1)->fetch()`），返回**单行关联数组**（如 `['id' => 1, 'title' => '...']`）。`BaseModel::find()` 内部调用 `PdoDriver::query()`，返回**多行数组**（如 `[['id' => 1], ['id' => 2]]`）。两者返回结构不同，混用会导致 "Undefined array key 0" 或 "Trying to access array offset on value of type int"。

**错误模式** (严禁):
```php
// ❌ 错误 1：用 read() 查多行
$all = $this->model->read($condition, $orderBy);
foreach ($all as $row) { ... }          // 遍历单个关联数组的键！

// ❌ 错误 2：对 read() 结果用 [0] 取第一条
$result = $this->model->read($condition);
$page = $result[0];                     // read() 已返回单行！
```

**正确模式** (必须):
```php
// ✅ 正确：单行查询用 read()
$result = $this->model->read(['slug' => 'manual', ...]);
if (empty($result)) throw new NotFoundException();
$page = $result;                         // 直接使用，不需要 [0]

// ✅ 正确：多行查询用 find()
$items = $this->model->find($condition, $orderBy, 1, 20);
foreach ($items as $row) { ... }
```

**关键认知**:
- `read()` → `queryOne()` → 单行关联数组
- `find()` → `query()` → 多行（索引数组套关联数组）
- `count()` → `queryCount()` → 整数
- 写代码前先确认需要单行还是多行

**检查清单**:
- [ ] 单行查询使用 `read()`
- [ ] 多行查询使用 `find()`
- [ ] `read()` 结果直接使用，不用 `[0]`
- [ ] `find()` 结果用 foreach 遍历

---

## 教训 #14: `requiresUserPerm` meta 必须包含 `'enable' => true`

**背景**: `UserPermResolver::supports()` 的判定条件要求 `true === $value['enable']`。缺少 `'enable' => true` 时，`supports()` 返回 `false`，权限中间件不会被调度，路由无权限保护。

**错误模式** (严禁):
```php
// ❌ 错误：缺少 enable => true，中间件不生效
Router::group(['prefix' => '/admin', 'meta' => [
    'requiresUserPerm' => ['perm' => 'well_page.manage']
]], function () { ... });
```

**正确模式** (必须):
```php
// ✅ 正确：包含 enable => true
Router::group(['prefix' => '/admin', 'meta' => [
    'requiresUserPerm' => ['enable' => true, 'role' => ['administer', 'well_page_manage']]
]], function () { ... });
```

**关键认知**:
- `role` 数组列名对应 `well_group` 表的字段
- 自定义权限列必须在 `install.php` 中通过 `ALTER TABLE` + `GroupService::update` 创建

**检查清单**:
- [ ] 所有 `requiresUserPerm` 配置包含 `'enable' => true`
- [ ] `role` 列名在 `well_group` 表中存在

---

## 教训 #15: 插件 `config.json` 必须包含 `install`/`uninstall` 声明

**背景**: 插件系统通过 `config.json` 定位安装/卸载入口。缺少声明时，即使文件存在，框架也不会执行它。

**错误模式** (严禁):
```json
{ "name": "well_page", "version": "1.0.0" }
```

**正确模式** (必须):
```json
{ "name": "well_page", "version": "1.0.0",
  "install": {"file": "install.php"},
  "uninstall": {"file": "uninstall.php"} }
```

**检查清单**:
- [ ] 有安装逻辑的插件包含 `"install": {"file": "install.php"}`
- [ ] 有卸载逻辑的插件包含 `"uninstall": {"file": "uninstall.php"}`

---

## 教训 #16: Asset 路径必须使用相对路径，禁止绝对路径

**背景**: 资产聚合框架将 `assets` 路径视为相对于插件目录的相对路径。绝对路径会导致拼接错误，资产不加载。

**错误模式** (严禁):
```json
{ "assets": { "global": { "js": ["/plugins/well_page/static/js/page.js"] } } }
```

**正确模式** (必须):
```json
{ "assets": { "global": { "js": ["static/js/page.js"] } } }
```

**检查清单**:
- [ ] `assets` 中的路径不以 `/` 开头
- [ ] 路径相对于插件根目录

---

*最后更新: 2026-06-06 - 新增教训 #10 ~ #16（控制器注入/Hook 安全/BaseModel 语义/权限/配置/资产路径）*

---

## 教训 #17: 插件语言包审计必须同时检查 `language.php` 和 `admin.php`

**背景**: WellCMS 3.0 的 `LanguageManager` 采用严格的前后台分离加载策略：
- 后台控制器通过 `AdminTrait` 调用 `loadAdmin($locale)`，加载的是 **`admin.php`**
- 前台控制器通过 `FrontendTrait` 调用 `loadLanguage($locale)`，加载的是 **`language.php`**

`Compile::compileLanguage()` 在压平语言缓存时，按 `core → plugins → themes → hooks` 四层合并，但每层只加载对应 `$type` 的文件（`admin` 或 `language`）。**插件目录下只有 `language.php` 不等于语言包完整**——后台编译缓存中完全不会包含 `language.php` 的任何键。

**错误模式** (严禁):
```php
// ❌ 错误：审计时只看 language.php，得出"无需补全"
plugins/well_page/Language/zh/language.php  // 包含 well_page_menu ✅
plugins/well_page/Language/en/language.php  // 包含 well_page_menu ✅
// 结论：语言包完整 ← 严重错误！后台加载的是 admin.php
```

**正确模式** (必须):
```php
// ✅ 正确：审计时必须同时检查 language.php 和 admin.php
plugins/well_page/Language/zh/language.php  // 前台键
plugins/well_page/Language/zh/admin.php     // 后台键

// 对于同时被前后台调用的键（如 well_page_homepage_hero_title 在 setLanguageData_end 钩子中），
// 两个文件都必须包含该键，确保任意场景下编译缓存都有值。
```

**关键认知**:
- `language.php` 和 `admin.php` 是**物理隔离**的两个编译入口，不存在自动 fallback
- 后台 Hook（如 `app_Trait_AdminTrait_getAdminNavigation_after.php`）调用的键必须在 `admin.php` 中定义
- 前台控制器（如 `PageController::list()`）调用的键必须在 `language.php` 中定义
- 跨前后台共用的键（如首页文案通过 `setLanguageData_end` 钩子注入）**两边都必须存在**
- 14 国本土语言包必须同时更新 `language.php` 和 `admin.php`

**检查清单**:
- [ ] 审计插件语言包时，同时扫描 `Language/*/language.php` 和 `Language/*/admin.php`
- [ ] 提取插件所有 `$this->language->get('xxx')` 调用点
- [ ] 按调用场景归类：后台调用 → admin.php，前台调用 → language.php，共用 → 两边都补
- [ ] 修改后清理 `storage/tmp/langs/` 编译缓存，强制重新压平

## 教训 #5: 路由路径必须对齐方法名，禁用横杠与下划线

**背景**: 路由路径是上下游控制器同步对齐的锚点。横杠 `-` 和下划线 `_` 破坏路径与控制器方法的——映射关系，导致无法通过 URL 路径反向索引到具体方法。路由 path segment 必须使用 PascalCase 且严格对应 `ControllerClass + MethodName`。

**错误模式** (严禁):
```php
// ❌ 错误：横杠分隔，无法对应方法名
Router::post('/batch-sync', [BatchSyncController::class, 'index']);
Router::post('/post-setting', [SettingController::class, 'save']);
Router::post('/resolve-conflict', [ConflictController::class, 'resolve']);

// ❌ 错误：下划线分隔，同样无法对应
Router::post('/batch_sync', [BatchSyncController::class, 'index']);
```

**正确模式** (必须):
```php
// ✅ 正确：路由 = 控制器缩写 + Action 方法名，PascalCase 拼接
// BatchSyncController::index() → /BatchSync
Router::get('/BatchSync', [BatchSyncController::class, 'index']);

// SettingController::save() → /PostSetting（POST 语义合并入路径）
Router::post('/PostSetting', [SettingController::class, 'save']);

// ConflictController::resolve() → /ResolveConflict（动词+名词）
Router::post('/ResolveConflict', [ConflictController::class, 'resolve']);
```

**关键认知**:
- 路由路径 = "见路径即知方法"：看到 `/BatchSync` 立即定位 `BatchSyncController::index()`
- 横杠和下划线在 URL 编码、文件系统、grep 搜索中均与 PascalCase 方法名不一致
- 上下游多站点同步时，路径即为方法签名，分隔符差异导致匹配断裂

**检查清单**:
- [ ] 所有路由 path 中不含 `-` 或 `_`
- [ ] Path segment 均为 PascalCase（首字母大写驼峰）
- [ ] 路径与 `ControllerClass + MethodName` 可——映射
- [ ] 同一控制器的多个 Action，路径前缀保持一致（如 `BatchSync`、`BatchSyncList`、`PauseBatchSync`）

---

## 教训 #6: `<form>` 内禁止使用 `name="action"`——会遮蔽 `form.action` 属性

**背景**: HTML DOM 规范中，表单元素可通过 `form.fieldName` 直接访问其命名子元素。当 `<input name="action">` 存在时，`form.action` 返回该 input 元素而非表单的 `action` URL 字符串。JavaScript `ajax-form` 处理器读取 `form.action` 时获得 `[object HTMLInputElement]`，导致路由匹配失败。

**错误模式** (严禁):
```html
<!-- ❌ 错误：name="action" 遮蔽了 form.action -->
<form class="ajax-form" action="/admin/MultiSite/PostSetting">
    <input type="hidden" name="action" value="save_node">
</form>
```
JS 取 `form.action` → `[object HTMLInputElement]` → URL 变成 `/admin/MultiSite/[object HTMLInputElement]`

**正确模式** (必须):
```html
<!-- ✅ 正确：使用 _action 或其他不冲突的名字 -->
<form class="ajax-form" action="/admin/MultiSite/PostSetting">
    <input type="hidden" name="_action" value="save_node">
</form>
```
```php
// 控制器同步修改
$action = RequestUtils::param('_action', '');
```

**禁用字段名（会遮蔽原生属性）**:
| 字段名 | 遮蔽属性 | 后果 |
|--------|----------|------|
| `action` | `form.action` | URL 变成 `[object HTMLInputElement]` |
| `method` | `form.method` | GET/POST 判断失效 |
| `enctype` | `form.enctype` | 编码类型错乱 |
| `submit` | `form.submit()` | 提交方法被覆盖 |
| `reset` | `form.reset()` | 重置方法被覆盖 |

**检查清单**:
- [ ] 所有 `<form>` 内无 `name="action"` 的 input
- [ ] 使用 `_action` 作为动作标识字段名
- [ ] 控制器 `RequestUtils::param()` 读取的字段名与模板一致

---

## 教训 #7: CSRF 必须由路由中间件校验，严禁控制器手动验证

**背景**: WellCMS 3.0 的 `CsrfMiddleware` 在路由层自动拦截 `_csrf_token` 并完成比对。控制器内手动调用 `verifyCsrfToken()` 是重复校验，且容易因忘记调用导致安全漏洞。**所有 POST 路由必须声明 `requiresCsrf`，控制器零 CSRF 代码。**

**错误模式** (严禁):
```php
// ❌ 错误 1：路由缺少 requiresCsrf 声明
Router::post('/PostSetting', [SettingController::class, 'save']);

// ❌ 错误 2：控制器内手动验证 CSRF
public function save($request): ResponseInterface
{
    $csrfToken = RequestUtils::param('_csrf_token');
    if (!$this->verifyCsrfToken($csrfToken, $user['salt'] ?? '')) {
        return $this->errorMessage('illegal_operation', 1);
    }
    // ...
}
```

**正确模式** (必须):
```php
// ✅ 路由声明 requiresCsrf
Router::post('/PostSetting', [SettingController::class, 'save'], [
    'requiresCsrf' => ['enable' => true, 'ttl' => 3600]
]);

// ✅ 控制器零 CSRF 代码，中间件自动校验
public function save($request): ResponseInterface
{
    $action = RequestUtils::param('_action', '');
    // 直接处理业务逻辑，无需任何 CSRF 验证代码
}
```

**关键认知**:
- 安全链路：路由声明 `requiresCsrf` → `CsrfMiddleware` 自动校验 → 控制器零代码
- GET 渲染时传 token（`'csrf_token' => $this->getCsrfToken($user['salt'])`），模板携带 `_csrf_token`
- 控制器内 **严禁** 出现 `verifyCsrfToken()` 调用

**检查清单**:
- [ ] 所有 POST 路由声明了 `'requiresCsrf' => ['enable' => true, 'ttl' => 3600]`
- [ ] 控制器内无 `verifyCsrfToken()` 调用
- [ ] 控制器内无 `RequestUtils::param('_csrf_token')` 手动读取
- [ ] 模板表单/AJAX 中正确携带 `_csrf_token` 字段

---

## 教训 #8: `$view->e()` 仅用于 `$view` 数据，foreach 变量必须用 `htmlspecialchars()`

**背景**: `$view->e('key')` 是 `$view` 对象的方法，只能访问控制器通过 `$this->render($layout, $data, ...)` 传入的 `$data` 数组。foreach 循环中的 `$node` 是普通 PHP 变量，不在 `$view` 的存储中，`$view->e($node['field'])` 返回空字符串。

**错误模式** (严禁):
```php
// ❌ 错误：$node 是 foreach 循环变量，$view->e() 取不到值
<?php foreach ($nodes as $node): ?>
    <span><?php echo $view->e($node['site_id']);?></span>
    <span><?php echo $view->e($node['api_endpoint']);?></span>
<?php endforeach; ?>
```

**正确模式** (必须):
```php
// ✅ 正确：$view->e() 用于 $view 数据
<?php echo $view->e('csrf_token');?>

// ✅ 正确：foreach 变量用 htmlspecialchars()
<?php foreach ($nodes as $node): ?>
    <span><?php echo htmlspecialchars($node['site_id']);?></span>
    <span><?php echo htmlspecialchars($node['api_endpoint']);?></span>
    <span data-id="<?php echo (int)$node['id'];?>"></span>
<?php endforeach; ?>
```

**适用对照表**:

| 数据来源 | 输出方法 | 示例 |
|----------|----------|------|
| `$view->get('key')` | `$view->e('key')` | `$view->e('csrf_token')` |
| `$view->get('a.b')` 嵌套 | `$view->e('a.b')` | `$view->e('website.title')` |
| `foreach` 循环变量 | `htmlspecialchars($v)` | `htmlspecialchars($node['name'])` |
| 整型/布尔用于属性 | `(int)` / `(bool)` | `(int)$node['id']` |

**检查清单**:
- [ ] `$view->e()` 的参数是字符串 key，不是 PHP 变量
- [ ] foreach 内无 `$view->e($loopVar[...])` 调用
- [ ] 循环变量输出使用 `htmlspecialchars()` 或 `(int)` 强转

---

## 教训 #9: 严禁使用 `$request->getAttributes()['key']`，必须使用 `$request->getAttribute('key')`

**背景**: WellCMS 3.0 的 `ServerRequestInterface` 同时提供了 `getAttributes(): array`（返回全部属性数组）和 `getAttribute(string $name, $default = null)`（按名读取单个属性）。在控制器中通过数组下标访问属性（`$request->getAttributes()['user']`）不仅语法冗余，还会触发铁律 #25 的 `??` 兜底问题（`$request->getAttributes()['user'] ?? []`）。PSR-7 标准已提供原子方法，应直接调用。

**错误模式** (严禁):
```php
// ❌ 错误：先取全量数组再下标访问，冗长且易触发 ?? 兜底
$user = $request->getAttribute('user', []);
$routeMeta = $request->getAttributes()['_route_meta'] ?? ['layout' => 'default'];
```

**正确模式** (必须):
```php
// ✅ 正确：直接使用 getAttribute 原子方法
$user = $request->getAttribute('user');
$routeMeta = $request->getAttribute('_route_meta');
```

**关键认知**:
- `getAttribute('key')` 是 PSR-7 标准方法，语义清晰、零冗余
- 路由中间件（如 `requiresAuth`、`requiresAdminSignIn`）已保证属性存在，无需兜底
-  eliminates the temptation to write `??` fallbacks after array access
- 与 IDE 类型推断和静态分析工具兼容性更好

**检查清单**:
- [ ] 控制器内无 `$request->getAttributes()['xxx']` 写法
- [ ] 统一使用 `$request->getAttribute('xxx')`
- [ ] 不因为 "保险" 而给 `getAttribute()` 追加 `??` 或 `?:` 兜底

---

## 反合理化表 (Anti-Rationalization Tables)

以下表格列出 WellCMS 开发中常见的"自我合理化"借口，以及对应的反驳和真实后果。代码审查和自检时逐一对照。

### 路由与模板

| 合理化借口 | 反驳 | 真实后果 |
|-----------|------|---------|
| "路径加个横杠更可读" | Iron Law #24 强制路径对齐方法名，横杠破坏一一映射 | 下游站点无法通过 URL 反向索引到控制器方法，多站点同步断裂 |
| "就一个模板，放 views/ 根目录行了" | `TemplateManager` 硬编码查找路径为 `views/htm/` | 模板 404，页面白屏，排查耗时 30 分钟以上 |
| "这里用 `<?php exit;` 太麻烦，删掉" | Hook 文件缺少头标记，编译阶段直接跳过该文件 | Hook 不生效，功能缺失且无报错——最隐蔽的故障模式 |
| "先写代码，路由 meta 等会儿再补" | 缺少 `requiresCsrf` 的 POST 路由无 CSRF 保护 | 安全漏洞直接暴露，XSS/CSRF 可被利用 |
| "模板里用 `name='action'` 没问题" | `form.action` 被遮蔽为 HTMLInputElement 对象 | ajax-form 提交 URL 变成 `[object HTMLInputElement]`，请求失败 |

### 数据库与模型

| 合理化借口 | 反驳 | 真实后果 |
|-----------|------|---------|
| "数据量小，用 OFFSET 分页没关系" | 铁律 #20 强制游标分页，OFFSET 在千万级必然性能崩塌 | 随着数据增长，OFFSET 越翻越慢，前端超时，DBA 深夜被叫醒 |
| "就这一次，手动查一下 COUNT(*)" | 铁律 #16 严禁实时 COUNT，必须维护冗余统计字段 | 大表 `COUNT(*)` 全表扫描，高峰期拖垮数据库 |
| "这里用 JOIN 更快，就两行" | 铁律 #17 多表关联必须在 PHP 层逐表查询合并 | 索引失效 + 死锁风险，协程环境下连接池耗尽 |
| "array_diff 返回的结果直接传进 where" | 铁律 #23：非连续键数组被 `isset($v[0])` 误判为关联数组 | WHERE IN 拼成 `WHERE id IN ('1=>20, 2=>30')`，SQL 语法错误 |
| "read() 和 find() 差不多，能跑就行" | `read()` 返回单行关联数组，`find()` 返回多行索引数组 | `foreach($readResult as $row)` 遍历的是单行的字段键而不是多行数据 |
| "update 增量用数组嵌套也行吧" | 键名后缀语法 `field+` / `field-` 是唯一支持的增量语法 | `$update['downloads' => ['+=' => 1]]` 里的数组被 `paramsValueEscape` 转成字符串 `"Array"`，MySQL strict mode 抛异常 |

### Hook 与插件

| 合理化借口 | 反驳 | 真实后果 |
|-----------|------|---------|
| "就一个 use 语句，不会有冲突" | Hook 内容物理注入到主程序，重复 `use` 导致 PHP Fatal Error | 全站 500，需删除编译缓存重新压平才能恢复 |
| "注释里写 `<?php` 没什么影响" | `sanitizeHookCode()` 的正则检测 AST 级别，注释中的 `<?php` 也会被拦截 | Hook 文件被跳过，功能静默不生效 |
| "插件控制器定义一个构造函数也没事" | BaseController 的构造函数有 12+ 个参数，子类签名难对齐 | TypeError / LazyLoadingProxy 冲突，页面白屏 |
| "config.json 缺少 install 声明，但文件在" | 框架通过 config.json 定位安装入口，不读取文件是否存在 | `install.php` 永远不会被执行，插件安装流程断裂 |
| "语言包只更新 language.php 就行" | `admin.php` 和 `language.php` 是物理隔离的两个编译入口 | 后台页面语言键全部缺失，显示空白或报错 |
| "assets 路径用绝对路径更保险" | 资产聚合框架将 assets 路径视为插件目录的相对路径 | JS/CSS 加载路径拼接错误，资源 404 |

### 安全与架构

| 合理化借口 | 反驳 | 真实后果 |
|-----------|------|---------|
| "控制器里手动 verifyCsrfToken() 双重保险" | 铁律 #8 禁止控制器内手动 CSRF 验证，路由中间件已统一拦截 | 重复校验逻辑、代码冗余，忘记调用时形成安全漏洞 |
| "就这次用 `??` 兜个底，不会出问题" | 铁律 #25 零兜底严格模式——不存在时应让错误自然暴露 | 静默吞掉错误，数据不一致，排查时毫无线索 |
| "私有 getter 加个返回类型声明更清晰" | 容器热路径可能返回 LazyLoadingProxy，类型声明触发 TypeError | 控制器方法调用全部崩溃，且故障间歇性复现难以定位 |
| "在控制器里直接硬编码模板名更直观" | 铁律 #14 模板名必须从路由元数据读取 | 路由与模板解耦失败，模板路径变更需全局搜索替换 |
| "跨插件直接调用 Model 更方便" | 铁律 #15 跨插件必须通过 Service 层 | 插件升级时内部 Model 变更导致调用方断裂，插件耦合失控 |

### 代码审查与调试

| 合理化借口 | 反驳 | 真实后果 |
|-----------|------|---------|
| "这个改动很小，不需要审查" | 80% 的生产事故来自"小改动" | 一行代码可能导致整站不可用，且 blame 追溯成本极高 |
| "先合并，bug 后面修" | 技术债复利累积，修复成本随延迟指数增长 | 未修复的 bug 在后续 3 个迭代中造成连锁故障 |
| "测试报错是环境问题，不是代码问题" | 没有排查就归因环境，是最大的调试反模式 | 真正的代码 bug 被忽视，推广到生产环境后爆发 |
| "这个 flaky test 先 skip，不影响功能" | Flaky test 是真实 bug 的掩体 | 被跳过的测试在代码重构后无人察觉，回归漏洞带入生产 |
| "线上问题，先 hotfix 再说原因" | 不查根因的 hotfix 只是贴膏药 | 同一问题以不同表象反复出现，每次 hotfix 增加技术债 |

---

*反合理化表最后更新: 2026-06-15 - 从 agent-skills 工程流程规范汲取结构模式，适配 WellCMS 专有约束*
