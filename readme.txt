=== WP 采集助手 ===
Contributors: 一点优化
Requires at least: 5.8
Tested up to: 6.5
Requires PHP: 7.4
Stable tag: 2.1.27
License: GPLv2 or later

长期自动化文章采集插件。支持采集规则、列表页发现、分页发现、URL 队列、定时采集、失败重试、正文清洗、图片本地化、SEO 字段、随机延迟发布、Tag 提取、健康检查和诊断报告。

== 核心功能 ==

* 规则管理：新增、编辑、复制、启用/停用、导入/导出。
* 列表页发现：从栏目页自动提取文章链接。
* 分页发现：支持 https://example.com/page/{page} 形式。
* 手动 URL：每行一个文章 URL，直接加入队列。
* URL 队列：pending、running、success、failed、skipped 状态。
* 定时采集：发现链接和采集文章分离，采集文章支持固定频率或随机延迟模式。
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
* 标题、正文、日期、Tag 标签提取。
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
* 默认分类下拉选择。
* 关键词自动分类：关键词=>分类ID。
* 固定标签。
* 自动标签：自动载入站点现有 Tag，可继续手动维护；仅标题命中关键词时加标签。
* 作者下拉选择。
* Rank Math SEO 字段。
* Yoast SEO 字段。
* All in One SEO 字段。

== 反爬/请求配置 ==

* User-Agent 池，每次随机选择。
* Referer。
* Cookie。
* 请求间隔。
* 全局单次采集数量限制。
* 采集文章随机延迟模式，可设置最小/最大分钟数。
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


== 远程请求与隐私说明 ==

本插件会根据管理员配置执行以下远程请求：

* 采集目标页面：向规则中的列表页 URL、分页 URL、手动文章 URL 和队列 URL 发起 HTTP/HTTPS 请求，用于提取文章链接、标题、正文、日期、Tag 和页面图片地址。
* 图片本地化：启用“图片本地化”时，会请求正文中的远程图片 URL，将图片下载到本站媒体库，并可设置第一张图为特色图。
* AI 改写：启用全局 AI 且规则开启“发布前 AI 改写”时，会把文章标题、正文片段、目标语言和管理员配置的 Prompt 发送到管理员填写的 OpenAI 兼容 AI Endpoint。API Key 按管理员要求明文保存在 WordPress 数据库选项中，后台设置页会明文显示；诊断导出会自动脱敏。
* 插件更新检查：插件会请求 GitHub API 获取 921988379/AI-caiji 的 Release 信息；执行插件更新时，会从 GitHub Release 下载 wp-caiji.zip。
* WP-Cron 触发：手动提交发现/采集任务时，插件可能请求本站 wp-cron.php?doing_wp_cron=... 来尽快触发后台任务。

安全限制：采集 URL、图片 URL 和 AI Endpoint 只允许公网 HTTP/HTTPS 地址，会拒绝 localhost、内网 IP 和保留地址；GitHub 更新包只接受 GitHub/GitHubusercontent 相关 HTTPS 下载地址。

本插件会在自定义数据库表中保存采集规则、队列 URL、采集状态、错误日志、来源 URL 和生成文章 ID。默认卸载插件不会删除这些数据；只有管理员在设置中开启“卸载插件时删除规则、队列、日志和设置”后，卸载时才会删除。

请在使用前确认采集行为符合目标网站 robots.txt、版权声明和服务条款，并在站点隐私政策中披露所使用的第三方 AI 服务、远程内容来源和图片下载行为。

== 权限、Nonce 与输出安全 ==

