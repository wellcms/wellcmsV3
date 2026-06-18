# WellCMS 3.0 规格驱动开发规范 (Spec-Driven Development)

**定位**：流程式参考文档，不注册为 Claude Code skill。新增功能或重大变更前由 AI 参照此流程编写规格文档。

---

## 适用范围

**使用条件**（满足任一即可）：
- 新增插件或插件主要功能模块
- 新增/修改路由 3 条以上
- 涉及数据库表变更（新增表、修改索引、分区变更）
- 涉及路由 meta 配置变更（权限/CSRF/限流）
- 跨多个文件（5+）的改动
- 可能影响现有功能的变更

**不需要的场景**：单行修复、纯模板样式调整、仅修改语言键值。

---

## 四阶段门控流程

每个阶段结束必须有人工审查门禁，**通过后才能进入下一阶段**。

### Phase 1: 规格说明 (Specify)

#### 1.1 需求陈述

用一两句话描述要做什么：

```
为 {谁} 新增/修改 {什么功能}，使其能够 {达到什么效果}。
```

**示例**：
> 为插件 `well_page` 新增「页面草稿」功能，使管理员可在发布前保存草稿并预览。

#### 1.2 暴露隐含假设

列出你认为"显然成立"的假设，让审查者纠正：

```
假设 1：草稿只有管理员可查看（依赖 requiresAdminSignIn）
假设 2：草稿不参与搜索引擎索引（header 不渲染）
假设 3：草稿存储在 page 主表，用 status=0 标记
→ 请确认以上假设是否成立（纠正我）
```

#### 1.3 规格文档六要素

每个规格文档必须覆盖以下 6 个方面：

##### ① 目标 (Objective)

- 功能的核心价值主张
- 成功标准（可量化，如"管理员可保存草稿并预览，不暴露给普通用户"）

##### ② 路由与 Meta 声明 (Routes)

列出所有新增/修改的路由，对齐铁律 #24（PascalCase 路径）和铁律 #8（Meta 驱动）：

```php
// 规格示例
Router::group(['prefix' => '/admin', 'meta' => [
    'requiresAdminSignIn' => true,
    'requiresUserPerm' => ['enable' => true, 'role' => ['administer']],
    'csrf_exempt' => false,
]], function () {
    // 页面渲染
    Router::get('/DraftList', [DraftController::class, 'list'], ['layout' => 'draft_list']);
    Router::get('/DraftEdit', [DraftController::class, 'edit'], ['layout' => 'draft_edit']);

    // POST 操作（必须声明 requiresCsrf）
    Router::post('/PostDraft', [DraftController::class, 'postDraft'], [
        'requiresCsrf' => ['enable' => true, 'ttl' => 3600],
    ]);
});
```

##### ③ 控制器与 Action 签名 (Controller)

- 控制器类名（PascalCase，后缀 `Controller`）
- 每个 Action 的方法名 + 签名（必须含 `$request` 参数 + `ResponseInterface` 返回类型）
- 使用 `FrontendTrait` / `AdminTrait`
- 模板名从路由元数据读取（铁律 #14）

```php
// 规格示例 - Action 签名
class DraftController extends BaseController
{
    use \App\Traits\Admin\AdminTrait;

    public function list(
        \Framework\Http\Interfaces\ServerRequestInterface $request
    ): \Framework\Http\Interfaces\ResponseInterface {
        $routeMeta = $request->getAttribute('_route_meta');
        return $this->render($routeMeta['layout'], $data, 'well_page');
    }

    public function postDraft(
        \Framework\Http\Interfaces\ServerRequestInterface $request
    ): \Framework\Http\Interfaces\ResponseInterface {
        // 控制器内零 CSRF 代码（铁律 #8）
        // 控制器内无 ?? 兜底（铁律 #25）
    }
}
```

##### ④ 数据库变更 (Database)

- 新增表名、字段、索引定义（对齐安装规约）
- 索引顺序必须与 `$condition` 查询顺序一致（铁律 #19）
- 分区策略（RANGE/HASH/组合）
- 冗余统计字段设计（铁律 #16）

```sql
-- 规格示例 - 建表
CREATE TABLE IF NOT EXISTS `well_page_draft` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `page_id` int(10) unsigned NOT NULL DEFAULT '0',
  `content` text NOT NULL COMMENT '草稿内容',
  `status` tinyint(1) NOT NULL DEFAULT '0' COMMENT '0=草稿 1=已发布',
  `created_at` int(10) unsigned NOT NULL DEFAULT '0',
  `updated_at` int(10) unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  KEY `page_id` (`page_id`, `status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

##### ⑤ 服务层与模型 (Service & Model)

