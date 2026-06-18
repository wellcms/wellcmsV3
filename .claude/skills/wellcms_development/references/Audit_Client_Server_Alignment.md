# 客户端-服务端全链路对齐审计

> **铁律**: 服务端定义协议，客户端100%精准消费。禁止任何兜底、双写、兼容旧字段、自动纠错行为。

---

## 审计项

### A. 路由对齐

客户端发起的 HTTP 请求路径必须精确匹配服务端路由定义 + `.html` 后缀规则。

| # | 检查项 | 通过标准 |
|---|---|---|
| A1 | 服务端路由定义无 `.html` 后缀 | `Router::post('/api/version', ...)` ✅，`Router::post('/api/version.html', ...)` ❌ |
| A2 | 客户端请求路径有 `.html` 后缀，且必须位于 ThrottleMiddleware 白名单前缀下 | 客户端 POST `http://www.wellcms.com/api/version.html` ✅，`/version.html` ❌（非白名单前缀走限流全链路可能抛异常） |
| A3 | 客户端请求方法与路由声明一致 | 路由 `Router::post(...)` 则客户端 `method => 'POST'` |
| A4 | 服务端路由元数据声明完整 | API 路由必须声明 `'api' => true`（铁律 #3） |
| A5 | `processRouteParams` 剥离 `.html` 后路由可匹配 | mode 2 下 `/api/version.html` → 剥离 → `/api/version` 匹配 `Router::post('/api/version')` |
| A6 | 路由路径在 ThrottleMiddleware 白名单内 | `urlPrefixAllow` 包含 `admin`/`my`/`api`，路由路径必须以其中之一开头，否则进入限流全链路检查 |

**违反示例**:
```php
// ❌ 服务端路由带 .html
Router::post('/api/version.html', ...);

// ❌ 客户端请求缺 .html
$client->request(['url' => 'http://www.wellcms.com/api/version', ...]);

// ❌ 客户端请求路径不在 ThrottleMiddleware 白名单内
// POST http://www.wellcms.com/version.html → 前缀不是 admin/my/api → 触发限流异常

// ❌ 服务端缺 api 标记（mode=2 框架自动识别 JSON 时可能失效）
Router::post('/api/version', [...], []); // 缺 'api' => true
```

---

### B. 请求参数对齐

客户端发出的请求参数必须与服务端控制器 `RequestUtils::param()` 读取的参数名称、类型一致。

| # | 检查项 | 通过标准 |
|---|---|---|
| B1 | 参数名一致 | 服务端 `RequestUtils::param('version', '')` → 客户端 `'version' => $currentVersion` |
| B2 | 参数类型一致 | 服务端 `RequestUtils::param('version', '')`（string）→ 客户端传 string |
| B3 | 无多余必填参数 | 客户端不传服务端未定义 `param()` 读取的字段 |
| B4 | 无缺失必填参数 | 服务端 `param('version', '')` 的字段客户端必须传（即使允许空值） |

---

### C. 响应协议对齐

服务端 API 响应结构必须符合铁律 #5 `{status, code, message, data, timestamp}`，客户端必须从 `data` 字段读取有效载荷。字段名必须一一对应，禁止翻译/映射。

| # | 检查项 | 通过标准 |
|---|---|---|
| C1 | 客户端从 `$response['data']` 读取有效载荷 | `$data = $response['data'] ?? []`，不从顶层读 `$response['upgrade']` |
| C2 | 响应字段名完全一致 | 服务端 `'hash'` → 客户端 `$data['hash']`，不能是 `$data['sha256']` |
| C3 | 响应字段结构完全一致 | 服务端 `'upgrade' => 1`（int）→ 客户端 `(int)$data['upgrade']` |
| C4 | 无兜底双读 | 客户端不能同时兼容新旧字段名（如 `$data['url'] ?? $data['download_url']`） |
| C5 | 无兜底默认值覆盖 | 字段不存在时应保持客户端对应配置不变，不以空字符串覆盖已有值 |
| C6 | 版本号使用 `preg_match` 校验格式 | `preg_match('#^\d+(\.\d+){1,3}$#', $data['version'])` 防止异常数据污染 |

