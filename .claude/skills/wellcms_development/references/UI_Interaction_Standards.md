# WellCMS 3.0 前端美学与交互规约 (UI & Interaction Standards)

> **源码位置**: `app/views/js/main.js` —— 所有前端交互的唯一标准库。
> **严禁**在模板 JS 中引入外部 AJAX 库或自行封装 `fetch/XMLHttpRequest`。

---

## 1. 声明式交互 (Declarative UI)

基于 `GlobalClickHandler` + `GlobalFormHandler` 驱动，通过 HTML 属性控制业务逻辑，减少重复 JS。

### 1.1 点击交互 (GlobalClickHandler)

| 属性 | 说明 |
|------|------|
| `.ajax-get` / `.ajax-post` | 点击触发异步请求 |
| `data-href` / `href` / `data-url` | 目标 URL（优先级：`data-href` > `href` > `data-url`） |
| `data-confirm` | 确认文案，如 `"确定删除吗？"` |
| `data-arg` / `data-json` | POST 提交的 JSON 数据，如 `{"id":1}` |
| `data-reload="false"` | 成功后不自动刷新页面 |
| `data-modal-title` | 触发 `ajaxModal` 弹窗，值为弹窗标题 |
| `data-modal-url` / `href` | `ajaxModal` 加载的远程 URL |
| `data-modal-size` | 弹窗尺寸：`sm` / `md` / `lg` / `xl` / `full` |
| `data-modal-callback` | 弹窗加载完成后执行的回调函数名 |
| `data-modal-arg` | 传入回调函数的参数 |

**示例：**
```html
<!-- AJAX GET 请求 -->
<a class="ajax-get" data-href="admin/user/delete?id=1" data-confirm="确定删除？">删除</a>

<!-- AJAX POST 请求 -->
<button class="ajax-post" data-href="admin/user/ban" data-arg='{"id":1}'>封禁</button>

<!-- 声明式弹窗（加载远程页面） -->
<a href="admin/user/edit?id=1" data-modal-title="编辑用户" data-modal-size="lg">编辑</a>
```

### 1.2 表单交互 (GlobalFormHandler)

| 属性 | 说明 |
|------|------|
| `.ajax-form` | 接管表单提交，阻止默认行为，通过 `wellcms.post()` 发送 |
| `data-loading-text` | 提交中按钮文案，如 `"保存中..."` |
| `data-timeout` | 成功后跳转/刷新延迟（秒），默认 1 |
| `data-modal-size` | 错误弹窗尺寸 |

**成功响应处理：**
- `code === 0` 时显示 `toast('success')`，若 `res.data.alert` 为真则显示 `success()` 弹窗
- 若 `res.data.redirect.url` 存在，延迟跳转
- 触发 `CustomEvent('wellcms:form-success', { detail: res })`

**错误响应处理：**
- `res.data.field` 存在时，调用 `showInputError(field, message)`
- 否则显示 `error()` 阻塞弹窗

---

## 2. wellcms JS API 速查手册

全局唯一实例：`window.wellcms`（由 `new WellCMSUI()` 创建）。

**⚠️ 致命陷阱**: `window.wellcms.ui` **不存在**！只有 `window.wellcms`。所有方法直接通过 `wellcms.xxx()` 调用。

### 2.1 弹窗与通知

#### `wellcms.dialog(options)`
通用模态弹窗。

```javascript
wellcms.dialog({
    title: '标题',
    body: '<p>HTML 内容</p>',
    type: 'info',        // 'info' | 'success' | 'error' | 'confirm'
    size: 'md',          // 'sm' | 'md' | 'lg' | 'xl' | 'full'
    confirmText: '确认',
    cancelText: '取消',
    onConfirm: function() { /* 同步回调 */ },
    onCancel: function() { /* 同步回调 */ }
});
```

**🔴 关键限制 —— onConfirm 陷阱：**
- `onConfirm` **不支持 async**（点击后立即 `dialogEl.remove()`，不等待异步完成）
- `onConfirm` **不支持 `return false` 阻止关闭**
- 若需在确认时执行 AJAX 并保留弹窗，**必须在 `body` 内放置自定义按钮**，绕过 `dialog` 的 confirm 逻辑；或使用 `type: 'info'` 不渲染确认按钮

#### `wellcms.alert(message, title, size)` / `wellcms.success(message, title, size)` / `wellcms.error(message, title, size)`
阻塞式模态对话框，内部调用 `dialog()`。

```javascript
wellcms.error('操作失败', '错误', 'sm');
wellcms.success('保存成功', '成功');
```

#### `wellcms.confirm(message, onConfirm, onCancel)`
确认对话框。

```javascript
wellcms.confirm('确定删除吗？', function() {
    // 同步操作
});
```

#### `wellcms.toast(message, type, duration)` ⭐ 标准反馈方式
非阻塞 Toast 提示。**`GlobalFormHandler` 和 `GlobalClickHandler` 内部统一使用此方法。**

```javascript
wellcms.toast('操作成功', 'success', 3000);  // type: success|error|warning|info
wellcms.toast('保存失败', 'error');
```

### 2.2 AJAX 请求

#### `wellcms.get(url, params, retry)`
GET 请求，自动携带 `X-Requested-With: XMLHttpRequest`。

```javascript
// 方式1：带查询参数
const res = await wellcms.get('admin/store/list', { page: 1, keyword: 'test' });

// 方式2：只传 URL（第二个参数省略）
const res = await wellcms.get('admin/store/list');

// ⚠️ 陷阱：如果第二个参数是 number，会被视为 retry 次数！
// wellcms.get('url', 3) 等价于 retry=3，params={}
```

**返回值：**
- JSON 响应自动解析为对象 `{code, data, message}`
- HTML 响应返回 `{code: 0, data: html, isHtml: true, parse(selector)}`
- 网络错误返回 `{code: -1, message: '...'}`

