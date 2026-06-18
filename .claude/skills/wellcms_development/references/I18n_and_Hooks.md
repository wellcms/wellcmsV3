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
    *   后台控制器通过 `AdminTrait` 自动加载 `admin.php`，前台控制器通过 `FrontendTrait` 自动加载 `language.php`。
    *   **两个文件在编译层物理隔离**，不存在自动 fallback。审计插件语言包时**必须同时检查 `language.php` 和 `admin.php`**，只检查其一就得出"完整"结论是严重错误。
*   **必须翻译全部 14 个本土语言**：新增或修改论坛插件（`plugins/well_forum/`）的语言键时，**必须同时实现全部 14 个本土语言翻译**，禁止仅添加英文或仅添加中英文：
    *   目录：`plugins/well_forum/Language/{locale}/language.php`（前台）和 `admin.php`（后台）
    *   14 个语言代码：`zh`（简体中文）、`tw`（繁体中文）、`en`（英文）、`ja`（日文）、`ko`（韩文）、`de`（德文）、`fr`（法文）、`es`（西班牙文）、`it`（意大利文）、`pt`（葡萄牙文）、`ru`（俄文）、`ar`（阿拉伯文）、`nl`（荷兰文）、`tr`（土耳其文）
    *   翻译必须使用本土语言，禁止使用机翻占位符或留空
    *   参考现有翻译风格维护一致性

### 动态语言钩子 (Language Hooks)
文件位置：`plugins/your_plugin/Hooks/language_zh_language.php`。
```php
<?php exit;
// 用于动态向语言包数组中物理注入代码
'well_demo_dynamic_key' => '动态值',
```

## 3. 编译驱动与调试准则
*   **生命周期自洽**：安装、卸载或修改插件配置后，系统会自动销毁 `compile_manifest.php`。下一次请求将强制触发“重扫模式”。
*   **冻结模式**：当 `DEBUG === 0` 时，系统依赖清单不再执行 `filemtime` 检查。

## 4. 参考实现 (Reference Implementation)
*   **前台语言包**：[zh/language.php](/plugins/well_forum/Language/zh/language.php)
*   **后台语言包**：[zh/admin.php](/plugins/well_forum/Language/zh/admin.php)
*   **动态语言逻辑钩子**：[language_zh_language.php](/plugins/well_forum/Hooks/language_zh_language.php)
