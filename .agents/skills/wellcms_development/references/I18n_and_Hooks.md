# WellCMS 3.0 异步任务与国际化规约 (Jobs & I18n)

## 1. 异步任务与调度规范 (Job & Scheduler Standards)

为了保证高并发下的系统吞吐量，所有耗时操作 (I/O 密集型) must 异步化。

*   **Job 类规范**：must 存放于 `app/Jobs/` (主程序) 或 `plugins/your_plugin/Jobs/` (插件)。必须实现 `\Framework\Scheduler\Interfaces\JobInterface`。
*   **依赖注入**：Job 类通过构造函数接收业务 Service，严禁在 `handle()` 中手动 `new` 类。
*   **触发机制**：必须使用 `\Framework\Scheduler\TaskManage::createTask()` 压入队列。

### 开发示例
```php
namespace Plugins\your_plugin\Jobs;

class NotifyJob implements \Framework\Scheduler\Interfaces\JobInterface {
    private $mailService;
    public function __construct(\App\Services\System\MailService $mailService) {
        $this->mailService = $mailService;
    }
    public function handle(int $uid, string $content): array {
        // 执行逻辑...
        return ['status' => 'success'];
    }
}
```

## 2. 极致性能语言包 (High Performance I18n)

*   **编译压平机制**：WellCMS 3.0 采用编译驱动模式。所有插件的 `Language/` 目录及 `Hooks/language_*.php` 会在编译阶段被物理压平至单体缓存文件。
*   **排他性 (Exclusivity)**：在插件 `Hooks/` 目录下新增语言键值前，**必须优先检索**主程序语言包 (`app/Language/`)。若主程序已包含同义且语义匹配词条（如 `submit`, `save`, `edit`, `delete` 等），必须直接引用，禁止重复定义。
*   **严禁硬编码**：严禁在 PHP 代码或 HTML 模板中直接书写中文或其他语言文本。所有文本必须通过语言包管理。
*   **Key 命名规范**：插件私有 Key 必须包含插件前缀或 `well_` 前缀（如 `well_demo_title`）。
*   **运行期 O(1) 加载**：运行时严禁调用递归合并或多文件探测。必须通过 `LanguageManager` 直接 include 编译后的扁平化数组。
*   **前台/后台语言包严格分离**：插件 `Language/{locale}/` 目录下必须区分：
    *   `language.php` —— **前台语言包**，仅供前台控制器/模板使用。
    *   `admin.php` —— **后台语言包**，仅供后台控制器/模板使用。
    *   后台键严禁混入 `language.php`，前台键严禁混入 `admin.php`。后台控制器通过 `AdminTrait` 自动加载 `admin.php`，前台控制器通过 `FrontendTrait` 自动加载 `language.php`。

### 动态语言钩子 (Language Hooks)
文件位置：`plugins/your_plugin/Hooks/language_zh_language.php`。
```php
<?php exit;
// 用于动态向语言包数组中物理注入代码
'well_demo_dynamic_key' => '动态值',
```

## 3. 钩子归属与编写规范 (Hook Ownership)

### 3.1 钩子文件名只是“位置坐标”
`plugins_well_article_Services_CommentService_create_transaction_after.php` 这类文件名中的命名空间路径，**仅用来告诉编译器要注入到哪个类、哪个方法、哪个位置**，不代表这个 hook 应该由 `well_article` 实现。

### 3.2 禁止插件 hook 自己
- 宿主插件只负责在自己的 Service / Controller / Trait 里埋 `// hook xxx` 扩展点。
- **禁止**在 `plugins/your_plugin/Hooks/` 下写目标类仍属于本插件的 hook 文件。
- 如果你需要给本插件加功能，直接改本插件的类，不要绕一圈 hook。

### 3.3 跨插件能力必须由能力提供方实现
- **消息通知** → 由 `well_message` 实现，宿主插件（如 `well_article`、`well_forum`）只暴露 hook 点。
- **搜索索引** → 由搜索插件实现。
- **多站点同步** → 由 `well_multi_site_sync` 实现。
- 宿主插件**不得**在自己的 `Hooks/` 里实现这些跨插件能力，避免硬耦合和功能重复。

### 3.4 Hook 文件编写安全要点
- 钩子代码会被编译器**内联到宿主方法体中**，因此钩子代码必须遵守宿主方法的返回类型。
- 如果宿主方法返回 `array` / `ResponseInterface` 等非 `void` 类型，钩子中**严禁使用无值 `return;`**。
- 推荐用 `if` 条件包裹早期退出逻辑，而不是 `return;`。
- 优先使用 `\Plugins\well_message\Helpers\Notifier::sendSafe()` 等安全封装，避免在 hook 中直接写易抛异常的原生调用。

## 4. 编译驱动与调试准则
*   **生命周期自洽**：安装、卸载或修改插件配置后，系统会自动销毁 `compile_manifest.php`。下一次请求将强制触发“重扫模式”。
*   **冻结模式**：当 `DEBUG === 0` 时，系统依赖清单不再执行 `filemtime` 检查。

## 5. 参考实现 (Reference Implementation)
*   **前台语言包**：[zh/language.php](/plugins/well_forum/Language/zh/language.php)
*   **后台语言包**：[zh/admin.php](/plugins/well_forum/Language/zh/admin.php)
*   **动态语言逻辑钩子**：[language_zh_language.php](/plugins/well_forum/Hooks/language_zh_language.php)
