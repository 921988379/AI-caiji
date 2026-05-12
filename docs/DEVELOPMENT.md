# WP 采集助手开发维护文档

本文档面向后续维护者，说明当前插件结构、重构策略和安全改动原则。

## 当前结构

```text
wp-caiji/
├── wp-caiji.php                         插件入口
├── uninstall.php                        卸载保护
├── readme.txt                           用户文档
├── assets/
│   ├── admin.css                        后台样式
│   └── admin.js                         后台交互
├── docs/
│   └── DEVELOPMENT.md                   开发维护文档
└── includes/
    ├── class-wp-caiji.php               当前主业务类，历史逻辑集中处
    └── core/
        ├── class-wp-caiji-loader.php    模块加载器
        ├── class-wp-caiji-utils.php     通用工具方法
        └── class-wp-caiji-schema.php    表名/字段元信息
```

## 为什么没有一次性拆完？

`includes/class-wp-caiji.php` 已承载完整业务，包括：

- 数据库建表和迁移
- 后台页面
- 队列处理
- Cron 和任务锁
- 抓取、解析、清洗
- 图片本地化
- SEO 字段
- 健康检查
- 导入导出

一次性拆成多个类风险较高，容易引入回归问题。当前采用“渐进式重构”：

1. 先建立 `includes/core/` 和 Loader。
2. 新增工具类和 Schema 类。
3. 后续新功能优先写入独立类。
4. 逐步把主类中的稳定方法迁移出去。
5. 每次迁移后做 PHP 语法检查和后台手动验证。

## 后续推荐拆分顺序

优先拆低耦合部分：

1. `WP_Caiji_Schema`
   - 迁移 `create_tables()`
   - 迁移 `maybe_add_column()`
   - 迁移默认字段列表

2. `WP_Caiji_Health`
   - 迁移 `render_health()`
   - 迁移 `health_action()`
   - 迁移 `export_diagnostics()`

3. `WP_Caiji_Parser`
   - 迁移 `selector_to_xpath()`
   - 迁移 `query_nodes()`
   - 迁移 `extract()`
   - 迁移 `extract_links()`
   - 迁移 `clean_content()`

4. `WP_Caiji_Fetcher`
   - 迁移 `fetch()`
   - 迁移 User-Agent/Referer/Cookie 逻辑

5. `WP_Caiji_Queue`
   - 迁移 `enqueue_url()`
   - 迁移 `collect_pending()`
   - 迁移 `collect_queue_item()`
   - 迁移 `mark_failed()`

6. `WP_Caiji_Admin`
   - 最后拆，因为页面多、耦合最多

## 改动原则

- 每次只迁移一个职责，不要大爆炸式重构。
- 保持公共入口和菜单 slug 不变。
- 保持数据库表名不变。
- 保持已有 option key 不变：`wp_caiji_settings_v2`。
- 保持 Cron hook 不变：
  - `wp_caiji_cron_discover`
  - `wp_caiji_cron_collect`
- 每次改动后至少执行：

```bash
php -l wp-caiji.php
php -l includes/class-wp-caiji.php
find includes -name '*.php' -print -exec php -l {} \;
```

## 数据库表

当前表：

```text
{prefix}caiji_rules
{prefix}caiji_queue
{prefix}caiji_logs
```

字段迁移通过 `dbDelta()` + `maybe_add_column()` 双保险。

## 卸载策略

默认卸载不删除数据。只有设置中开启：

```text
delete_data_on_uninstall = 1
```

`uninstall.php` 才会删除规则、队列、日志和设置。

## 发布前检查清单

- PHP 语法检查通过。
- 后台菜单可进入。
- 规则保存正常。
- 列表页测试可运行。
- 文章测试可运行。
- 队列页可筛选/分页。
- 日志页可筛选/分页。
- 健康检查页可打开。
- 不要默认删除用户数据。

## 合规提醒

插件提供采集能力，但使用者需要确保遵守目标站版权、robots.txt 和服务条款。

## 2026-05 DB/Schema 层拆分记录

已新增：

```text
includes/core/class-wp-caiji-db.php
```

已迁移到 `WP_Caiji_DB`：

- `create_tables()`
- `maybe_add_column()`
- `default_settings()`
- `default_rule()`

