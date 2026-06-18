# WellCMS 3.0 前端模板变量规范 (Front-end Template Variables)

本指南总结了 WellCMS 3.0 模板中常用的变量及其结构，方便开发者在编写 `.htm` 文件时准确调用数据。

## 1. 变量访问逻辑
在模板中，所有变量均通过 `$view` 对象访问：
- **安全转义输出**: `<?php echo $view->e('key.subkey');?>` (推荐，默认处理 HTML 转义)
- **原始数据获取**: `<?php $data = $view->raw('key.subkey', $default);?>` (用于遍历或逻辑判断)

---

## 2. 全局基础变量 (Global Website Data)
这些变量在几乎所有页面（由 `BaseController` 初始化）均可用。

| 变量路径 | 说明 | 示例值 |
| :--- | :--- | :--- |
| `website.header.title` | 页面最终 Title | `首页 - WellCMS` |
| `website.header.keywords` | 页面关键词 | `cms, php, high-performance` |
| `website.header.description` | 页面描述 | `这是一个高性能内容管理系统...` |
| `website.copyright` | 版权声明 | `Copyright © 2026 WellCMS` |
| `website.sitename` | 站点名称 | `WellCMS 官方社区` |
| `website.locale.default` | 当前语言 | `zh`、`us` |
| `website.static_version` | 静态资源版本号（用于清除缓存） | `?v=1.0.1` |
| `url_index` | 首页链接 | `/` |
| `csrf_token` | CSRF 防护令牌，用于 POST 表单 | `a1b2c3d4...` |

---

## 3. 用户与权限变量 (User & Identity)
存储在 `user` 根键下，由 `MenuService` 统一分发。

| 变量路径 | 说明 | 示例 |
| :--- | :--- | :--- |
| `user.uid` | 用户 UID (0 为游客) | `1` |
| `user.username` | 用户名 | `Admin` |
| `user.avatar_url` | 头像 URL | `/upload/avatar/1.png` |
| `user.groupname` | 用户组名 | `管理员` |
| `user.administer` | 是否拥有后台管理权限 (bool) | `true` |
| `user.links.home` | 个人中心链接数组 (`name`, `url`) | `['name' => '我的主页', 'url' => '...']` |
| `user.links.admin` | 后台入口链接 (仅管理员有) | `['name' => '后台管理', 'url' => '...']` |
| `user.links.logout` | 退出登录链接 | `['name' => '退出', 'url' => '...']` |

---

## 4. 导航与结构化列表 (Navigation & Menus)
- **主导航**: `navigation` 为一个对象数组。
  - `$item['parent']`: 父级菜单
  - `$item['name']`: 名称
  - `$item['url']`: 链接可能为 `javascript:void(0);`
  - `$item['icon']`: 图标
  - `$item['original_url']`: 原始链接
  - `$item['children']`: 子菜单数组
      - `$item['child']`: 子菜单
      - `$item['name']`: 名称
      - `$item['url']`: 链接
- **面包屑**: `breadcrumb` 常用结构：
  - `breadcrumb.home`: 首页信息
  - `breadcrumb.list`: 列表页/分类页信息
  - `breadcrumb.title`: 当前页信息

---

## 5. 模式：结构化输入数据 (Pattern: Structured Input)
在后台或插件设置页面，变量通常被封装为对象以简化表单渲染：
```php
<?php $item = $view->get('tag_size'); ?>
<label><?php echo $item['text']; ?></label>
<input type="<?php echo $item['type']; ?>" name="<?php echo $item['name']; ?>" value="<?php echo $item['value']; ?>">
```
常见属性包括：`text` (中文名), `name` (表单提交键), `value` (当前值), `type` (input类型)。

---

## 6. 运行期调试变量 (Runtime Debug)
仅在 `DEBUG > 1` 时生效，位于 `footer.inc.htm` 中。
- `website.running.processed_time`: 页面执行耗时。
- `website.running.sql_count`: SQL 执行次数。
- `website.running.sqls`: 具体的 SQL 语句数组。
- `website.running.request`: 当前请求的所有上下文数据镜像。

## 7. 参考实现 (Reference Implementation)
*   **公共视图头模板**：[header.inc.htm](/app/views/htm/header.inc.htm)
*   **公共头部菜单逻辑**：[MenuService.php](/app/Services/MenuService.php)
