# WellCMS 3.0 插件架构规约 (Plugin Framework Standards)

## 1. 核心配置文件 `config.json`
插件元数据、资产与权限指令的唯一入口。必须 strictly 区分 **“框架指令”** 与 **“业务配置”**。

### 结构标准 (Standard Structure)
```json
{
    "name": "插件名称",
    "brief": "介绍",
    "type": "0", // 0=插件, 1=主题
    "version": "1.0.0",
    "software_version": "3.0.0",
    "enable": 0,
    "installed": 0,
    "rank": 90,
    "assets": {
        "global": {
            "css": ["css/common.css", "https://cdn.example.com/a.css"],
            "js": ["js/core.js"]
        },
        "admin": {
            "css": ["css/admin.css"],
            "js": ["js/admin.js"]
        }
    },
    "config": {
        "enable_feature_x": true
    },
    "hooks_rank": [],
    "overwrites_rank": []
}
```

## 2. 插件 Rank 排序与劫持 (Ranking)
*   **执行顺序**：`Rank` 较大的插件代码块排列在较小者 **之前** (大者优先)。
*   **Overwrite 决胜**：当多个插件覆盖同一源文件，仅采纳 `Rank` 最高者的物理文件。
*   **原则**：基础库/公用组件设小 Rank (10~50)，业务功能设大 Rank (100+)。

## 3. 前端资产聚合 (Assets Aggregator)
*   **声明式载入**：严禁硬编码 `<script>` 或 `<link>`。必须使用 `config.json` 的 `assets` 节点。
*   **模版宏标签**：
    *   `<asset-css group="global" />`
    *   `<asset-js group="admin" />`
*   **智能路由**：外部链接被识别为 `external`，本地文件自动生成 8 位内容哈希指纹。

## 4. 无侵入注入 (Non-intrusive Injection)
*   **容器服务注入**：使用 `app_Providers_ModelServiceProvider_register_models.php` 钩子。
    *   必须采用 **“类名数组 array_merge”** 模式。
*   **中间件注入**：在 `app_Bootstrap_middleware_before.php` 钩子向 `$middlewareQueue` 注入。

## 5. 插件私有 Vendor 处理
*   **存放路径**：物理存放于 `plugins/your_plugin/vendor/`。
*   **加载逻辑**：在插件钩子中执行 `require_once __DIR__ . '/../vendor/autoload.php';`。
*   **原则**：严禁修改主程序 `composer.json`。

## 6. 路由注入规范 (Routing)
*   **文件位置**：`Hooks/app_Routes_Routes_end.php`。
*   **命名引用**：必须使用全名限定引用 (Fully Qualified Name)。
    *   `[\Plugins\your_plugin\Controllers\ApiController::class, 'list']`
*   **Router 类**：严禁在钩子内再次 `use Router`。

## 7. 参考实现 (Reference Implementation)
*   **标准插件配置**：[config.json](/plugins/well_forum/config.json)
*   **路由注入钩子**：[app_Routes_Routes_end.php](/plugins/well_forum/Hooks/app_Routes_Routes_end.php)