主类 `WP_Caiji` 中保留兼容包装方法：

```php
WP_Caiji::create_tables()       -> WP_Caiji_DB::create_tables()
WP_Caiji::maybe_add_column()    -> WP_Caiji_DB::maybe_add_column()
WP_Caiji::default_settings()    -> WP_Caiji_DB::default_settings()
$this->default_rule()           -> WP_Caiji_DB::default_rule()
```

这样可以保证旧调用不失效，同时让后续数据库相关维护集中到 DB 类。

下一步推荐迁移：Health 层。

建议新增：

```text
includes/core/class-wp-caiji-health.php
```

候选迁移方法：

- `render_health()`
- `health_action()`
- `export_diagnostics()`

迁移时同样保留主类包装方法，避免菜单回调和 admin_post action 失效。

## 2026-05 Health 层拆分记录

已新增：

```text
includes/core/class-wp-caiji-health.php
```

已迁移到 `WP_Caiji_Health`：

- `render_health()` → `WP_Caiji_Health::render($plugin)`
- `health_action()` → `WP_Caiji_Health::handle_action($plugin)`
- `export_diagnostics()` → `WP_Caiji_Health::export_diagnostics($plugin)`

主类 `WP_Caiji` 保留兼容包装方法，后台菜单和 admin_post action 不需要变更。

为支持 Health 类访问必要状态，主类新增只读访问器：

- `admin_page_url()`
- `rules_table()`
- `queue_table()`
- `logs_table()`

下一步推荐迁移：Parser 层。

建议新增：

```text
includes/core/class-wp-caiji-parser.php
```

候选迁移方法：

- `selector_to_xpath()`
- `xpath_literal()`
- `query_nodes()`
- `extract()`
- `extract_links()`
- `clean_content()`
- `absolute_url()`

## 2026-05 Parser 层拆分记录

已新增：

```text
includes/core/class-wp-caiji-parser.php
```

已迁移到 `WP_Caiji_Parser`：

- `extract_links()`
- `extract()`
- `query_nodes()`
- `selector_to_xpath()`
- `xpath_literal()`
- `clean_content()`
- `absolute_url()`

主类 `WP_Caiji` 保留同名 private 包装方法，内部转发到 `WP_Caiji_Parser`，避免影响原有业务调用。

迁移注意：

- 静态 Parser 类不能使用 `$this`。
- URL 标准化调用 `WP_Caiji_Utils::normalize_url()`。
- DOM/XPath 相关逻辑集中在 Parser 类，后续增强选择器能力应优先改这里。

下一步推荐迁移：Fetcher 层。

建议新增：

```text
includes/core/class-wp-caiji-fetcher.php
```

候选迁移方法：

- `fetch()`
- `get_rule_for_headers()`
- `pick_user_agent()`

## 2026-05 Fetcher 层拆分记录

已新增：

```text
includes/core/class-wp-caiji-fetcher.php
```

已迁移到 `WP_Caiji_Fetcher`：

- `fetch()`
- `get_rule_for_headers()`
- `pick_user_agent()`

主类 `WP_Caiji` 保留同名 private 包装方法，内部转发到 `WP_Caiji_Fetcher`。

为支持 Fetcher 写日志，主类新增：

- `log_public()`

迁移注意：

- Fetcher 是静态类，不使用 `$this`。
- SQL 中需要先把表名取到局部变量，避免字符串插值方法调用。
- 后续增强代理、429 冷却、重试策略、域名限速时，应优先改 `WP_Caiji_Fetcher`。

下一步推荐迁移：Media 层。

建议新增：

```text
includes/core/class-wp-caiji-media.php
```

候选迁移方法：

- `download_images()`
- 图片 ALT 写入逻辑
- 特色图逻辑相关辅助

## 2026-05 Media 层拆分记录

已新增：

```text
includes/core/class-wp-caiji-media.php
```

已迁移/补齐：

- `download_images()` → `WP_Caiji_Media::download_images()`
- 图片 sideload
- 图片 ALT 写入
- 第一张图特色图 ID 捕获

主类 `WP_Caiji` 保留 `download_images()` 包装方法。

本轮同时补齐了此前拆分后仍被业务调用的辅助方法：

