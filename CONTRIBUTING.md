# WellCMS 3.0 贡献指南

感谢你考虑为 WellCMS 做出贡献！无论你是提交 Bug 报告、功能建议，还是直接提交代码，我们都非常欢迎。

---

## 🐛 如何提交 Bug

1. **搜索现有 Issue**，确认该问题未被报告过。
2. 如果确认是新问题，请使用 **Bug 报告模板** 创建 Issue。
3. 尽可能提供详细的复现步骤、环境信息和相关日志。

---

## 💡 如何提交功能建议

1. **搜索现有 Issue / Discussion**，确认该功能未被提议过。
2. 使用 **功能请求模板** 创建 Issue。
3. 描述清楚功能的**使用场景**和**期望行为**。

---

## 🔧 开发环境准备

```bash
# 1. 克隆仓库
git clone https://github.com/your-org/wellcms.git
cd wellcms

# 2. 环境要求
# - PHP >= 7.2（推荐 8.0 ~ 8.5）
# - PDO、mbstring、gd/fileinfo、openssl 扩展
# - 可选：Swoole 扩展（用于协程模式开发）
# - MySQL / PostgreSQL / SQLite 等数据库

# 3. 配置
# 复制 config/ 下各配置文件并根据环境修改
# 访问 http://localhost/install/ 完成安装向导
```

---

## 📝 代码规范

### 基础约束

- **PHP 7.2+ 兼容**：禁止使用 typed properties、union types、`match` 表达式、`str_contains` 等 PHP 8+ 专属语法。
- **严格类型**：所有新 PHP 文件开头必须包含 `<?php\ndeclare(strict_types=1);`
- **无 Composer 依赖**：核心框架不引入任何 Composer 包。工具类请优先放入 `src/Utils/` 或 `app/Utils/`。

### 命名与组织

| 类型 | 规范 | 示例 |
|------|------|------|
| 命名空间 | PSR-4 风格 | `App\Services\Auth\UserService` |
| 类名 | 大驼峰 (PascalCase) | `class UserService {}` |
| 方法名 | 小驼峰 (camelCase) | `public function getUserById()` |
| 常量 | 全大写 + 下划线 | `const MAX_RETRY = 3;` |
| 文件 | 类名与文件名一致 | `UserService.php` |

### 注释与文档

- **中文注释优先**：核心逻辑注释使用中文，方便国内开发者阅读。
- **DocBlock**：公共方法建议添加 `@param` 和 `@return` 说明。
- **复杂逻辑**：在复杂算法或业务分支前添加行内注释。

### 数据库写操作返回值判断规范

`update()` / `bulkUpdate()` / `delete()` 返回 `int`（匹配行数，>= 0）。

- **禁止**使用 `if (!$result)` 判断执行成功。
- **禁止**使用 `if ($result)` 作为成功断言（因 `0` 也是合法返回值）。
- 正确做法：
  - 判断失败：`if ($result === false) return false;`（实际在 PDO 异常模式下不会触发）。
  - 判断未命中：`if ($result === 0) return false;`（显式表达"必须影响 N 行"的业务意图）。
  - 幂等场景：不判断返回值，依赖 `try-catch` 处理异常。

### 代码风格示例

```php
<?php

declare(strict_types=1);

namespace App\Services\Auth;

/**
 * 用户服务类
 */
class UserService
{
    /**
     * 根据用户 ID 获取用户信息
     *
     * @param int $userId 用户 ID
     * @return array|null
     */
    public function getUserById(int $userId): ?array
    {
        // 优先读取缓存，减少数据库查询
        $cacheKey = 'user_' . $userId;
        // ...
    }
}
```

---

## 🔄 Pull Request 流程

1. **Fork 仓库** 并从 `main`（或 `master`）分支创建你的功能分支：
   ```bash
   git checkout -b feature/my-awesome-feature
   ```

2. **保持提交清晰**：每个 commit 只做一件事，commit message 用中文或英文清晰描述变更原因。

3. **自测**：
   - FPM 模式下能正常访问前后台
   - Swoole 模式下能正常启动和响应请求（如使用 Swoole）
   - 安装向导能正常走完 5 步

4. **同步主分支**：提交 PR 前请 rebase 到最新的主分支，解决冲突。

5. **填写 PR 描述**：说明变更原因、影响范围、测试方式。

6. **等待 Review**：维护者会在 3-5 个工作日内进行反馈，请及时响应修改意见。

---

## ⚠️ 注意事项

- **不要** 提交 `storage/logs/`、`storage/tmp/`、`upload/` 中的运行时文件。
- **不要** 提交 `install/install.lock`。
- **不要** 提交 `config/` 文件夹。
- **不要** 在代码中硬编码密码、API Key、真实域名等敏感信息。
- 修改涉及框架核心（`src/`）时，请确保同时更新相关注释文档。

---

## 📜 行为准则

请保持友善和尊重，禁止人身攻击、歧视性言论和骚扰行为。我们致力于打造一个开放、包容的社区环境。

---

再次感谢你的贡献！
