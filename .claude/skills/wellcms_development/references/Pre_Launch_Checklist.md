# WellCMS 3.0 发布前检查清单 (Pre-Launch Checklist)

**核心原则**：每个发布都必须是"可回滚、可观测、增量式"的。走完此清单再发布，禁止跳过。

---

## 一、代码质量

### 编译与缓存
- [ ] 删除 `storage/tmp/classes/`、`storage/tmp/views/`、`storage/tmp/langs/`、`storage/tmp/configs/` 全部编译缓存，触发重新压平
- [ ] `compile_manifest.php` 重新生成后确认 Hook 注入无遗漏
- [ ] Tailwind CSS 重新编译：`./node_modules/.bin/tailwindcss -i tailwind.input.css -o app/views/css/tailwind.min.css -m`
- [ ] 新增的 Tailwind class 已同步写入 `app/views/class_dump.html`
- [ ] 静态资产已重新聚合（`public/static/runtime/` 下指纹文件已更新）
- [ ] Redis/APCu/Memcached 缓存已清除（根据项目使用的缓存驱动）

### 代码审查
- [ ] 所有改动已通过代码审查（五轴：正确性/可读性/架构/安全/性能）
- [ ] 无 `console.log`、`var_dump`、`print_r`、`error_log` 等调试语句遗留在生产代码中
- [ ] 无 `??` 或 `?:` 兜底违反铁律 #25（零兜底严格模式）
- [ ] 无 TODO、FIXME、XXX 等未完成任务标记（与本次发布相关且已评估）
- [ ] 无死代码或注释掉的代码块遗留

### 异常处理
- [ ] 所有 `catch` 块已记录日志（`LoggerInterface::warning/error`），无空 catch
- [ ] API 失败路径记录了原始错误信息，不返回空数组让调用方误判
- [ ] 关键业务路径已覆盖预期的异常分支

---

## 二、安全

### 认证与鉴权
- [ ] 所有需登录的接口已声明 `requiresAuth => true`
- [ ] 所有管理员接口已声明 `requiresAdminSignIn => true`
- [ ] 所有 POST 路由已声明 `requiresCsrf => ['enable' => true, 'ttl' => 3600]`
- [ ] 所有需权限控制的接口已声明 `requiresUserPerm => ['enable' => true, 'role' => [...]]`
- [ ] 所有权限字段已在 `well_group` 表中存在
- [ ] 控制器内无 `verifyCsrfToken()` 手动调用（铁律 #8）

### 输入与数据
- [ ] 所有外部 ID 参数已强制 `(int)` 转换（铁律 #2）
- [ ] 所有数据库查询使用参数化绑定（无 SQL 拼接）
- [ ] 所有用户内容输出使用 `$view->e()` 或 `htmlspecialchars()`，无 XSS 风险
- [ ] 文件上传已校验 MIME type 和文件大小限制
- [ ] 文件上传已校验 magic bytes（不止文件扩展名）

### 配置与密钥
- [ ] 无密钥/密码硬编码在代码或配置文件中
- [ ] 生产环境密钥通过环境变量或密钥管理服务注入
- [ ] `.env` / `.env.local` 不在版本控制中（已在 `.gitignore` 中排除）
- [ ] 安全 Header 已配置（CSP、HSTS、X-Frame-Options、X-Content-Type-Options）

### Hook 与插件
- [ ] 所有 Hook 文件第一行为 `<?php exit;`
- [ ] Hook 文件中无 `use` 语句，全部使用 FQCN（铁律 #14）
- [ ] Hook 文件中除首行外无 `<?` 字面量
- [ ] `config.json` 已包含 `install`/`uninstall` 声明
- [ ] `assets` 路径使用相对路径，不是绝对路径

---

## 三、性能

### 数据库
- [ ] 无 `OFFSET` 分页——全部使用游标分页（铁律 #20）
- [ ] 无实时 `COUNT(*)`——已使用冗余统计字段（铁律 #16）
- [ ] 无 JOIN/子查询/视图——多表关联在 PHP 层逐表查询后合并（铁律 #17）
- [ ] 查询 `$condition` 键名顺序对齐 `install.php` 索引定义（铁律 #19）
- [ ] `WHERE IN` 数组已执行 `array_values()` 重新索引（铁律 #23）
- [ ] 大数据表已注册分区维护（PartitionManager）

### 缓存
- [ ] 热点查询已配置缓存层（Redis/APCu/Memcached/Yac）
- [ ] 缓存键设计无冲突（协程上下文已隔离）
- [ ] `DEBUG=0` 生产模式下 filemtime 检查已冻结（铁律 #12）

### 前端
- [ ] 静态资源已压缩（CSS/JS minify）
- [ ] 图片已压缩并配置响应式尺寸
- [ ] 模板中无冗余 `<asset-css>`/`<asset-js>` 声明
- [ ] 不需要的 CSS/JS 已从 `assets` 配置中移除

---

## 四、数据库与分区