**违反示例**:
```php
// ❌ 客户端从顶层读（不在 data 内）
if (isset($response['upgrade'])) { ... }

// ❌ 客户端读旧的字段名
if (!empty($response['download_url'])) { ... }

// ❌ 兜底双读（禁止）
$url = $data['url'] ?? $data['download_url'] ?? '';

// ❌ 服务端返回的字段名与客户端读取的不一致
// 服务端: 'sha256' => 'abc'
// 客户端: $data['hash'] ← null
```

#### 规范响应协议模板

服务端返回（4 种场景之一）：

```php
'data' => [
    'upgrade' => 1,                     // int: 1=有更新 0=已最新
    'upgrade_id' => (string)$latest['id'], // string: 版本记录ID
    'version' => $latest['version'],    // string: 版本号 3.0.1
    'url' => $downloadUrl,              // string: 下载链接（必须 .html 后缀）
    'hash' => $latest['sha256'],        // string: SHA256 校验值
    'message' => $latest['changelog'],  // string: 变更日志/消息
]
```

其他字段（如 `filesize`、`changelog`、`sha256`、`download_url`）**不得出现在 `data` 中**——客户端只消费协议定义的字段。

---

### D. `.html` 后缀强制审计

客户端从远程服务端获取的链接（下载 URL、302 跳转 URL、资源链接）必须以 `.html` 结尾。

| # | 检查项 | 通过标准 |
|---|---|---|
| D1 | 服务端生成的下载 URL 路径含 `.html` | `urlGenerator->url('api/v1/Upgrade/GetPackage')` + mode 2 → `/api/v1/Upgrade/GetPackage.html` ✅ |
| D2 | 服务端未硬编码 URL | 使用 `$this->urlGenerator->url()` 而非字符串拼接路径 |
| D3 | `url_rewrite_on` 值正确 | 服务端 `config/App.php` 中 `url_rewrite_on` 必须为 0/1/2（含 `.html` 后缀的模式） |
| D4 | 客户端未剥离 `.html` | 客户端 Downloader 直接使用 URL，不修改、不追加、不截断路径 |

**保证链路**:
```
urlGenerator->url('api/v1/Upgrade/GetPackage', [...])
  → buildPath(mode=2): return '/' . $route . '.html'
  → /api/v1/Upgrade/GetPackage.html?id=X&token=Y
  → 客户端读取 $data['url'] → Downloader::download() → fopen(.html URL, 'rb')
```

**违反示例**:
```php
// ❌ 服务端 url_rewrite_on = 3（无 .html 后缀模式）
// 生成的 URL: /api/v1/Upgrade/GetPackage?id=X&token=Y

// ❌ 客户端修改下载 URL
$url = $data['url'] . '&extra=param'; // 改变签名

// ❌ 服务端硬编码路径
'url' => '/api/v1/Upgrade/GetPackage?' . http_build_query([...]);
// 缺 .html 后缀！应为 /api/v1/Upgrade/GetPackage.html?
```

---

### E. 客户端无自我逻辑

客户端不得包含独立的"版本判断"、"协议解析"、"数据兜底"逻辑——所有这些由服务端定义。

| # | 检查项 | 通过标准 |
|---|---|---|
| E1 | 客户端不自行构造协议 | 无 `$data['upgrade'] = version_compare(...)` 或类似自判断 |
| E2 | 客户端不自行决定字段默认值覆盖行为 | 字段不存在 → 对应 `$config` 项不变，不主动赋默认值 |
| E3 | 客户端不根据响应状态码做业务判断 | HTTP 200 = 收到响应，但 `data.upgrade` 才决定是否有新版本 |
| E4 | 客户端不缓存旧响应结构 | `$response['data']` 的字段增减完全跟随服务端，客户端不做字段存在性假设 |

---

### F. 端到端验证清单

逐项检查后确认：

- [ ] 服务端所有 API 路由定义无 `.html` 后缀
- [ ] 客户端所有请求 URL 带 `.html` 后缀
- [ ] 服务端响应符合 `{status, code, message, data, timestamp}` 结构
- [ ] 客户端从 `data` 读取所有业务字段
- [ ] 服务端 `data` 内字段名与客户端读取的字段名完全一致
- [ ] 服务端下载 URL 路径含 `.html`（`url_rewrite_on` 为 0/1/2）
- [ ] 客户端无兜底双读/旧字段兼容代码
- [ ] 客户端不自行判断版本、不自行构造协议字段
- [ ] 请求参数名/类型在服务端-客户端之间完全一致
- [ ] `processRouteParams(mode)` 剥离 `.html` 后路由可匹配
