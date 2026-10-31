# WellCMS 3.0 核心 API 速查表 (API Quick Reference)

## 1. 数据库操作 ($this->db)
以下是底层数据库接口速查。Model/原子 Service 按既有职责访问；复合 Service 通过原子 Service 协作，不因本表绕过核心第 17 条直查数据库。

- **查单条**: `$db->queryOne($table, array $where)`
- **查多条**: `$db->query($table, array $where, array $orderBy = [], int $page = 1, int $pageSize = 20, string $key = '', array $fields = ['*'])`
- **计数**: `$db->count($table, array $where)`
- **插入**: `$db->insert($table, array $data)`
- **更新**: `$db->update($table, array $where, array $data)`
- **删除**: `$db->delete($table, array $where)`

## 2. 路由与跳转

遵循[核心第24条](../SKILL.md)：路由小驼峰、类名大驼峰；固定路由禁止 `-`、`_`，路由不要求与控制器类名同名，按路由声明绑定实际Action。例如 `collection/create` 可注册到 `CollectionController::create`，此例不构成类名映射约束。
- **生成 URL**: `$this->urlGenerator->url('collection/create')`
- **中间件内重定向**: 
```php
$responseFactory = $this->container->get(ResponseFactoryInterface::class);
return $responseFactory->createResponse(302)->withHeader('Location', $urlGenerator->url('collection/create'));
```

- **控制器内重定向**: 
 * 成功跳转重定向
```php
return $this->successMessage($this->language->get('updated_successfully'), 0, $url, 3);
```
 * 失败跳转重定向
```php
return $this->errorMessage($this->language->get('updated_failed'), -1, $url, 5);
```

### 错误响应码合同

成功码为 `0`；失败不可使用 `0`。失败直接调用 `errorMessage()`，保留数值或字符串类型，不新增响应包装。

| 范围 | code | 含义 |
|------|------|------|
| 底层失败 | -1 | Service / 数据库操作失败 |
| 底层失败 | -2 | 缓存操作失败 |
| 底层失败 | -3 | 外部 API 接口返回错误 |
| 业务错误 | 1 | 传参数错误 |
| 业务错误 | 2 | 逻辑或判断参数错误 |
| 业务错误 | 3 | 方法返回错误 |
| 业务错误 | 4 | GET 参数不存在 |
| 业务错误 | 5 | GET 参数错误 |
| 业务错误 | 6 | POST 参数不存在 |
| 业务错误 | 7 | POST 参数错误 |
| 业务错误 | 8 | 验证数据错误 |
| 业务错误 | 9 | 功能未开启 |
| 业务错误 | 10 | 目录或文件不存在 |
| 业务错误 | 11 | 未配置参数或参数错误 |
| 业务错误 | 12 | 请求返回错误 |
| 业务错误 | 13 | 升级失败 |
| 业务错误 | 14 | 保存文件失败 |
| 业务错误 | 15 | 权限不足 |
| 业务错误 | 16 | 禁止操作 |
| 表单字段错误 | string | 返回错误表单的 `name`，供交互层定位字段 |

## 3. 用户与权限
- **获取当前用户**: `$request->getAttribute('user')` (从中间件注入)
- **路由权限**：使用 `requiresUserPerm => ['enable' => true, 'role' => ['administer']]`，由中间件调用 `GroupService::access()`；控制器不重复判断。服务层已有资源权限判断按实际业务合同保留，不能把路由固定权限和资源归属混为一谈。

## 4. 状态与会话管理 (State & Session)

### 4.1 StatefulTrait (Service 级隔离)
在继承了 `StatefulTrait` 的 Service 中使用：
- **存入状态**: `$this->setState('key', $value)`
- **读取状态**: `$this->getState('key', $default = null)`
- **清理状态**: `$this->clearStates()` (通常由框架自动触发)

### 4.2 SessionInterface (会话)
通过构造函数注入或 `captureContext` 获取：
- **读取**: `$session->get('user_id', 0)`
- **写入**: `$session->set('last_active', time())`
- **删除**: `$session->delete('captcha')`
- **标识**: `$session->getId()`

## 5. 响应格式处理 ($this->response)
- **插件前台 HTML 渲染**：从当前路由 `_route_meta` 读取模板，调用 `$this->render($layout, $data, '实际插件目录名')`；控制器使用 `FrontendTrait`。
- **插件后台 HTML 渲染**：同样从 `_route_meta` 读取模板并传插件目录名字符串，控制器使用 `AdminTrait`。当前论坛前后台均传 `'well_forum'`。
- **主程序内部模板**：`BaseController::render()` 仍支持第三参数 `false/true` 选择主程序模板目录；这是宿主内部用法，不能作为插件控制器的渲染示例。插件调用遵循核心第14条，不传布尔第三参数或无实际主题继承需求的第四参数。
- **JSON 成功**: `return $this->successMessage($msg, $code, $url)`
- **JSON 失败**: `return $this->errorMessage($msg, $code)`

## 6. 缓存操作 (CacheInterface)

缓存与业务锁由主程序 `CacheManager` 管理，插件通过容器取得统一接口：

```php
$cache = $container->get(\Framework\Cache\Interfaces\CacheInterface::class);
```

支持主程序提供的 MySQL 缓存实现，不要求用户配置 Redis；禁止直接调用 Redis 或另建缓存链路。

- **取值**: `$cache->get($key)`
- **存值**: `$cache->set($key, $value, $ttl)`
- **删除缓存**: `$cache->delete($key)`
- **业务锁**: `$cache->lock($key, $ttl)` / `$cache->unlock($key, $token)`

上述接口只处理缓存与锁，不能代替下面的 KV 配置接口。

## 7. KV 配置 (KeyValueService)

持久配置复用主程序 `App\Services\System\KeyValueService`，插件以自己的 `well_***` 键读写和删除：

```php
$kv = $container->get(\App\Services\System\KeyValueService::class);
```

| 用途 | 原生接口示例 |
|---|---|
| 读取插件配置 | `$kv->settingGet('well_example')` |
| 保存插件配置 | `$kv->settingSet('well_example', $settings)` |
| 删除插件配置 | `$kv->settingDelete('well_example')` |

`well_example` 应替换为实际插件键；保存、删除的布尔结果沿调用方原错误合同处理。删除仅用于明确的配置删除或卸载动作，不用于刷新读取。禁止直接通过 `CacheManager`／`CacheInterface` 设置或清理这些配置，也不得直接处理共享 `setting` 缓存键。`clearStates()` 只清当前服务的内存状态，不是删除 KV 配置；不能代替 `settingDelete()`。主程序 KV 服务内部如何实现缓存不属于插件重写范围。

## 8. 核心常量
- `APP_PATH`: 物理根目录（末尾含 /）
- `DEBUG`: 0 生产, 1 调试, 2 开发
- `IN_WELLCMS`: 安全验证常量
