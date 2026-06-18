# WellCMS 3.0 调试与错误恢复规范 (Debugging & Error Recovery)

**定位**：流程式参考文档，不注册为 Claude Code skill。需要调试时由 AI 直接参照执行。

---

## 停线协议 (Stop-the-Line Rule)

当出现非预期错误时，严格按以下顺序执行：

```
① 停止 → ② 保全证据 → ③ 诊断 → ④ 修复根因 → ⑤ 防止复现 → ⑥ 验证后恢复
```

**严禁**：跳过诊断直接修、不保全证据就重试、修复后不写回归防护。

---

## WellCMS 诊断三步法

### Step 1: 复现 (Reproduce)

收集以下信息后再动手：

- **错误信息**：PHP 错误日志（`storage/logs/` 或 `syslog`）的完整堆栈
- **触发路径**：请求的完整 URL（含 `.html` 后缀）+ `GET`/`POST` 参数
- **DEBUG 模式**：当前 `DEBUG` 级别（`0`=生产冻结 / `1`=调试 / `2`=开发）
- **编译缓存状态**：`storage/tmp/classes/compile_manifest.php` 是否存在、时间戳

**检查清单**：
- [ ] 完整错误堆栈已保存
- [ ] 请求参数已记录
- [ ] 浏览器/客户端返回的 HTTP 状态码
- [ ] 是否是偶发性问题（协程竞态？缓存过期？）

### Step 2: 定位 (Localize)

按以下层级逐层缩小范围：

| 层级 | 诊断手段 | WellCMS 特有检查点 |
|------|---------|-------------------|
| ① 编译层 | 检查 `compile_manifest.php` 是否完整 | Hook 注入遗漏？`<?php exit;` 头缺失？FQCN 用了 `use`？ |
| ② 路由层 | `CompiledRouter::match()` 是否命中 | 路由 path 是否对齐方法名？`meta` 配置是否完整？ |
| ③ 中间件层 | MetaResolver → Middleware 链是否触发 | `requiresUserPerm` 缺少 `'enable' => true`？ |
| ④ 控制器层 | Action 签名是否正确 | `$request` 参数缺了？返回类型声明不对？ |
| ⑤ 服务层 | Service 能否正常从容器获取 | 构造函数签名冲突？LazyLoadingProxy TypeError？ |
| ⑥ 模型层 | SQL 是否按预期执行 | `$condition` 索引顺序不匹配？`array_values()` 漏了？ |
| ⑦ 模板层 | 模板路径能否解析 | `views/htm/` 路径错了？`render()` 第三个参数对了？ |

**特别检查**：
- 「页面白屏」→ 先切 `DEBUG=2` 看错误输出
- 「部分用户报错、部分正常」→ 检查缓存（Redis/APCu）污染或协程状态泄露
- 「新部署后出问题」→ 检查 `storage/tmp/` 编译缓存是否清除
- 「Hook 不生效」→ `compile_manifest.php` 中该 Hook 是否存在？Hook 文件是否被 `sanitizeHookCode()` 跳过？

### Step 3: 修复根因 (Fix Root Cause)

**场景对照表**：

| 症状 | 常见根因 | 正确修复 |
|------|---------|---------|
| 路由 404 | path 用了横杠/下划线 | 改为 PascalCase 对齐方法名（铁律 #24） |
| POST 请求被拦截 | 路由缺少 `requiresCsrf` 声明 | 补充 `'requiresCsrf' => ['enable' => true, 'ttl' => 3600]` |
| 模板空白 | `views/htm/` 路径错误 | 移到 `plugins/{name}/views/htm/` 下 |
| Hook 注入后报错 | `use` 语句冲突 / 注释含 `<?php` | 使用 FQCN，检查注释文本 |
| 控制器 TypeError | 声明了具体 Service 返回类型的私有 getter | 内联 `$this->container->get()` 调用 |
| `update()` 写入了 `"Array"` | 增量操作用了嵌套数组语法 | 改为键名后缀 `field+` / `field-` |
| 分页错误 | 用了 `OFFSET` | 改为游标分页（铁律 #20） |
| 缓存数据错乱 | 二进制数据未 `bin2hex()` 直接存入 Session | 先转换再存储 |
| 语言键不显示 | 键在 `language.php` 但后台加载的是 `admin.php` | 在对应文件补充定义 |

---

## 错误模式快速诊断表

| 错误信息 | 大概率原因 | 排查路径 |
|---------|-----------|---------|
| `Class "LazyLoadingProxy" not found` | 控制器 getter 声明了具体 Service 返回类型 | 去掉返回类型声明，改为内联调用 |
| `Cannot use ... as Router because the name is already in use` | Hook 文件用了 `use` 语句 | 改为 FQCN |
| `Malformed hook file skipped` | Hook 文件注释中含 `<?php` | 删除注释中的 PHP 标签字面量 |
| `Undefined array key` | read() 返回单行但被当多行遍历 | 确认使用 read()(单行)还是 find()(多行) |
| `Array to string conversion` | update() 第二个参数含嵌套数组 | 改为键名后缀语法 |
| `Call to undefined method LazyLoadingProxy::xxx()` | 容器返回的是代理但方法不存在 | 检查 Service 是否已注册到容器 |
| 模板中 `$view->e($var)` 返回空 | `$var` 是 foreach 循环变量 | 改用 `htmlspecialchars($var)` |

---

## 防止复现 (Guard Against Recurrence)

修复完成后必须做以下至少一项：

- [ ] 给相关模型/Service 写单元测试（PHPUnit，通过 `well_store_server` 插件）
- [ ] 在 `Common_Pitfalls.md` 中新增一条陷阱记录
- [ ] 给关键路径添加校验断言（输入校验、中间件断言）
- [ ] 删除编译缓存并确认重新压平后问题不重现

---

## 验证门禁 (Verification Gates)

- [ ] 根因已识别并记录
- [ ] 修复针对根因而非症状
- [ ] 无修复的回归测试或陷阱文档
- [ ] 修复后全量功能正常
- [ ] `storage/tmp/` 编译缓存已清除并重建

---

*本文档改编自 agent-skills `debugging-and-error-recovery`，2026-06-15 适配 WellCMS 3.0 专有约束*
