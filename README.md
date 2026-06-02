# WP 采集助手

WP 采集助手是一个用于 WordPress 的长期自动化文章采集插件，支持采集规则、列表页发现、分页发现、URL 队列、定时采集、失败重试、正文清洗、图片本地化、SEO 字段、随机延迟发布、Tag 提取、健康检查和诊断报告。

## 主要功能

- 规则管理：新增、编辑、复制、启用/停用、导入/导出。
- 列表页发现：从栏目页自动提取文章链接。
- 分页发现：支持 `https://example.com/page/{page}` 形式。
- 手动 URL：每行一个文章 URL，直接加入队列。
- URL 队列：支持 `pending`、`running`、`success`、`failed`、`skipped` 状态。
- 定时采集：发现链接和采集文章分离，支持固定频率或随机延迟模式。
- 失败重试、任务锁、running 超时释放、防重复标题/来源 URL。
- 正文清洗：删除指定选择器、空段落、外链、关键词段落，支持关键词替换。
- 图片本地化、特色图、图片 ALT 模板、自动摘要。
- 分类、作者、固定标签、自动标签、自动分类规则。
- Rank Math、Yoast SEO、All in One SEO 字段写入。
- AI 改写、目标语言控制、语言检测。
- 健康检查、维护工具、诊断报告导出。
- GitHub Release 自动更新。

## 安装

1. 下载 Release 中的 `wp-caiji.zip`。
2. 在 WordPress 后台进入「插件 → 安装插件 → 上传插件」。
3. 上传并启用插件。
4. 进入「WP 采集 → 设置」确认全局设置。
5. 进入「WP 采集 → 采集规则」新增采集规则。

## 推荐服务器 Cron

WP-Cron 依赖访问触发。长期运行建议服务器添加：

```cron
*/10 * * * * curl -s https://你的域名/wp-cron.php?doing_wp_cron >/dev/null 2>&1
```

当前站点示例：

```cron
*/10 * * * * curl -s https://cm.seoyh.net/wp-cron.php?doing_wp_cron >/dev/null 2>&1
```

## 最近版本更新说明

### 2.1.22

- 采集 URL 安全校验支持公网 HTTP/HTTPS 自定义端口，并在 URL 规范化时保留端口号。
- Referer 自动生成会保留目标 URL 的自定义端口。
- Cookie 池随机选择时增加控制字符过滤，防止导入规则绕过保存清洗。
- 默认 AI Prompt 修正来源 URL 说明，避免要求模型编造联网数据来源。
- 规则导入/导出改用统一白名单和清洗逻辑。
- 日志保留清理改为抽样执行，降低高频采集时的数据库压力。

### 2.1.21

- Tag 提取规则为空时不再解析整篇 HTML，避免误生成大量异常标签。
- 测试预览新增页面提取 Tag、固定 Tag、自动匹配 Tag 和最终写入 Tag 分项展示。
- 固定标签改为多行输入，数据库字段升级为 `TEXT`，支持更多标签和换行分隔。
- JSON Tag 对象优先读取 `name/title/label/tag/term/text` 字段，避免 `id/slug/url` 被误当作标签。
- 自动标签内容包含 `=>` 或 `|` 时，即使未开启增强匹配也会按映射规则解析。
- Tag 写入失败时会记录采集日志，便于排查。

### 2.1.20

- 修复自动采集/Cron 发布文章时 Tag 标签可能未写入的问题，改为文章创建成功后显式写入 `post_tag`。
- 固定标签支持英文逗号、中文逗号、顿号、分号、竖线、斜杠和换行等多种分隔符。
- 修复规则导入导出时遗漏 `auto_tag_advanced` 增强匹配开关的问题。

### 2.1.19

- 修复 AI Endpoint 使用 HTTP 自定义端口时被 WordPress 默认安全端口校验拦截的问题。
- AI Endpoint 现在支持公网 HTTP/HTTPS 自定义端口的 OpenAI 兼容 Endpoint。
- 保留公网 IP 安全校验，仍会拒绝 localhost、内网 IP 和保留地址。

