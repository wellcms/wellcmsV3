# WellCMS 3.0 系统架构与开发模式 (Architecture Patterns)

## 1. 控制器模式与数据流闭环 (Controller & Data Flow)
*   **执行链**：`Router -> Middleware -> Controller -> Service -> Model -> Database`。
*   **业务下沉**：控制器仅负责参数校验与响应分发，业务逻辑必须下沉到 **Service 层**。
*   **数据映射**：Model 仅负责单表 CRUD。**严禁在 Service 或 Model 编写跨表 JOIN**。必须在 PHP 逻辑层进行单表结果合并。
*   **Action 签名绝对标准**：所有 public Action 必须声明为 `public function actionName(\Framework\Http\Interfaces\ServerRequestInterface $request): \Framework\Http\Interfaces\ResponseInterface`。严禁省略 `$request` 参数或返回类型。后台控制器 `use AdminTrait;`，前台控制器 `use FrontendTrait;`，API 控制器不使用 Trait。
*   **模板名路由驱动**：控制器中**严禁硬编码模板文件名**。模板名必须从路由元数据读取：`$layout = $request->getAttributes()['_route_meta']['layout'] ?? '默认模板名';`，再传入 `$this->render($layout, $data, '插件目录名')`。`render()` 第三个参数传**插件目录名字符串**（如 `'well_forum'`），严禁传 `true` 或第四个 `$id` 参数。
*   **JSON 响应绝对委托**：主程序 `BaseController` 已注入 `ResponseFormatter`（`$this->responseFormatter`）。插件控制器**严禁**定义任何 `jsonResponse()`、`apiResponse()` 等包装/委托方法。必须直接在 Action 中调用 `$this->responseFormatter->jsonResponseFormat(array $data)` 返回 PSR-7 Response。标准 API 结构 `{status, code, message, data, timestamp}` 在调用点显式组装，`status` 取值仅为 `'success'` 或 `'error'`，严禁使用布尔型 `success`；禁止通过 Trait、BaseController 子类或任何中间层二次封装。

## 1.1 控制器 render() 数据合约 (Controller Data Contract)

所有后台/前台控制器在调用 `$this->render()` 时，必须按以下标准构造 `$data` 数组。遗漏字段将导致模板渲染异常（菜单空白、CSRF 校验失败、标签缺失）。

### 后台控制器（`use AdminTrait`）

参考实现：`plugins/well_forum/Controllers/Admin/CategoryAdminController.php`

```php
public function index(\Framework\Http\Interfaces\ServerRequestInterface $request): ResponseInterface
{
    $user = $request->getAttribute('user', []);  // 当前用户
    $extra = [];

    $csrfToken = $this->getCsrfToken($user['salt']);  // CSRF 令牌
    $menu = $this->getAdminMenu();                     // 侧边栏菜单

    // ... 业务逻辑获取数据 ...

    $pageLinkString = 'admin/xxx/yyy';                 // 当前路由名
    $data = [
        // 1. 页面元数据（必须）
        'header' => [
            'title'       => $this->language->get('page_title_key'),
            'keywords'    => $this->language->get('page_title_key'),
            'description' => $this->language->get('page_title_key'),
        ],

        // 2. 导航与菜单（必须）
        'menu'      => $menu,
        'menu_fixed' => ['parent' => 'menu_parent_key', 'child' => 'menu_child_key'],

        // 3. 安全令牌（必须，表单用）
        'csrf_token' => $csrfToken,

        // 4. 分页链接（必须）
        'extra'           => $extra,
        'page_link'       => $this->urlGenerator->url($pageLinkString, $extra),
        'page_link_string' => $pageLinkString,

        // 5. 表单 action（POST 表单页面必须）
        'action' => $this->urlGenerator->url('admin/xxx/postAction'),

        // 6. 面包屑（二级以上页面推荐）
        'breadcrumb' => [
            'home'  => ['name' => $this->language->get('home_page'), 'url' => $this->urlGenerator->url('admin/panel')],
            'list'  => ['name' => $this->language->get('parent_page'), 'url' => $this->urlGenerator->url('admin/xxx/list')],
            'title' => ['name' => $this->language->get('current_page'), 'url' => $this->urlGenerator->url($pageLinkString, $extra)],
        ],

        // 7. 模板中使用的语言键（必须，通过 $view->get('language.xxx') 访问）
        'language' => [
            'key1' => $this->language->get('key1'),
            'key2' => $this->language->get('key2'),
        ],

        // 8. 业务数据
        'items' => $items,
    ];

    // 安全读取路由元数据中的模板名
    $routeMeta = $request->getAttributes()['_route_meta'] ?? ['layout' => 'default_template'];
    return $this->render($routeMeta['layout'], $data, '插件目录名');
}
```