- 原子 Service 接口（insert/update/delete/read/find/count，对齐铁律 #18）
- Model 声明（仅需 `$table` 属性，继承 BaseModel）
- 跨插件调用路径（必须通过 Service 层，铁律 #15）

```php
// 规格示例
class DraftService {
    // 原子接口（一表一服务，铁律 #18）
    public function insert(array $data): int;
    public function update(array $condition, array $update): bool; // 增量用 field+
    public function delete(array $condition): bool;
    public function read(array $condition = []): array;
    public function find(array $condition, array $orderby, int $page, int $pageSize): array;
    public function count(array $condition = []): int;
}
```

##### ⑥ 边界条件 (Boundaries)

沿用 **Always Do / Ask First / Never Do** 三分法，但内容完全 WellCMS 化：

| 类别 | 内容 |
|------|------|
| **Always Do**（无例外） | 所有 POST 路由声明 `requiresCsrf`；控制器零 CSRF 代码；ID 参数 `(int)` 强转；查询条件对齐索引顺序；`WHERE IN` 前执行 `array_values()`；Hook 文件以 `<?php exit;` 开头且全 FQCN；语言包同时更新 `language.php` 和 `admin.php` |
| **Ask First**（需确认） | 新增数据库表；新增路由 5 条以上；变更分区策略；引入新的外部依赖；跨插件直接访问对方 Service；修改现有索引 |
| **Never Do**（严禁） | 使用 OFFSET 分页；实时 COUNT(*)；JOIN/子查询/视图；`??`/`?:` 兜底；空 catch；控制器定义构造函数；Hook 文件用 `use` 语句；模板 `name="action"`；修改主程序核心文件 |

---

### Phase 2: 技术方案 (Plan)

在规格文档批准后，编写技术实现方案：

#### 依赖图

```
数据库表新增 (Draft table)
  → DraftService (原子服务)
    → DraftController::list (渲染草稿列表)
    → DraftController::postDraft (保存草稿)
      → draft_list.htm (模板)
      → draft_edit.htm (模板)
    → WellPageService::publish (从草稿发布)
```

#### 风险与应对

| 风险 | 可能性 | 应对 |
|------|--------|------|
| 草稿 content 字段过大影响主表查询 | 低 | 独立草稿表，不与 page 主表 JOIN |
| 管理员同时编辑同一页面的草稿冲突 | 中 | 乐观锁 + updated_at 比对 |
| 编译缓存未清除导致 Hook 不生效 | 低 | install.php 中声明清理动作 |

---

### Phase 3: 任务分解 (Tasks)

将方案拆解为可独立完成的任务，每个任务满足：

- 完成时间 ≤ 1 个会话
- 涉及文件 ≤ 5 个
- 验收标准 ≤ 3 条
- 有明确的验证步骤

**任务模板**：
```markdown
### 任务 N: {任务标题}
**描述**：{一句话说明}
**涉及文件**：{文件路径列表}
**验收标准**：
- [ ] {验收条件 1}
- [ ] {验收条件 2}
**验证步骤**：
1. {验证命令或操作}
2. {预期结果}
```

---

### Phase 4: 增量实现 (Implement)

- 按任务顺序逐个实现（依赖优先）
- 每完成 2-3 个任务执行一次验证检查点
- 实现参考 `incremental-implementation` 增量构建原则
- 验证 API JSON 响应结构符合 `{status, code, message, data, timestamp}`（铁律 #5）
- 验证模板渲染使用路由元数据指定的 layout（铁律 #14）

---

## 规格文档模板

```markdown
# 规格：{功能名称}

## 目标
{一句话描述}

## 假设（请纠正）
- 假设 1：...
- 假设 2：...

## 路由声明
```php
// 路由定义
```

## 控制器 Action
```php
// Action 签名
```

## 数据库变更
```sql
-- DDL
```

## 服务接口
```php
// Service 接口
```

## 边界条件
- Always: {列表}
- Ask First: {列表}
- Never: {列表}

## 成功标准
- [ ] {可量化的标准 1}
- [ ] {可量化的标准 2}

## 开放问题
- {待确认的问题}
```

---

## 门禁验证

进入下一阶段前确认：
- [ ] 6 个要素全部覆盖（目标/路由/控制器/数据库/服务/边界）
- [ ] 所有假设已有人工确认
- [ ] 边界条件的 Always/Ask First/Never 已填写
- [ ] 成功标准可量化测试
- [ ] 规格文档已保存到 `docs/` 或插件目录

---

*本文档改编自 agent-skills `spec-driven-development`，2026-06-15 适配 WellCMS 3.0 专有约束（铁律 #5/#8/#14/#15/#18/#19/#20/#24/#25）*
