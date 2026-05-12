=== WP 采集助手 ===
Contributors: 一点优化
Requires at least: 5.8
Tested up to: 6.5
Requires PHP: 7.4
Stable tag: 2.1.2
License: GPLv2 or later

长期自动化文章采集插件。支持采集规则、列表页发现、分页发现、URL 队列、定时采集、失败重试、正文清洗、图片本地化、SEO 字段、随机延迟发布、健康检查和诊断报告。

== 核心功能 ==

* 规则管理：新增、编辑、复制、启用/停用、导入/导出。
* 列表页发现：从栏目页自动提取文章链接。
* 分页发现：支持 https://example.com/page/{page} 形式。
* 手动 URL：每行一个文章 URL，直接加入队列。
* URL 队列：pending、running、success、failed、skipped 状态。
* 定时采集：发现链接和采集文章分离。
* 任务锁：防止多个 Cron 同时运行。
* 防卡死：running 超时自动释放。
* 失败重试：按规则设置最大重试次数。
* 队列分页/筛选：按状态、规则、URL、错误搜索。
* 日志分页/筛选：按级别、规则、关键词搜索。
* 健康检查：数据库表、Cron、任务锁、失败率、最近错误。
* 维护工具：修复表、释放 running、重建 Cron、清理锁、清空日志。
* 诊断报告：导出 JSON 供排查问题。

== 内容处理 ==

* XPath 和简单 CSS 选择器。
* 标题、正文、日期提取。
* 文章测试预览。
* 列表页测试预览。
* 删除指定选择器。
* 删除空段落。
* 删除外链但保留文字。
* 删除包含关键词的段落。
* 关键词替换：原词=>新词。
* 图片本地化。
* 第一张图设为特色图。
* 图片 ALT 模板。
* 自动摘要。

== 发布与 SEO ==

* 支持草稿、发布、待审、定时发布。
* 随机延迟发布，避免一次性发布过多文章。
* 默认分类 ID。
* 关键词自动分类：关键词=>分类ID。
* 固定标签。
* 自动标签。
* 作者 ID。
* Rank Math SEO 字段。
* Yoast SEO 字段。
* All in One SEO 字段。

== 反爬/请求配置 ==

* User-Agent 池，每次随机选择。
* Referer。
* Cookie。
* 请求间隔。
* 全局单次采集数量限制。
* 单次 Cron 最大运行时间。

== 安装 ==

1. 将插件目录放到：
   wp-content/plugins/wp-caiji
2. 在 WordPress 后台启用“WP 采集助手”。
3. 进入“WP 采集 → 设置”，确认全局设置。
4. 进入“WP 采集 → 采集规则”，新增采集规则。

== 推荐服务器 Cron ==

WP-Cron 依赖访问触发。长期运行建议服务器添加：

*/10 * * * * curl -s https://你的域名/wp-cron.php?doing_wp_cron >/dev/null 2>&1

当前站点示例：

*/10 * * * * curl -s https://cm.seoyh.net/wp-cron.php?doing_wp_cron >/dev/null 2>&1

== 选择器示例 ==

标题：

//h1
h1
.article-title

正文：

//article
.article-content
.post-content

文章链接：

.news-list a
//div[contains(@class,"news-list")]//a

== 规则配置建议 ==

测试阶段：

* 文章状态：草稿
* 每批采集：3
* 失败重试：3
* 请求间隔：2 秒
* 开启标题去重
* 开启删除空段落
* 先使用文章测试预览和列表页测试预览

长期运行：

* 发布节奏：随机延迟发布
* 延迟：30 - 180 分钟
* 自动摘要：160 字
* SEO 描述：{excerpt}
* 图片 ALT：{title}
* 定期清理成功队列
* 定期查看健康检查

== 模板变量 ==

SEO 标题、SEO 描述、图片 ALT 支持：

* {title} 文章标题
* {excerpt} 文章摘要
* {site} 站点名称
* {source} 来源 URL

示例：

SEO 标题：{title} - {site}
SEO 描述：{excerpt}
图片 ALT：{title}

== 自动分类规则 ==

每行一条：

关键词=>分类ID

示例：

WordPress=>3
SEO=>5
服务器=>8

匹配标题或正文后，会优先使用命中的分类。未命中时使用默认分类 ID。

== 关键词替换 ==

每行一条：

原词=>新词

示例：

旧品牌=>新品牌
example.com=>cm.seoyh.net

会同时作用于标题和正文。

== 健康检查 ==

进入：

WP 采集 → 健康检查

可查看：

* 数据库表状态
* 队列数量
* 失败率
* 卡住 running 数量
* 最近成功采集
* 最近错误
* Cron 状态
* 任务锁状态

维护工具：

* 修复/创建数据表
* 释放 running 队列
* 重建定时任务
* 清理任务锁
* 清空日志
* 导出诊断 JSON