* 后台页面和管理动作默认要求 manage_options 权限；开发者可通过 wp_caiji_required_capability 过滤器调整所需权限。
* 规则、设置、队列、日志、健康检查、诊断导出、AI 测试、手动采集和手动 AI 改写等后台动作均使用 WordPress nonce 校验。
* 后台输出使用 esc_html、esc_attr、esc_url、esc_textarea 或 wp_kses_post 转义；发布文章正文会经过 wp_kses_post 清洗。
* 规则导入/导出使用字段白名单；导入时复用规则保存清洗逻辑，避免异常字段进入规则表。
* 诊断报告会对 AI API Key 和 GitHub Token 字段脱敏。

== 合规提醒 ==

请确认采集行为符合目标网站 robots.txt、版权声明和服务条款。建议优先采集为草稿，人工检查后再发布。

== 更新记录 ==

= 2.1.27 =
* 新增德语 de_DE、法语 fr_FR、印度尼西亚语 id_ID、印地语 hi_IN 语言包，并补齐对应 .po/.mo 文件。
* 默认 OpenAI 兼容中转站 Endpoint 调整为 https://api.seoyh.net/v1/chat/completions。
* 优化慢站点采集设置保存：放宽单次 Cron 最大运行、请求间隔和 AI 超时上限，避免保存后被强制截断。
* 改进设置保存容错：部分字段缺失时保留已有值，并为复选框增加隐藏默认值，减少卡顿或拦截导致的设置丢失。
* 采集运行、发现任务和 AI 请求同步支持新的慢站点超时/间隔配置。

= 2.1.26 =
* 完善全插件国际化：新增 I18n 核心类，按后台用户语言优先加载 languages 目录中的 .mo 文件。
* 新增英文 en_US 与简体中文 zh_CN 实际语言包，修复仅有 POT 模板导致后台切换语言不生效的问题。
* 扩充 en_US 语言包，收录历史 PHP/JS/HTML 中文静态文案，并为所有中文 msgid 生成非空英文翻译。
* 新增后台输出翻译兜底层，覆盖历史模板文本、placeholder、title、aria-label、data-confirm 等文案。
* 增加英文运行时兜底词典，减少动态拼接文案在英文后台继续显示中文的问题。

= 2.1.25 =
* 新增全插件国际化支持，声明 Domain Path 并统一加载 languages 目录翻译文件。
* 后台菜单、AI 服务商分类/费用/地区标签、核心提示和后台脚本文案接入 wp-caiji 文本域。
* 新增后台历史模板输出翻译层，覆盖页面文本节点、placeholder、title、aria-label、data-confirm 等属性文案。
* 新增 languages/wp-caiji.pot 翻译模板，并自动收录历史 PHP/JS/HTML 中文静态文案，便于后续生成各语言 po/mo 文件。
* 新增 zh_CN 与 en_US 语言包示例，并按后台用户语言优先加载对应 .mo 文件。

= 2.1.24 =
* AI 改写设置新增多层级服务商选择：先选“中转 / 免费 / 付费”，再选择对应 AI 平台。
* AI 平台按中国大陆、其他国家/海外、中转站自定义和费用类型区分展示。
* 新增 OpenAI 兼容中转站、OpenAI、DeepSeek、通义千问、Kimi、智谱、百川、MiniMax、xAI、OpenRouter、Claude、Gemini 等平台预设。
* 新增各 AI 平台“说明”弹窗，包含注册入口、API Key 获取步骤、插件填写方式和注意事项。
* 支持 Anthropic Claude 官方 Messages API 与 Google Gemini 官方 generateContent API，同时继续兼容 OpenAI 格式中转站。
* 默认中转站 Endpoint 调整为 https://api.seoyh.net/v1/chat/completions。

= 2.1.23 =
* 新增远程请求、采集、AI、图片下载、GitHub 更新检查和 WP-Cron 触发的完整披露说明。
* 新增权限、nonce、输出转义、规则导入白名单和诊断脱敏说明。
* 后台管理权限改为统一 helper，并提供 wp_caiji_required_capability 过滤器。
* 增加 WordPress 隐私政策建议内容，便于站点管理员复制到隐私政策。
* 采集请求在插件自有公网 URL 校验通过后允许公网自定义端口，避免 WordPress 默认安全端口限制拦截。

