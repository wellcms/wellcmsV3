# WellCMS 3.0 工业级分页规范 (Pagination Standards)

为了解决高并发、大数据量下分页性能退化及数据跳动问题，WellCMS 3.0 强制执行游标分页标准。

## 1. 锚点锁 (maxId Anchor)
用户首次进入或刷新列表时，必须获取当前时间戳作为 `maxId`（锚点）。
- 后续翻页请求必须携带此 `maxId`。
- SQL 查询条件必须包含：`'created_at' => ['<=' => $maxId]`。
- **作用**：锁定查询瞬间的时间快照，防止翻页过程中新插入的数据挤乱原有列表。

## 2. 游标双向探测 (Bidirectional Cursor)
必须使用 `fetchPaged` 方法替代传统的 `LIMIT OFFSET`。
*   **向下翻页**：使用当前页最后一条数据的 ID 作为 `cursorId`，`dirFlag` 设为 `next`。
*   **向上翻页**：使用当前页第一条数据的 ID 作为 `cursorId`，`dirFlag` 设为 `previous`。

## 3. 统一分页链接 (Unified Pager)
必须在控制器中调用 `buildPaginationLinks` 统一构建分页数据。

### hasNext 判定逻辑
```php
($dirFlag === 'next' ? $hasMore : true)
```
- 向后翻页时使用真实探测结果 `$hasMore`。
- 向前翻页时为了保证 UI 体验，链接状态建议设为 `true`。

## 5. 参考实现 (Reference Implementation)
*   **列表页分页标准**：[ForumController.php](/plugins/well_forum/Controllers/Frontend/ForumController.php) (主题列表加载)
*   **详情页双轴分页标准**：[ThreadController.php](/plugins/well_forum/Controllers/Frontend/ThreadController.php) (回复列表加载)