== 卸载保护 ==

默认卸载插件不会删除规则、队列、日志和设置。

如需卸载时删除全部数据，请进入：

WP 采集 → 设置 → 卸载保护

勾选“卸载插件时删除规则、队列、日志和设置”。

== 合规提醒 ==

请确认采集行为符合目标网站 robots.txt、版权声明和服务条款。建议优先采集为草稿，人工检查后再发布。

== 更新记录 ==

= 2.0.0 =
* 队列化长期采集架构。
* 新增列表页发现、分页发现、任务锁、防卡死。
* 新增 SEO、摘要、图片、清洗、自动分类标签。
* 新增健康检查、诊断报告、卸载保护。
* 新增后台 CSS/JS 和分区折叠体验。

== 开发维护 ==

插件已加入渐进式模块化基础：

* includes/core/class-wp-caiji-loader.php
* includes/core/class-wp-caiji-utils.php
* includes/core/class-wp-caiji-schema.php
* docs/DEVELOPMENT.md

当前主业务仍在 includes/class-wp-caiji.php 中，以保持稳定。后续新功能建议优先放入独立类，再逐步迁移主类逻辑。

= 2.0.1 =
* 新增 DB/Schema 层拆分基础。
* 新增 includes/core/class-wp-caiji-db.php。
* 建表、字段迁移、默认设置、默认规则已迁移到 WP_Caiji_DB。
* 主类保留兼容包装，降低升级风险。

= 2.0.2 =
* 新增 Health 层拆分。
* 新增 includes/core/class-wp-caiji-health.php。
* 健康检查、维护动作、诊断报告导出已迁移到 WP_Caiji_Health。
* 主类保留兼容包装，后台菜单和 action 不变。

= 2.0.3 =
* 新增 Parser 层拆分。
* 新增 includes/core/class-wp-caiji-parser.php。
* 选择器转换、DOM 查询、正文提取、链接提取、正文清洗、相对 URL 处理已迁移到 WP_Caiji_Parser。
* 主类保留兼容包装，原有业务调用不变。

= 2.0.4 =
* 新增 Fetcher 层拆分。
* 新增 includes/core/class-wp-caiji-fetcher.php。
* HTTP 抓取、User-Agent 池、Referer、Cookie 请求头逻辑已迁移到 WP_Caiji_Fetcher。
* 主类保留兼容包装，原有业务调用不变。

= 2.0.5 =
* 新增 Media 层拆分。
* 新增 includes/core/class-wp-caiji-media.php。
* 图片本地化、图片 sideload、图片 ALT 写入、特色图 ID 捕获迁移到 WP_Caiji_Media。
* 补齐摘要和 SEO 辅助方法，保证采集发布链路完整。

= 2.0.6 =
* 新增 Content/SEO 层拆分。
* 新增 includes/core/class-wp-caiji-content.php。
* 摘要生成、SEO meta 写入、分类匹配、自动标签、关键词替换、固定标签解析迁移到 WP_Caiji_Content。
* 主类保留兼容包装，原有采集发布流程不变。

= 2.0.7 =
* 新增 Queue 辅助层拆分。
* 新增 includes/core/class-wp-caiji-queue.php。
* running 超时释放、失败重试状态处理、来源 URL 去重、标题去重迁移到 WP_Caiji_Queue。
* 补齐 log_public() 日志桥接，供独立类安全写日志。
* 主类保留兼容包装，完整采集发布主流程不变。

= 2.0.8 =
* 新增 Logger 层拆分。
* 新增 includes/core/class-wp-caiji-logger.php。
* 日志写入和日志保留清理迁移到 WP_Caiji_Logger。
* 主类保留 log()/log_public() 兼容入口，独立模块仍可安全写日志。

= 2.0.9 =
* 新增 Publisher 发布层拆分。
* 新增 includes/core/class-wp-caiji-publisher.php。
* 文章发布准备、分类/标签、摘要、SEO meta、特色图设置迁移到 WP_Caiji_Publisher。
* collect_queue_item() 继续保留采集流程编排和队列状态更新，降低重构风险。

= 2.0.10 =
* 新增 Discovery 辅助层拆分。
* 新增 includes/core/class-wp-caiji-discovery.php。
* 列表页生成、手动 URL 入队、单 URL 标准化/去重/入队迁移到 WP_Caiji_Discovery。
* 补齐 parse_urls()/normalize_url() 兼容包装，避免发现入队流程缺方法。
* discover_rule() 继续保留主流程编排，降低重构风险。

= 2.0.11 =
* 新增 Collector 批处理层拆分。
* 新增 includes/core/class-wp-caiji-collector.php。
* collect_pending() 的队列批处理、最大运行时间控制、request delay 控制迁移到 WP_Caiji_Collector。
* collect_queue_item() 单篇采集主流程继续保留在主类，降低重构风险。