### 数据一致性
- [ ] 新增字段包含适当的默认值或迁移脚本
- [ ] `install.php` 已具备幂等性（`IF NOT EXISTS` / `CREATE TABLE IF NOT EXISTS`）
- [ ] `uninstall.php` 已正确清理：DROP TABLE + 注销分区 + 清理设置
- [ ] 权限更新通过 `GroupService::update()` 封装类实现，非 raw SQL（铁律 #21）

### 分区维护
- [ ] 新建 RANGE 分区表时，`PARTITION pmax VALUES LESS THAN MAXVALUE` 已创建
- [ ] `PartitionManager::maintain()` 已在计划任务或插件 install 中注册
- [ ] 过期数据清理策略已确认（保留期限 + 清理频率）

---

## 五、国际化 (I18n)

- [ ] 新增语言键已在全部 14 个 locale 目录下补充翻译（铁律 #3）
- [ ] 前后台语言键放置正确：前台键在 `language.php`，后台键在 `admin.php`
- [ ] 跨前后台共用的键两边都已存在（不存在自动 fallback）
- [ ] 语言占位符替换已在 `$this->language->get('key', ['name' => $value])` 完成，无 `str_replace` 二次替换（铁律 #26）
- [ ] 已清理 `storage/tmp/langs/` 重新压平语言缓存

---

## 六、路由与配置

- [ ] 所有路由 path 使用 PascalCase，无横杠 `-` 或下划线 `_`（铁律 #24）
- [ ] 路由 path 对齐 `ControllerClass + MethodName`
- [ ] 所有 POST 路由的 `requiresCsrf` 已配置 `'enable' => true`
- [ ] 路由 `meta` 元数据完整（`layout`、`permission`、`api` 等）
- [ ] 表单内无 `name="action"` 字段（使用 `_action`）（铁律 #15）
- [ ] 已知 URL 前缀正确使用：`admin`/`api` 小写，其余 PascalCase

---

## 七、基础设施

### 部署验证
- [ ] 部署前确认 PHP 版本兼容性（7.2+ 兼容至 8.5）
- [ ] DB 迁移脚本已就绪且可回滚
- [ ] Redis/Memcached 连接配置正确
- [ ] DNS 和 SSL/TLS 已配置
- [ ] CDN 已就绪（静态资源分发）

### 监控与日志
- [ ] 错误日志输出已配置（`FileLogger`/`SysLogger`）
- [ ] 关键操作已埋点审计日志
- [ ] `ThrottleMiddleware` 限流配置已启用（API 端点）
- [ ] 健康检查端点可正常响应

### 回滚计划
- [ ] 回滚触发条件已明确（错误率 > 2x 基线 / P95 延迟 > 50% / 数据完整性异常）
- [ ] 回滚步骤已记录：回滚代码版本 → 清除缓存 → 回滚 DB（如需要）→ 验证回滚
- [ ] 回滚时间目标已确认：
  - Feature flag 回滚：< 1 分钟
  - 代码版本回滚：< 5 分钟
  - 数据库回滚：< 15 分钟
- [ ] 回滚前通知团队

---

## 八、发布后验证（首小时）

- [ ] 首页正常响应（HTTP 200）
- [ ] 后台登录正常
- [ ] 新增/修改功能的核心流程走通
- [ ] 错误率无异常上升（比对发布前基线）
- [ ] 延迟无异常上升（p50/p95/p99）
- [ ] 日志正常输出、可读
- [ ] 静态资源正常加载（CSS/JS/图片无 404）
- [ ] 缓存层工作正常（Redis/APCu 命中率正常）

---

## 反合理化表

| 合理化借口 | 反驳 | 真实后果 |
|-----------|------|---------|
| "就一个小改动，不需要走完整清单" | 80% 的生产事故来自"小改动" | 凌晨被叫醒回滚 + 影响用户数小时 |
| "预发布环境测过了，线上一样" | 生产环境的数据量/流量/配置与预发布不同 | 真实流量下索引失效、缓存穿透、连接池耗尽 |
| "周二下午推没问题" | 发布避开周五下午是对的，但≠随时可发 | 无回滚计划 + 无监控 = 用户先发现故障 |
| "上线后再加监控" | 没有上线前的基线，上线后无法判断是否异常 | 出了故障不知道是新代码还是旧问题 |
| "回滚太麻烦，直接修" | 热修引入的风险高于回滚 | 热修制造新 bug，问题连锁扩大 |
| "Feature flag 只给大功能用" | 任何新功能都可能出问题，flag = 紧急关闭开关 | 出问题时只能回滚全部代码而不是关闭单个功能 |
| "日活低，不用操心性能" | 性能问题在数据量增长后爆发，那时重构代价 10x | 用户增长后被性能问题卡住，代码重写成本极高 |

---

*最后更新: 2026-06-15 - 从 agent-skills 工程流程规范汲取结构模式，适配 WellCMS 3.0 专有约束*