### 2.1.18

- AI API Endpoint 兼容公网 HTTP 地址，便于使用内网映射或不支持 HTTPS 的 OpenAI 兼容中转服务。
- AI Endpoint 后台说明更新为支持 HTTP/HTTPS，生产环境仍建议优先使用 HTTPS。
- 保留 Endpoint 自动补全逻辑，可继续填写基础地址、`/v1` 或完整 `/chat/completions` 地址。

### 2.1.17

- Referer 留空时自动随机生成来源页。
- 自动 Referer 候选包含目标站首页、目标站栏目页，以及 Google、Bing、百度、360、搜狗等常见搜索来源。
- Cookie 输入框支持每行填写一组 Cookie，采集时随机选择一组发送。
- Cookie 留空时默认不发送 Cookie，避免生成无效随机 Cookie 触发目标站风控。
- 后台请求头配置提示文案已同步更新。

### 2.1.16

- User-Agent 池留空时自动随机生成常见浏览器 User-Agent，不再使用固定插件标识。
- 内置 User-Agent 覆盖 Windows Chrome、Windows Edge、macOS Chrome、macOS Safari、Linux Chrome、Android Chrome、iPhone Safari 等常见访问标识。
- 保留手动 User-Agent 池优先级；规则中已填写 User-Agent 池时，仍从手动列表随机选择。
- 后台 User-Agent 输入框提示文案已改为「留空则自动随机生成常见浏览器 User-Agent」。

### 2.1.15

- 修复发布文章时旧队列数据缺少 `auto_tag_advanced` 字段导致 PHP 未定义索引提示的问题。
- 自动标签增强匹配开关在缺省数据下会安全回退为关闭状态。
- 提升旧版本升级后的采集发布兼容性，避免警告信息干扰发布流程。

### 2.1.14

- 修复采集规则「分类与标签 → 自动标签」设置在部分站点无法保存的问题。
- 补齐 `auto_tag_advanced` 数据库列升级逻辑。
- 新增保存规则时的数据库字段白名单过滤，避免未来字段升级未同步时导致整条规则保存失败。
- 在插件后台页面统一增加版权署名：「一点优化」（链接到 https://www.seoyh.net/）。

### 2.1.13

- 自动标签新增「增强匹配」开关，可按规则决定使用新版增强匹配或旧版纯包含匹配。
- 增强匹配支持 `关键词=>标签名` 与 `关键词1|关键词2=>标签名` 写法，便于别名归一到同一个 Tag。
- 改进英文短关键词匹配，尽量避免 `AI`、`SEO`、`WP` 等短词误命中其他单词片段。
- 自动标签最终写入前统一做更稳妥的规范化去重，减少大小写或重复写法导致的脏标签。

## 请求头配置说明

### User-Agent

- 如果 User-Agent 池留空，插件会自动随机生成常见浏览器 User-Agent。
- 如果填写 User-Agent 池，每行一个，采集时会优先从手动列表随机选择。

### Referer

- 如果 Referer 留空，插件会自动随机生成来源页。
- 如果填写 Referer，会优先使用手动填写的来源地址。

### Cookie

- Cookie 支持每行一组 Cookie，采集时随机选择一组。
- Cookie 留空时不会发送 Cookie。
- 不建议生成无意义的随机 Cookie，容易触发目标站风控或返回异常页面。

## 合规提醒

请确认采集行为符合目标网站 robots.txt、版权声明和服务条款。建议优先采集为草稿，人工检查后再发布。

## 更新方式

插件通过 GitHub Releases 检测更新。发布新版本时需要：

1. 更新插件版本号和说明。
2. 推送代码和版本 tag。
3. 创建 GitHub Release。
4. 上传 `wp-caiji.zip` 更新包。

WordPress 端可能存在缓存延迟。可在插件后台使用「手动检查插件更新」，或在 WordPress「仪表盘 → 更新」中再次检查。