#### `wellcms.post(url, data, onProgress)`
POST 请求，自动将对象转为 `FormData`，自动携带 `X-Requested-With: XMLHttpRequest`。

```javascript
const res = await wellcms.post('admin/store/postPayment', {
    dir: 'well_forum',
    _csrf_token: token,
    password: '123456'
});

// 文件上传进度
await wellcms.post('upload', formData, function(percent) {
    console.log(percent + '%');
});
```

### 2.3 URL 生成

#### `wellcms.url(route, extra, config)` ⭐ 必须传 config

```javascript
wellcms.url('admin/store/list', { page: 1 }, {
    path: './',
    url_rewrite_on: 0   // 必须与服务器配置一致！
});
```

**`url_rewrite_on` 模式对照表：**

| 模式 | 示例路由 | 生成结果 |
|------|----------|----------|
| 0 | `admin/store/list` | `?admin-store-list.html` |
| 1 | `admin/store/list` | `admin-store-list.html` |
| 2 | `admin/store/list` | `/admin/store/list.html` |
| 3 | `admin/store/list` | `/admin/store/list` |

**🔴 致命陷阱 —— 默认配置不匹配：**
- 第三个参数默认 `{path: './', url_rewrite_on: 0}`
- **如果服务器 `url_rewrite_on ≠ 0`，不传第三个参数会导致 404！**
- 模板中已通过 `data-*` 属性注入 `url_rewrite_on` 和 `path`，JS 必须读取后传入

### 2.4 远程弹窗加载

#### `wellcms.ajaxModal(url, title, options)`
AJAX 加载远程 HTML 到弹窗。

```javascript
wellcms.ajaxModal('admin/user/edit?id=1', '编辑用户', {
    size: 'lg',
    callback: 'onModalLoaded',  // 加载完成后执行的函数名
    arg: 'extraData'            // 传入 callback 的参数
});
```

**工作流程：**
1. 显示 loading 弹窗
2. GET 加载远程 HTML
3. 提取 `.ajax-body` 或 `#body` 的 **innerHTML**（`.ajax-body` 元素本身不在弹窗 DOM 中）
4. 移除 `script, link, style, meta, title, iframe` 标签
5. 提取并执行 `<script ajax-eval="true">...</script>` 脚本
6. 提取并加载外部 `<script src="...">`

**🔴 关键陷阱 —— ajaxModal 与 .ajax-body：**
- `ajaxModal` 注入的是 `.ajax-body` 的 `innerHTML`，**`.ajax-body` 元素本身不在弹窗 DOM 中**
- 如果远程页面的脚本包含 `document.querySelector('.ajax-body')`，会返回 `null` 导致崩溃
- **结论**：依赖 `.ajax-body` 元素自身属性的脚本（如 `ajaxBody.getAttribute('payapi')`）**不兼容 `ajaxModal`**

### 2.5 表单错误提示

#### `wellcms.showInputError(name, message)`
在指定输入框上方显示错误提示（带抖动动画），输入时自动消失。

```javascript
wellcms.showInputError('password', '密码错误');
```

---

## 3. PHP → JS 参数传递规范

### 🔴 铁律：严禁在 JS 代码中硬编码 PHP 代码

所有 PHP 变量必须通过 **HTML `data-*` 属性** 注入，JS 从 DOM 读取。

**错误示范：**
```javascript
// ❌ 严禁！JS 中硬编码 PHP
var token = "<?php echo $view->get('csrf_token');?>";
var rewrite = <?php echo (int)$view->get('config.rewrite');?>;
```

**正确示范：**
```html
<!-- 1. PHP 在 HTML 标签上输出 data-* 属性 -->
<div id="store-payment-config"
     data-csrf-token="<?php echo htmlspecialchars($view->get('csrf_token'));?>"
     data-rewrite="<?php echo (int)$view->get('config.rewrite');?>"
     data-path="<?php echo htmlspecialchars($view->get('config.path'));?>">
</div>

<script>
// 2. JS 从 DOM 读取（纯 JS，无 PHP）
(function() {
    var configEl = document.getElementById('store-payment-config');
    var csrfToken = configEl.dataset.csrfToken;     // data-csrf-token → csrfToken
    var rewrite = parseInt(configEl.dataset.rewrite, 10);
    var path = configEl.dataset.path;
    
    // 使用
    wellcms.get(wellcms.url('admin/store/paymentDialog', {dir: dir}, {
        url_rewrite_on: rewrite,
        path: path
    }));
})();
</script>
```

**data-* 属性命名与 dataset 映射规则：**
- HTML: `data-csrf-token` → JS: `dataset.csrfToken`
- HTML: `data-url-rewrite-on` → JS: `dataset.urlRewriteOn`
- HTML: `data-recharge-url` → JS: `dataset.rechargeUrl`

---

## 4. 后台视觉规范 (Admin Aesthetics)

*   **列表页 (List)**：
    *   `thead` 背景：`bg-gray-50/50` (极浅灰)。
    *   多选主控：ID 必须为 `selectAll`。
    *   平滑删除：使用 `ajax-delete` 类，赋予受力物理反馈动画。
*   **组件化 (Components)**：
    *   **Switch**：严禁原生复选框，必须使用 `well-switch` 控件。
    *   **Focus**：输入框光晕统一使用 `ring-blue-500`。

---

## 5. 参考实现 (Reference Implementation)

*   **后台列表交互标准**：[admin_thread_list.htm](/plugins/well_forum/views/htm/admin_thread_list.htm)
*   **前端 AJAX 回复与点赞**：[forum_thread_detail.htm](/plugins/well_forum/views/htm/forum_thread_detail.htm)