- `make_excerpt()`
- `render_template()`
- `write_seo_meta()`

下一步推荐迁移：Content/SEO 层。

建议新增：

```text
includes/core/class-wp-caiji-content.php
```

候选迁移方法：

- `make_excerpt()`
- `render_template()` 可继续保留 Utils 代理
- `write_seo_meta()`
- `match_category_id()`
- `match_auto_tags()`
- `apply_replacements()`
- `parse_tags()`

## 2026-05 Content/SEO 层拆分记录

已新增：

```text
includes/core/class-wp-caiji-content.php
```

已迁移到 `WP_Caiji_Content`：

- `make_excerpt()`
- `render_template()`
- `write_seo_meta()`
- `match_category_id()`
- `match_auto_tags()`
- `apply_replacements()`
- `parse_tags()`

主类 `WP_Caiji` 保留同名 private 包装方法，内部转发到 `WP_Caiji_Content`，避免影响采集发布流程。

职责划分：

- 摘要生成：`make_excerpt()`
- SEO 元数据写入：`write_seo_meta()`
- 模板变量渲染：`render_template()` / `WP_Caiji_Utils::render_template()`
- 分类匹配：`match_category_id()`
- 自动标签匹配：`match_auto_tags()`
- 关键词替换：`apply_replacements()`
- 固定标签解析：`parse_tags()`

下一步推荐迁移：Queue/Collector 层。

建议新增：

```text
includes/core/class-wp-caiji-queue.php
includes/core/class-wp-caiji-collector.php
```

低风险优先候选：

- `post_exists_by_source()`
- `post_exists_by_title()`
- `mark_failed()`
- `release_stuck_running()`

## 2026-05 Queue 辅助层拆分记录

已新增：

```text
includes/core/class-wp-caiji-queue.php
```

已迁移到 `WP_Caiji_Queue`：

- `release_stuck_running()`
- `mark_failed()`
- `post_exists_by_source()`
- `post_exists_by_title()`

主类 `WP_Caiji` 保留同名 private 包装方法，内部转发到 `WP_Caiji_Queue`。

本轮同时补齐公开日志桥接：

- `log_public()`

用途：供 Fetcher/Queue 等独立类写入采集日志，而不直接访问主类私有 `log()`。

迁移注意：

- SQL 中表名先取局部变量，例如 `$queue_table = $plugin->queue_table();`。
- Queue 类可以引用 `WP_Caiji::META_SOURCE_URL`，但不直接访问主类私有属性。
- 完整 `collect_queue_item()` 暂不迁移，避免一次性牵动发布、图片、SEO、队列状态等链路。

下一步推荐迁移：Logger 层或 Collector 编排层。

较低风险建议：先拆 Logger 层。

建议新增：

```text
includes/core/class-wp-caiji-logger.php
```

候选迁移方法：

- `log()`
- 日志保留清理逻辑

## 2026-05 Logger 层拆分记录

已新增：

```text
includes/core/class-wp-caiji-logger.php
```

已迁移到 `WP_Caiji_Logger`：

- `log()` 的日志写入逻辑
- 日志保留清理逻辑 `trim()`

主类 `WP_Caiji` 保留：

- private `log()` 包装方法
- public `log_public()` 桥接方法

职责划分：

- `WP_Caiji_Logger::log()`：写入 logs 表，并触发保留清理。
- `WP_Caiji_Logger::trim()`：按 `log_retention` 限制保留最近日志。

迁移注意：

- Logger 通过 `get_option(WP_Caiji::OPTION_SETTINGS, array())` + `WP_Caiji_DB::default_settings()` 读取设置，避免调用主类 private `get_settings()`。
- SQL 中 logs 表名先取局部变量 `$logs_table = $plugin->logs_table();`。
- 其他模块仍可通过 `$plugin->log_public()` 写日志，不直接访问主类 private `log()`。

下一步推荐迁移：Collector 编排层的低风险子步骤，或先拆 Admin UI 层。若继续偏稳，建议先拆 Collector 的发布辅助方法。

## 2026-05 Publisher 发布层拆分记录

已新增：

```text
includes/core/class-wp-caiji-publisher.php
```

已迁移到 `WP_Caiji_Publisher::publish()`：

