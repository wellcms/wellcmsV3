# WellCMS 3.0 后台设置与主题规范

## 1. 插件后台设置规范 (Plugin Admin Settings)

实现方式取决于页面的复杂程度：

### 方案 A：轻量化方案 (≤ 10 个页面)
统一使用插件目录下的 `setting.php`。
*   **实现机制**：由 `PluginController::setting()` 自动 include 执行。
*   **上下文**：在 `PluginController` 成员方法上下文中运行，可直接使用 `$this->language`, `$this->urlGenerator`, `$user` 等。

**`setting.php` 基础结构示例**：
```php
<?php
!defined('DEBUG') and exit('Access Denied.');
switch ($action) {
    case 'setting':
        if ('GET' === $method) {
            $data = [
                'header' => ['title' => $this->localPlugins[$dir]['name']],
                'action' => $this->urlGenerator->url('admin/plugin/postSetting', ['dir' => $dir, 'action' => 'setting']),
            ];
            // 第三参数传入 $dir 实现插件模板检索
            return $this->render('admin_setting_view', $data, $dir);
        } elseif ('POST' === $method) {
            // 保存逻辑...
            return $this->successMessage($this->language->get('update_success'), 0);
        }
        break;
}
```

### 方案 B：重型化方案 (> 10 个页面)
推荐使用独立的 **控制器 (Controllers)** 加 **路由 (Routes)** 注入。

## 2. 主题 (Theme) 开发准则

*   **继承机制**：主题通过 `config.json` 中的 `dependencies_theme` 声明父子依赖。
*   **寻址优先级**：
    1.  当前激活主题 (`themes/your_theme/htm/`)
    2.  父级主题 (`themes/parent_theme/htm/`)
    3.  系统核心视图目录 (`app/views/htm/`)
*   **扁平化结构**：**模板仅能放在 `/htm/` 根目录下，严禁使用子目录**。文件名必须与主程序严格对应。
*   **资产隔离**：主题 CSS/JS 优先通过 `config.json` 声明。

## 3. 参考实现 (Reference Implementation)
*   **复杂插件设置 (setting.php)**：[well_forum/setting.php](/plugins/well_forum/setting.php)
*   **轻量化接口设置**：[well_tools/setting.php](/plugins/well_tools/setting.php)
