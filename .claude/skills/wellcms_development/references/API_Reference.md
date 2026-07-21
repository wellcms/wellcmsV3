# WellCMS 3.0 核心 API 速查表 (API Quick Reference)

## 1. 数据库操作 ($this->db)
所有 Service 均可注入 `Framework\Database\Interfaces\DatabaseInterface`。

- **查单条**: `$db->queryOne($table, array $where)`
- **查多条**: `$db->query($table, array $where, array $orderBy = [], int $page = 1, int $pageSize = 20, string $key = '', array $fields = ['*'])`
- **计数**: `$db->count($table, array $where)`
- **插入**: `$db->insert($table, array $data)`
- **更新**: `$db->update($table, array $where, array $data)`
- **删除**: `$db->delete($table, array $where)`

## 2. 路由与跳转
- **生成 URL**: `$this->urlGenerator->url('action/path', ['id' => 1])`
- **中间件内重定向**: 
```php
$responseFactory = $this->container->get(ResponseFactoryInterface::class);
return $responseFactory->createResponse(302)->withHeader('Location', $urlGenerator->url('action/path', ['id' => 1]));
```

- **控制器内重定向**: 
 * 成功跳转重定向
```php
return $this->successMessage($this->language->get('updated_successfully'), 0, $url, 3);
```
 * 失败跳转重定向
```php
return $this->errorMessage($this->language->get('updated_failed'), 0, $url, 5);
```

## 3. 用户与权限
- **获取当前用户**: `$request->getAttribute('user')` (从中间件注入)
- **校验权限**: 
`$container->get(GroupService::class)->access($gid, 'action_name')`

- **校验权限**: 
`$container->get(GroupService::class)->access($gid, 'action_name')`

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
- **前台(Frontend) HTML 渲染**: `$this->render($template, $data, false)`
- **后台(Backend) HTML 渲染**: `$this->render($template, $data, true)`
- **JSON 成功**: `return $this->successMessage($msg, $code, $url)`
- **JSON 失败**: `return $this->errorMessage($msg, $code)`

## 5. 缓存操作 (CacheInterface)
- **取值**: `$cache->get($key)`
- **存值**: `$cache->set($key, $value, $ttl)`
- **删除**: `$cache->delete($key)`

## 6. 核心常量
- `APP_PATH`: 物理根目录（末尾含 /）
- `DEBUG`: 0 生产, 1 调试, 2 开发
- `IN_WELLCMS`: 安全验证常量