**字段说明：**

| 字段 | 后台 | 要求 | 说明 |
|------|------|------|------|
| `header` | 必须 | `title`,`keywords`,`description` 三个子键 | HTML `<title>` 与 `<meta>` 标签 |
| `menu` | 必须 | `$this->getAdminMenu()` 返回值 | 侧边栏导航渲染 |
| `menu_fixed` | 必须 | `parent` + `child` | 当前菜单项高亮 |
| `csrf_token` | 必须 | `$this->getCsrfToken($user['salt'])` | 表单 CSRF 防护 |
| `extra` | 必须 | 初始 `[]` | 分页参数透传 |
| `page_link` | 必须 | `$this->urlGenerator->url()` | 分页链接 |
| `page_link_string` | 必须 | 路由名 | 分页路由标识 |
| `breadcrumb` | 推荐 | 导航层级 | 面包屑导航 |
| `action` | POST 页面必须 | `$this->urlGenerator->url()` | 表单提交地址 |
| `language` | 必须 | 视图用到的所有语言键 | 模板中 `$view->get('language.*')` 用 |

### 前台控制器（`use FrontendTrait`）

参考实现：`app/Controllers/Frontend/MyController.php`

```php
public function action(\Framework\Http\Interfaces\ServerRequestInterface $request): ResponseInterface
{
    $user = $request->getAttribute('user', []);
    $extra = [];

    $navigation = $this->getNavigation();  // 顶部导航栏（FrontendTrait 提供）

    // ... 业务逻辑 ...

    $pageLinkString = 'my/xxx';
    $data = [
        'header' => [
            'title'       => $this->language->get('page_title_key'),
            'keywords'    => $this->language->get('page_title_key'),
            'description' => $this->language->get('page_title_key'),
        ],
        'extra'           => $extra,
        'navigation'      => $navigation,        // 前台顶部导航（FrontendTrait::getNavigation()）
        'menu'            => $this->myMenu($user), // 个人中心侧栏（自定义方法）
        'menu_fixed'      => ['parent' => '...', 'child' => '...'],
        'csrf_token'      => $this->getCsrfToken($user['salt']), // 表单页面需要
        'page_link'       => $this->urlGenerator->url($pageLinkString),
        'page_link_string' => $pageLinkString,
        'breadcrumb'      => [...],              // 推荐
        'action'          => $this->urlGenerator->url('my/postAction'), // POST 页面需要
        'language'        => [
            'key1' => $this->language->get('key1'),
        ],
    ];

    $routeMeta = $request->getAttributes()['_route_meta'] ?? ['layout' => 'default_template'];
    return $this->render($routeMeta['layout'], $data, false);  // 前台传 false
}
```

**前后台关键区别：**

| 项目 | 后台 (AdminTrait) | 前台 (FrontendTrait) |
|------|-------------------|---------------------|
| 菜单来源 | `$this->getAdminMenu()` | `$this->getNavigation()` + 自定义 `myMenu()` |
| `render()` 第3参 | 插件目录名字符串 `'well_xxx'` | `false` |
| `menu_fixed` | 控制侧边栏高亮 | 控制个人中心或主导航高亮 |
| `header` 作用 | `<title>` + meta 标签（均需设置） | 同上 |