- 发布状态处理
- 随机未来发布时间计算
- 摘要生成
- `$postarr` 构建
- 分类匹配
- 作者设置
- 固定标签和自动标签生成
- 来源日期转 `post_date`
- `wp_insert_post()`
- 来源 URL meta 写入
- SEO meta 写入
- 设置特色图

主类 `collect_queue_item()` 现在继续负责流程编排：

```text
队列置 running → 抓取 → 提取 → 替换 → 去重 → 清洗 → 图片 → 发布 → 更新队列/规则状态 → 日志
```

迁移注意：

- Publisher 调用 `WP_Caiji_Content` 处理摘要、分类、标签、SEO。
- Publisher 返回 `int post_id` 或 `WP_Error`，由主类继续决定队列失败处理。
- 不在 Publisher 中更新队列表状态，避免发布层和队列层职责混杂。

下一步推荐：拆 Collector/Discovery 的更小辅助方法，或先拆 Admin UI 渲染层。若继续保持低风险，建议下一轮迁移 Discovery 辅助层：`build_list_pages()`、`enqueue_manual_urls()`、`enqueue_url()`。

## 2026-05 Discovery 辅助层拆分记录

已新增：

```text
includes/core/class-wp-caiji-discovery.php
```

已迁移到 `WP_Caiji_Discovery`：

- `build_list_pages()`
- `enqueue_manual_urls()`
- `enqueue_url()`
- `parse_urls()`

主类 `WP_Caiji` 保留兼容包装：

- `build_list_pages()`
- `enqueue_manual_urls()`
- `enqueue_url()`
- `parse_urls()`
- `normalize_url()`

职责划分：

- `discover_rule()` 仍在主类中负责编排：读取规则、抓列表页、提取链接、更新规则发现时间、写日志。
- `WP_Caiji_Discovery` 负责 URL 列表生成、手动 URL 解析入队、单 URL 标准化/去重/入队。

本轮同时修复：

- 主类此前调用 `parse_urls()` / `normalize_url()`，但没有对应方法定义的兼容风险。
- 现在主类已补齐包装方法，分别转发到 `WP_Caiji_Discovery::parse_urls()` 和 `WP_Caiji_Utils::normalize_url()`。

迁移注意：

- URL 标准化统一使用 `WP_Caiji_Utils::normalize_url()`。
- URL 来源文章去重使用 `WP_Caiji_Queue::post_exists_by_source()`。
- SQL 中 queue 表名先取 `$queue_table = $plugin->queue_table();`。

下一步推荐：继续拆 Collector 编排层，优先迁移 `collect_pending()` 到独立类，保留 `collect_queue_item()` 在主类。

## 2026-05 Collector 批处理层拆分记录

已新增：

```text
includes/core/class-wp-caiji-collector.php
```

已迁移到 `WP_Caiji_Collector::collect_pending()`：

- 读取采集设置
- 释放超时 running 队列
- 构造 pending 队列查询条件
- 按规则或全局 batch limit 查询待采集队列
- 控制单次最大运行时间
- 循环调用单篇采集处理
- request delay 间隔控制

主类 `WP_Caiji` 保留：

- `collect_pending()` 包装方法，转发到 `WP_Caiji_Collector::collect_pending()`。
- `collect_queue_item()` 单篇采集主流程，暂不迁移。
- `collect_queue_item_public()` 桥接方法，供 Collector 调用主类私有单篇采集流程。

职责划分：

```text
WP_Caiji_Collector::collect_pending()
    负责批量调度、时间限制、队列查询、间隔控制

WP_Caiji::collect_queue_item()
    继续负责单篇：抓取 → 提取 → 清洗 → 图片 → 发布 → 更新状态
```

迁移注意：

- Collector 不直接访问主类私有属性。
- 表名通过 `$plugin->rules_table()` 和 `$plugin->queue_table()` 获取，并先赋值到局部变量。
- 日志通过 `$plugin->log_public()` 写入。
- 单篇采集通过 `$plugin->collect_queue_item_public()` 桥接，避免修改最敏感的采集主流程。

下一步推荐：拆 Cron/Action 编排层，或继续将 `collect_queue_item()` 内部再分成更小的私有步骤，而不是一次性整体迁移。