= 2.1.22 =
* 采集 URL 安全校验支持公网 HTTP/HTTPS 自定义端口，并在 URL 规范化时保留端口号。
* Referer 自动生成会保留目标 URL 的自定义端口。
* Cookie 池随机选择时增加控制字符过滤，防止导入规则绕过保存清洗。
* 默认 AI Prompt 修正来源 URL 说明，避免要求模型编造联网数据来源。
* 规则导入/导出改用统一白名单和清洗逻辑。
* 日志保留清理改为抽样执行，降低高频采集时的数据库压力。

= 2.1.21 =
* Tag 提取规则为空时不再解析整篇 HTML，避免误生成大量异常标签。
* 测试预览新增页面提取 Tag、固定 Tag、自动匹配 Tag 和最终写入 Tag 分项展示。
* 固定标签改为多行输入，数据库字段升级为 TEXT，支持更多标签和换行分隔。
* JSON Tag 对象优先读取 name/title/label/tag/term/text 字段，避免 id、slug、url 被误当作标签。
* 自动标签内容包含 => 或 | 时，即使未开启增强匹配也会按映射规则解析。
* Tag 写入失败时会记录采集日志，便于排查。

= 2.1.20 =
* 修复自动采集/Cron 发布文章时 Tag 标签可能未写入的问题，改为文章创建后显式写入 post_tag。
* 固定标签支持英文逗号、中文逗号、顿号、分号、竖线、斜杠和换行等多种分隔符。
* 修复规则导入导出时遗漏 auto_tag_advanced 增强匹配开关的问题。

= 2.1.19 =
* 修复 AI Endpoint 使用 HTTP 自定义端口时被 WordPress 默认安全端口校验拦截的问题。
* AI Endpoint 现在支持公网 HTTP/HTTPS 自定义端口的 OpenAI 兼容 Endpoint。
* 保留公网 IP 安全校验，仍会拒绝 localhost、内网 IP 和保留地址。

= 2.1.18 =
* AI API Endpoint 兼容公网 HTTP 地址，便于使用内网映射或不支持 HTTPS 的 OpenAI 兼容中转服务。
* AI Endpoint 后台说明更新为支持 HTTP/HTTPS，生产环境仍建议优先使用 HTTPS。
* 保留 Endpoint 自动补全逻辑，可继续填写基础地址、/v1 或完整 /chat/completions 地址。

= 2.1.17 =
* Referer 留空时自动随机生成来源页，包含目标站首页/栏目页和常见搜索引擎来源。
* Cookie 支持每行一组 Cookie，采集时随机选择一组发送。
* Cookie 留空时默认不发送，避免无效随机 Cookie 触发目标站风控。

= 2.1.16 =
* User-Agent 池留空时自动随机生成常见浏览器 User-Agent，不再使用固定插件标识。
* 自动 User-Agent 覆盖 Chrome、Edge、Safari、Android Chrome、iPhone Safari 等常见访问标识。
* 保留手动 User-Agent 池优先级；已填写时仍从手动列表随机选择。

= 2.1.15 =
* 修复发布文章时旧队列数据缺少 auto_tag_advanced 字段导致 PHP 未定义索引提示的问题。
* 自动标签增强匹配开关现在会在缺省数据下安全回退为关闭状态，提升升级后采集发布兼容性。

= 2.1.14 =
* 修复采集规则“分类与标签 → 自动标签”设置在部分站点无法保存的问题，补齐 auto_tag_advanced 数据库列升级逻辑。
* 新增保存规则时的数据库字段白名单过滤，避免未来字段升级未同步时导致整条规则保存失败。
* 在插件后台页面统一增加版权署名：“一点优化”（链接到 https://www.seoyh.net/）。