## 2. 依赖注入与构造铁律 (DI & Constructor Law)
*   **禁止直连**：控制器、Service 严禁直接 `new` 实例化其他 Service/Model，必须由容器注入。
*   **构造函数复用铁律**：严禁在子控制器中无目的地覆盖 `__construct`。必须优先复用 `BaseController` 中已注入的服务。
    *   若确需定义，必须首先执行 `parent::__construct(...)`。
*   **Service 构造函数单参数铁律**：所有 Service 类的 `__construct` 必须**只接受 `\Framework\Core\Container $container`**，禁止逐个声明依赖的类型提示参数。内部依赖在构造函数体内通过 `$this->container->get()` 统一获取：
    ```php
    use Framework\Core\Traits\StatefulTrait;

    class ForumAccessService
    {
        use StatefulTrait;

        /** @var \Framework\Core\Container */
        protected $container;

        /** @var \Plugins\well_forum\Models\ForumAccessModel */
        protected $dbModel;

        public function __construct(\Framework\Core\Container $container)
        {
            $this->container = $container;
            $this->dbModel = $container->get(\Plugins\well_forum\Models\ForumAccessModel::class);
            // ...更多依赖通过 $container->get() 获取
        }
    }
    ```
    **理由**：插件 Service 注册到容器后使用 LazyLoadingProxy 代理，显式类型声明会导致 `Framework\Core\LazyLoadingProxy` 与具体类型不匹配而报错。只接受 `Container` 统一入口可避免此问题。

### 2.1 容器自动装配支持继承构造函数
容器的反射解析（`ReflectionClass::getConstructor()`）**会自动读取继承的构造函数**。因此：
*   Model 继承 `BaseModel` 后，**无需**在子类中重复声明 `__construct(DatabaseInterface $db)`。
*   `ModelServiceProvider` 通过 `$container->bind($model, $model, true, false)` 注册时，容器会自动解析 `BaseModel::__construct(DatabaseInterface $db)` 并完成注入。
*   **禁止**在仅继承 BaseModel 的标准 Model 中冗余定义构造函数，保持类体仅含 `protected $table` 和必要的 `find()` / `maxid()` 默认值覆盖。

## 3. 分布式原子锁规约 (Distributed Locking)
当操作敏感金额、配额或热点缓存重建时，必须在 Service 层引入原子锁。

*   **调用规范**：
    1.  **范围最小化**：仅锁定变更的核心临界区。
    2.  **超时释放**：必须设置合理的 TTL 预防死锁。
    3.  **标准 API**：使用 `CacheInterface::lock(key, ttl)` 或框架封装的 `cacheWithLock()`。

## 4. 物理命名隔离 (Naming Isolation)
*   **表名规范**：插件表必须带前缀 `well_插件名_业务名`。
*   **冲突规避**：严禁占用 `well_core_` 或系统核心表名空间。
*   **字段隔离**：向主程序表添加字段时，建议带插件缩写（如 `plugin_xxx`）。

## 5. 参考实现 (Reference Implementation)
*   **基础控制器 (BaseController) 标准**：[BaseController.php](/app/Controllers/Base/BaseController.php)
*   **容器注册标准驱动**：[ModelServiceProvider.php](/app/Providers/ModelServiceProvider.php)
*   **Service 构造函数规范示例**：[ForumAccessService.php](/plugins/well_forum/Services/ForumAccessService.php) —— 只接受 `Container $container`，内部 `$container->get()` 获取依赖。
*   **后台控制器实现标准**：[CategoryAdminController.php](/plugins/well_forum/Controllers/Admin/CategoryAdminController.php) —— AdminTrait + render() 完整数据合约。
*   **前台控制器实现标准**：[MyController.php](/app/Controllers/Frontend/MyController.php) —— FrontendTrait + render() 完整数据合约。
