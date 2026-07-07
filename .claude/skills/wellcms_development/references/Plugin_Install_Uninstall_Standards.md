# 插件安装与卸载标准 (Plugin Installation & Uninstallation Standards)

本手册定义了 WellCMS 3.0 插件在安装 (`install.php`) 和卸载 (`uninstall.php`) 过程中的物理文件处理及数据库方言编写规范。

## 🟢 安装规范 (install.php)

### 1. 物理文件处理

* **批量复制**：严禁冗长的单行复制，必须使用数组循环配合 `FileHelper::copy`。
* **目录创建**：复制前必须使用 `DirectoryHelper::mkdir()` 确保目标路径存在。

```php
$dest_path = APP_PATH . 'app/views/icon/';
DirectoryHelper::mkdir($dest_path);
$icons = ['facebook.ico', 'instagram.ico'];
foreach ($icons as $icon) {
    FileHelper::copy(APP_PATH . 'plugins/well_tools/views/icon/' . $icon, $dest_path . $icon);
}
```

### 2. 数据库字段扩展 (Altering Tables)

* **循环定义**：将统计字段（user表）和权限字段（group表）封装进数组循环处理。
* **存在性校验**：执行 `ADD COLUMN` 前必须调用 `$db->findField()`，防止重复安装报错。

```php
$user_fields = ['tools_pending', 'tools_approved'];
foreach ($user_fields as $field) {
    if (!$db->findField('user', $field)) {
        $db->exec("ALTER TABLE `{$db->prefix}user` ADD `{$field}` int(11) UNSIGNED NOT NULL DEFAULT '0';");
    }
}
```

### 3. 权限字段更新 (Permission Updates)

* **服务层驱动**：添加新权限字段后，严禁使用 `$db->exec("UPDATE...")`。必须通过 `GroupService` 实现，以确保缓存同步及业务逻辑闭环。
* **严禁修改 SQL 文件**：所有插件相关的字段扩展必须在插件自身的 `install.php` 中完成，严禁修改主程序核心 `/install/install.sql`。

```php
if ($field === 'collection') {
    $this->container->get(\App\Services\Auth\GroupService::class)->update(1, [$field => 1]);
}
```

### 4. 数据表架构 (Schema Creation)

* **匿名函数模式**：使用局部闭包处理表存在性检查，保持代码整洁。
* **SQL 标准**：统一使用 `snake_case`，显式声明 `NOT NULL DEFAULT`，并为索引表/大数据表添加 `PARTITION BY RANGE`。
* **方言中立**：SQL 必须保持标准写法，依靠底层 `Grammar` 类自动适配 MySQL/PostgreSQL 标识符（` vs "）。

---

## 🔴 卸载规范 (uninstall.php)

### 1. 精准文件清理

* **严禁盲目删除目录**：除了插件独占的私有目录外，严禁直接使用 `rmdirRecursive(..., true)` 删除公共目录（如 `app/views/icon/`）。
* **两步走策略**：
    1. 逐一删除插件产出的具体文件。
    2. 调用 `DirectoryHelper::rmdir()`（仅在目录为空时才会执行物理删除），确保不影响共用该目录的其他插件。

```php
foreach ($icons as $icon) {
    FileHelper::unlink($icon_path . $icon);
}
DirectoryHelper::rmdir($icon_path); // 安全删除
```

### 2. 字段与表清理

* **逆向循环**：使用与安装对应的列表进行 `DROP COLUMN` 和 `DROP TABLE`。
* **安全删除**：必须在删除前执行 `findField` 或 `findTable` 检测，确保卸载过程的鲁棒性。

## ⚠️ 兼容性铁律

1. **大小写屏蔽**：由于底层驱动开启了 `PDO::CASE_LOWER`，在 `findField` 和 `findTable` 时，框架会自动匹配。SQL 写法应统一遵循小写规范。
2. **前缀引用**：所有 SQL 必须包含 `{$db->prefix}`。
3. **方言转换保护**：DDL 脚本严禁自带双分号或非标准注释，确保 `prepareSchema` 能将其正确转译为 PostgreSQL 等方言。

## 3. 参考实现 (Reference Implementation)

* **标准安装脚本 (install.php)**：[well_forum/install.php](/plugins/well_forum/install.php)
* **标准卸载脚本 (uninstall.php)**：[well_forum/uninstall.php](/plugins/well_forum/uninstall.php)
