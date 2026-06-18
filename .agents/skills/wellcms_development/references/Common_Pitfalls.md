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
// 生成 SQL: UPDATE store_server_plugin SET `downloads` = `downloads` + ? WHERE `id` = ?

// ✅ 减量：键名以 '-' 结尾
$this->pluginService->update(['id' => $id], ['stock-' => 1]);
// 生成 SQL: UPDATE store_server_plugin SET `stock` = `stock` - ? WHERE `id` = ?
```

**关键认知**:
- 所有方言编译器（MySQL / PgSQL / SQLite / SqlServer）均遵循相同的键名后缀规则
- `paramsValueEscape()` 只处理 `int` 和 `string`，数组会被强转为 `"Array"` 字符串
- `bulkUpdate` 同理：行数据中使用 `'views+' => 5` 而非嵌套数组
- 此错误在 `download()`、`purchase()` 等高频写操作中被反复踩中，必须形成肌肉记忆

**检查清单**:
- [ ] 所有增量/减量更新使用键名后缀语法（`field+` / `field-`）
- [ ] 绝不使用 `['field' => ['+=' => N]]` 或 `['field' => ['-=' => N]]`
- [ ] 代码审查时重点检查 `update()` 的第二个参数中是否出现嵌套数组

---

*本文档由 AGENT.md 分离而来，用于集中记录开发和维护过程中的常见陷阱。*

---

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