= 2.1.13 =
* 自动标签新增“增强匹配”开关，可按规则决定使用新版增强匹配或旧版纯包含匹配。
* 增强匹配支持“关键词=>标签名”与“关键词1|关键词2=>标签名”写法，便于别名归一到同一个 Tag。
* 改进英文短关键词匹配，尽量避免 AI、SEO、WP 等短词误命中其他单词片段。
* 自动标签最终写入前统一做更稳妥的规范化去重，减少大小写或重复写法导致的脏标签。

= 2.1.12 =
* 编辑采集规则体验优化：来源与文章 URL、文章字段提取改为先选择获取方式，再只显示对应规则编辑项，减少页面占用。
* WP 采集设置新增“随机延迟采集”模式，可在最小/最大分钟范围内随机安排下一次采集文章任务。
* WP 采集设置页面各板块默认收起，点击标题后再展开配置，减少后台页面占用。

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


= 2.1.11 =
* 编辑采集规则时，“分类与标签 → 自动标签”不再自动载入站点现有文章标签，只显示并保存手动填写的关键词。

= 2.1.10 =
* AI 改写增加目标语言选择，支持中文、英文、日文、韩文、西班牙文、法文、德文和不限制/不检测。
* 采集规则可单独覆盖全局默认改写语言，便于不同来源做翻译发布。
* 增加改写后语言检测；检测不符合目标语言时会判定 AI 改写失败，并按规则的失败处理策略执行。

= 2.1.9 =
* 明确限制自动添加文章 tag 标签只匹配标题关键词，正文内容命中不会作为自动 tag。
* 分类匹配逻辑保持不变，仍可按标题 + 正文内容匹配分类。

= 2.1.8 =
* AI API Key 改为明文保存，并在后台设置页明文显示。
* 升级时会将旧版已加密保存的 AI API Key 自动转换为明文保存。
* 诊断导出仍会对 AI API Key 脱敏，避免误泄露。

= 2.1.7 =
* 更新默认 AI 改写 Prompt，增加联网补充数据来源、正文尾部 FAQ 等要求。
* 升级时若站点仍使用旧默认 Prompt 或为空，会自动更新为新版默认 Prompt；如已自定义 Prompt，则不会覆盖。

= 2.1.6 =
* 修复部分站点健康检查显示旧版 Release 的问题。
* 更新器改为读取 GitHub Releases 列表，并按语义版本号选择最高正式版本，不再单纯依赖 /releases/latest。
* 手动检查更新时会重新获取最高版本 Release，避免 GitHub latest 或本地缓存异常导致漏提示。

= 2.1.5 =
* 健康检查新增“插件更新”状态，显示当前版本、GitHub 最新版本和 Release 链接。
* 维护工具新增“手动检查插件更新”按钮，可清理更新缓存并立即向 GitHub Release 检测新版本。
* 手动检查会同步清理 WordPress 插件更新缓存，便于后台更快显示可更新版本。

= 2.1.4 =
* 固定 GitHub 更新源为 921988379/AI-caiji，移除后台 GitHub 更新配置入口，避免站长误填更新源。
* 编辑采集规则时，默认分类和作者改为读取站点现有数据的下拉选择。
* 文章字段提取新增 Tag 标签提取，支持选择器、前后代码截取和 JSON 路径，并在发布时合并到文章标签。
* 自动标签输入框自动载入当前站点已有 Tag，仍支持继续手动增删。
* 自动标签规则改为仅匹配文章标题；正文出现关键词不再触发自动标签。
* 后台输入框样式优化，regular-text 宽度调整为 25%。

= 2.1.3 =
* 新增 JSON/Next.js 采集字段，支持从 __NEXT_DATA__ 提取列表链接、标题、正文和日期。
* JSON 来源支持 __NEXT_DATA__、ld+json、id:脚本ID、var:变量名，保留原有选择器和前后代码兜底。
* 改进相对文章路径和毫秒时间戳日期处理。

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
