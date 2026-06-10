<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * AI rewrite client for WP Caiji.
 *
 * Uses OpenAI-compatible chat completions endpoints. API key is now stored as
 * plain text by request, while previously encrypted values remain readable for
 * compatibility.
 */
class WP_Caiji_AI
{
    const ENC_PREFIX = 'caiji_enc:v1:';

    private static function t($text)
    {
        return __($text, 'wp-caiji');
    }

    private static function esc_t($text)
    {
        return esc_html__($text, 'wp-caiji');
    }

    public static function default_prompt()
    {
        return "请将下面采集到的文章改写为自然、流畅、适合中文网站发布的原创表达。\n要求：\n1. 保留原意和事实，不要编造不存在的信息。\n2. 优化标题和正文表达，避免机械翻译腔。\n3. 正文允许保留必要 HTML 标签，例如 p、h2、h3、ul、ol、li、strong、em、a、img。\n4. 如原文已包含数据来源，请保留并规范来源 URL；不要编造不存在的数据或来源。\n5. 正文尾部增加FAQ。\n6. 不要输出解释，不要输出 Markdown。\n7. 必须只返回 JSON：{\"title\":\"改写后的标题\",\"content\":\"改写后的 HTML 正文\"}";
    }

    public static function legacy_default_prompts()
    {
        return array(
            "请将下面采集到的文章改写为自然、流畅、适合中文网站发布的原创表达。\n要求：\n1. 保留原意和事实，不要编造不存在的信息。\n2. 优化标题和正文表达，避免机械翻译腔。\n3. 正文允许保留必要 HTML 标签，例如 p、h2、h3、ul、ol、li、strong、em、a、img。\n4. 不要输出解释，不要输出 Markdown。\n5. 必须只返回 JSON：{\"title\":\"改写后的标题\",\"content\":\"改写后的 HTML 正文\"}",
        );
    }

    public static function mask_secret($secret)
    {
        $secret = (string)$secret;
        if ($secret === '') return self::t('未设置');
        if (strlen($secret) <= 8) return str_repeat('*', strlen($secret));
        return substr($secret, 0, 4) . str_repeat('*', max(4, strlen($secret) - 8)) . substr($secret, -4);
    }

    public static function preserve_or_update_secret($incoming, $existing_value = '')
    {
        $incoming = trim((string)$incoming);
        if ($incoming === '') return (string)$existing_value;
        if (preg_match('/^\*+$/', $incoming) || preg_match('/^[^*]{1,8}\*{4,}[^*]{1,8}$/', $incoming)) {
            return (string)$existing_value;
        }
        return $incoming;
    }

    public static function prepare_api_key_for_storage($api_key, $existing_value = '')
    {
        return trim((string)$api_key);
    }

    public static function get_plain_api_key_from_value($value)
    {
        return self::decrypt((string)$value);
    }

    public static function maybe_encrypt_api_key($api_key, $existing_value = '')
    {
        return self::prepare_api_key_for_storage($api_key, $existing_value);
    }

    public static function language_options()
    {
        return array(
            'zh-CN' => self::t('中文'),
            'en' => self::t('英文'),
            'ja' => self::t('日文'),
            'ko' => self::t('韩文'),
            'es' => self::t('西班牙文'),
            'fr' => self::t('法文'),
            'de' => self::t('德文'),
            'auto' => self::t('不限制/不检测'),
        );
    }


    public static function provider_options()
    {
        return array(
            'openai_compatible' => array(
                'label' => self::t('OpenAI 兼容 / 中转站'),
                'endpoint' => 'https://api.seoyh.net/v1/chat/completions',
                'models' => array('gpt-5.5', 'gpt-4o', 'gpt-4o-mini', 'gpt-4.1', 'gpt-4.1-mini', 'o4-mini'),
                'region' => 'custom',
                'billing' => 'depends',
                'description' => '适合绝大多数中转站、One API、New API、LiteLLM、OpenRouter 等；地区和费用取决于你的中转站。',
            ),
            'openai' => array(
                'label' => self::t('OpenAI 官方'),
                'endpoint' => 'https://api.openai.com/v1/chat/completions',
                'models' => array('gpt-4o', 'gpt-4o-mini', 'gpt-4.1', 'gpt-4.1-mini', 'o4-mini'),
                'region' => 'global',
                'billing' => 'paid',
                'description' => 'OpenAI 官方 Chat Completions，通常为付费 API。',
            ),
            'deepseek' => array(
                'label' => self::t('DeepSeek'),
                'endpoint' => 'https://api.deepseek.com/v1/chat/completions',
                'models' => array('deepseek-chat', 'deepseek-reasoner'),
                'region' => 'cn',
                'billing' => 'paid',
                'description' => 'DeepSeek 中国大陆官方接口，兼容 OpenAI 格式，通常为付费 API。',
            ),
            'qwen' => array(
                'label' => self::t('通义千问 / DashScope'),
                'endpoint' => 'https://dashscope.aliyuncs.com/compatible-mode/v1/chat/completions',
                'models' => array('qwen-plus', 'qwen-turbo', 'qwen-max', 'qwen-long'),
                'region' => 'cn',
                'billing' => 'free_tier',
                'description' => '阿里云 DashScope 中国大陆 OpenAI 兼容模式；常见为免费额度 + 付费计费。',
            ),
            'moonshot' => array(
                'label' => self::t('Moonshot / Kimi'),
                'endpoint' => 'https://api.moonshot.cn/v1/chat/completions',
                'models' => array('moonshot-v1-8k', 'moonshot-v1-32k', 'moonshot-v1-128k'),
                'region' => 'cn',
                'billing' => 'paid',
                'description' => 'Moonshot / Kimi 中国大陆官方接口，兼容 OpenAI 格式，通常为付费 API。',
            ),
            'zhipu' => array(
                'label' => self::t('智谱 GLM'),
                'endpoint' => 'https://open.bigmodel.cn/api/paas/v4/chat/completions',
                'models' => array('glm-4-plus', 'glm-4-air', 'glm-4-flash'),
                'region' => 'cn',
                'billing' => 'free_tier',
                'description' => '智谱 AI 中国大陆 v4 Chat Completions；部分模型/额度可能免费，生产通常按量付费。',
            ),
            'baichuan' => array(
                'label' => self::t('百川智能'),
                'endpoint' => 'https://api.baichuan-ai.com/v1/chat/completions',
                'models' => array('Baichuan4', 'Baichuan3-Turbo', 'Baichuan3-Turbo-128k'),
                'region' => 'cn',
                'billing' => 'paid',
                'description' => '百川智能中国大陆 OpenAI 兼容接口，通常为付费 API。',
            ),
            'minimax' => array(
                'label' => self::t('MiniMax'),
                'endpoint' => 'https://api.minimax.chat/v1/text/chatcompletion_v2',
                'models' => array('abab6.5s-chat', 'abab6.5g-chat', 'abab6.5t-chat'),
                'region' => 'cn',
                'billing' => 'paid',
                'description' => 'MiniMax 中国大陆 Chat Completion v2，通常为付费 API；多数中转站也可用 OpenAI 兼容模式。',
            ),
            'xai' => array(
                'label' => self::t('xAI / Grok'),
                'endpoint' => 'https://api.x.ai/v1/chat/completions',
                'models' => array('grok-3', 'grok-3-mini', 'grok-2-vision-1212'),
                'region' => 'global',
                'billing' => 'paid',
                'description' => 'xAI / Grok 海外官方接口，兼容 OpenAI 格式，通常为付费 API。',
            ),
            'openrouter' => array(
                'label' => self::t('OpenRouter'),
                'endpoint' => 'https://openrouter.ai/api/v1/chat/completions',
                'models' => array('openai/gpt-4o-mini', 'anthropic/claude-3.5-sonnet', 'google/gemini-2.0-flash-001', 'deepseek/deepseek-chat'),
                'region' => 'global',
                'billing' => 'free_tier',
                'description' => '海外聚合平台 / 中转站，兼容 OpenAI 格式；有免费模型，也有付费模型。',
            ),
            'anthropic' => array(
                'label' => self::t('Anthropic Claude 官方'),
                'endpoint' => 'https://api.anthropic.com/v1/messages',
                'models' => array('claude-3-5-sonnet-latest', 'claude-3-5-haiku-latest', 'claude-3-opus-latest'),
                'region' => 'global',
                'billing' => 'paid',
                'description' => 'Claude 海外官方 Messages API，通常为付费 API；如果走中转站请选择 OpenAI 兼容。',
            ),
            'gemini' => array(
                'label' => self::t('Google Gemini 官方'),
                'endpoint' => 'https://generativelanguage.googleapis.com/v1beta/models/{model}:generateContent',
                'models' => array('gemini-2.0-flash', 'gemini-1.5-pro', 'gemini-1.5-flash'),
                'region' => 'global',
                'billing' => 'free_tier',
                'description' => 'Google Gemini 海外官方 generateContent API；有免费额度/免费层，Key 会自动放入 URL 参数。',
            ),
        );
    }

    public static function sanitize_provider($provider)
    {
        $provider = sanitize_key((string)$provider);
        return array_key_exists($provider, self::provider_options()) ? $provider : 'openai_compatible';
    }

    public static function provider_default_endpoint($provider)
    {
        $provider = self::sanitize_provider($provider);
        $providers = self::provider_options();
        return (string)($providers[$provider]['endpoint'] ?? $providers['openai_compatible']['endpoint']);
    }

    public static function provider_model_presets($provider = '')
    {
        $providers = self::provider_options();
        if ($provider !== '') {
            $provider = self::sanitize_provider($provider);
            return (array)($providers[$provider]['models'] ?? array());
        }
        $models = array();
        foreach ($providers as $meta) {
            foreach ((array)($meta['models'] ?? array()) as $model) {
                $models[$model] = true;
            }
        }
        return array_keys($models);
    }


    public static function provider_label($provider)
    {
        $provider = self::sanitize_provider($provider);
        $providers = self::provider_options();
        return (string)($providers[$provider]['label'] ?? $provider);
    }


    public static function provider_region_label($region)
    {
        $labels = array(
            'cn' => self::t('中国大陆'),
            'global' => self::t('其他国家/海外'),
            'custom' => self::t('中转站/自定义'),
        );
        return $labels[$region] ?? self::t('其他国家/海外');
    }

    public static function provider_billing_label($billing)
    {
        $labels = array(
            'paid' => self::t('付费'),
            'free_tier' => self::t('免费额度/免费模型'),
            'depends' => self::t('费用取决于中转站'),
        );
        return $labels[$billing] ?? self::t('付费');
    }

    public static function provider_grouped_options()
    {
        $groups = array(
            'custom_depends' => array('label' => self::t('中转站 / 自定义（费用和地区取决于服务商）'), 'providers' => array()),
            'cn_free_tier' => array('label' => self::t('中国大陆 · 免费额度/免费模型'), 'providers' => array()),
            'cn_paid' => array('label' => self::t('中国大陆 · 付费'), 'providers' => array()),
            'global_free_tier' => array('label' => self::t('其他国家/海外 · 免费额度/免费模型'), 'providers' => array()),
            'global_paid' => array('label' => self::t('其他国家/海外 · 付费'), 'providers' => array()),
        );
        foreach (self::provider_options() as $key => $meta) {
            $region = (string)($meta['region'] ?? 'global');
            $billing = (string)($meta['billing'] ?? 'paid');
            $group_key = $region === 'custom' || $billing === 'depends' ? 'custom_depends' : $region . '_' . $billing;
            if (!isset($groups[$group_key])) $group_key = 'global_paid';
            $groups[$group_key]['providers'][$key] = $meta;
        }
        return array_filter($groups, function ($group) {
            return !empty($group['providers']);
        });
    }


    public static function provider_tutorials()
    {
        return array(
            'openai_compatible' => array(
                'title' => 'OpenAI 兼容 / 中转站 API Key 获取说明',
                'official_url' => '',
                'steps' => array(
                    '登录你的中转站后台，例如 One API、New API、LiteLLM、OpenRouter 或服务商提供的兼容平台。',
                    '在后台找到“令牌 / Tokens / API Keys / 渠道密钥”等菜单，新建一个可用令牌。',
                    '复制生成的 Key，通常以 sk- 开头，也可能是中转站自定义格式。',
                    '复制中转站提供的接口地址。常见格式为 https://你的域名/v1 或 https://你的域名/v1/chat/completions。',
                    '回到本插件：服务商选择“OpenAI 兼容 / 中转站”，API Key 填令牌，Endpoint 填中转站地址，模型名填中转站支持的模型。',
                    '点击“测试 API 连接”，成功后再保存设置。',
                ),
                'notes' => array('如果中转站要求自定义模型名，请以中转站后台显示为准。', '不要把 Key 写在 Endpoint URL 里，应填写在 API Key 输入框。'),
            ),
            'openai' => array(
                'title' => 'OpenAI 官方 API Key 获取说明',
                'official_url' => 'https://platform.openai.com/api-keys',
                'steps' => array(
                    '打开 OpenAI Platform 并登录账号。',
                    '进入 API keys 页面，点击 Create new secret key。',
                    '复制生成的 sk- 开头密钥；密钥只显示一次，请妥善保存。',
                    '确认 Billing / 账单已开通，官方 API 通常需要可用余额或付款方式。',
                    '回到本插件选择“OpenAI 官方”，Endpoint 使用默认地址，模型选择 gpt-4o、gpt-4o-mini 等。',
                    '点击“测试 API 连接”。',
                ),
                'notes' => array('OpenAI ChatGPT 网页会员不等于 API 余额。', '中国大陆网络环境可能需要你的服务器能正常访问 OpenAI 官方域名。'),
            ),
            'deepseek' => array(
                'title' => 'DeepSeek API Key 获取说明',
                'official_url' => 'https://platform.deepseek.com/api_keys',
                'steps' => array(
                    '打开 DeepSeek 开放平台并注册/登录。',
                    '完成必要的账号验证后，进入 API Keys 页面。',
                    '点击创建 API Key，复制生成的密钥。',
                    '确认账号余额或充值状态可用。',
                    '回到本插件选择“DeepSeek”，Endpoint 使用默认地址，模型可选 deepseek-chat 或 deepseek-reasoner。',
                    '点击“测试 API 连接”。',
                ),
                'notes' => array('deepseek-reasoner 成本和响应时间通常高于 deepseek-chat。', '如果走中转站，请选择“OpenAI 兼容 / 中转站”。'),
            ),
            'qwen' => array(
                'title' => '通义千问 / DashScope API Key 获取说明',
                'official_url' => 'https://dashscope.console.aliyun.com/apiKey',
                'steps' => array(
                    '登录阿里云账号并进入 DashScope 控制台。',
                    '按页面提示开通 DashScope / 模型服务。',
                    '进入 API Key 管理页面，创建或复制 API Key。',
                    '确认免费额度、资源包或按量付费已可用。',
                    '回到本插件选择“通义千问 / DashScope”，Endpoint 使用兼容模式默认地址。',
                    '模型可填 qwen-plus、qwen-turbo、qwen-max、qwen-long 等，然后测试连接。',
                ),
                'notes' => array('建议使用 DashScope 的 OpenAI 兼容模式 Endpoint。', '阿里云权限/实名/计费策略可能随账号类型不同。'),
            ),
            'moonshot' => array(
                'title' => 'Moonshot / Kimi API Key 获取说明',
                'official_url' => 'https://platform.moonshot.cn/console/api-keys',
                'steps' => array(
                    '打开 Moonshot AI 开放平台并注册/登录。',
                    '进入控制台的 API Key 管理页面。',
                    '点击新建 API Key 并复制密钥。',
                    '确认账号余额或赠送额度可用。',
                    '回到本插件选择“Moonshot / Kimi”，Endpoint 使用默认地址。',
                    '模型可填 moonshot-v1-8k、moonshot-v1-32k、moonshot-v1-128k，然后测试连接。',
                ),
                'notes' => array('长上下文模型费用通常更高。', '如接口策略变化，请以 Moonshot 控制台显示为准。'),
            ),
            'zhipu' => array(
                'title' => '智谱 GLM API Key 获取说明',
                'official_url' => 'https://open.bigmodel.cn/usercenter/apikeys',
                'steps' => array(
                    '打开智谱开放平台并注册/登录。',
                    '进入用户中心 / API Keys 页面。',
                    '创建新的 API Key 并复制。',
                    '确认免费额度、套餐或余额可用。',
                    '回到本插件选择“智谱 GLM”，Endpoint 使用默认 v4 地址。',
                    '模型可填 glm-4-plus、glm-4-air、glm-4-flash，然后测试连接。',
                ),
                'notes' => array('免费模型/额度会随官方活动变化。', '如果官方 Key 格式或鉴权方式变化，请优先参考控制台最新文档。'),
            ),
            'baichuan' => array(
                'title' => '百川智能 API Key 获取说明',
                'official_url' => 'https://platform.baichuan-ai.com/console/apikey',
                'steps' => array(
                    '打开百川智能开放平台并注册/登录。',
                    '进入控制台的 API Key 或密钥管理页面。',
                    '创建 API Key 并复制。',
                    '确认账号已开通对应模型权限和计费。',
                    '回到本插件选择“百川智能”，Endpoint 使用默认地址。',
                    '模型可填 Baichuan4、Baichuan3-Turbo、Baichuan3-Turbo-128k，然后测试连接。',
                ),
                'notes' => array('模型名称大小写建议按官方控制台填写。'),
            ),
            'minimax' => array(
                'title' => 'MiniMax API Key 获取说明',
                'official_url' => 'https://platform.minimaxi.com/user-center/basic-information/interface-key',
                'steps' => array(
                    '打开 MiniMax 开放平台并注册/登录。',
                    '进入账户中心 / 接口密钥页面。',
                    '创建或复制 API Key。',
                    '确认已开通模型调用权限和余额。',
                    '回到本插件选择“MiniMax”，Endpoint 使用默认 chatcompletion_v2 地址。',
                    '模型可填 abab6.5s-chat、abab6.5g-chat、abab6.5t-chat，然后测试连接。',
                ),
                'notes' => array('如果你的 MiniMax 账号还要求 GroupId 等额外参数，建议通过中转站转换为 OpenAI 兼容格式后接入。'),
            ),
            'xai' => array(
                'title' => 'xAI / Grok API Key 获取说明',
                'official_url' => 'https://console.x.ai/',
                'steps' => array(
                    '打开 xAI Console 并登录。',
                    '进入 API Keys 页面创建新的 API Key。',
                    '复制生成的密钥。',
                    '确认 Billing / Credits 可用。',
                    '回到本插件选择“xAI / Grok”，Endpoint 使用默认地址。',
                    '模型可填 grok-3、grok-3-mini 等，然后测试连接。',
                ),
                'notes' => array('xAI 官方 API 通常需要海外网络可访问。', '模型名称可能更新较快，请以 xAI Console 为准。'),
            ),
            'openrouter' => array(
                'title' => 'OpenRouter API Key 获取说明',
                'official_url' => 'https://openrouter.ai/settings/keys',
                'steps' => array(
                    '打开 OpenRouter 并注册/登录。',
                    '进入 Keys 页面，点击 Create Key。',
                    '复制生成的 API Key。',
                    '如需付费模型，请在 Credits / Billing 中充值；免费模型可留意模型页标记。',
                    '回到本插件选择“OpenRouter”，Endpoint 使用默认地址。',
                    '模型名需要填写 OpenRouter 模型 ID，例如 openai/gpt-4o-mini、deepseek/deepseek-chat 等，然后测试连接。',
                ),
                'notes' => array('OpenRouter 上的“免费模型”和“付费模型”会变化，请以模型页面为准。', '也可以把 OpenRouter 当作 OpenAI 兼容中转站使用。'),
            ),
            'anthropic' => array(
                'title' => 'Anthropic Claude 官方 API Key 获取说明',
                'official_url' => 'https://console.anthropic.com/settings/keys',
                'steps' => array(
                    '打开 Anthropic Console 并登录。',
                    '进入 Settings / API Keys 页面。',
                    '点击 Create Key，复制生成的 API Key。',
                    '确认 Billing / Credits 可用。',
                    '回到本插件选择“Anthropic Claude 官方”，Endpoint 使用默认 /v1/messages 地址。',
                    '模型可填 claude-3-5-sonnet-latest、claude-3-5-haiku-latest 等，然后测试连接。',
                ),
                'notes' => array('Claude 官方接口不是 OpenAI 格式，本插件已单独适配。', '如果使用 Claude 中转站，请选择“OpenAI 兼容 / 中转站”。'),
            ),
            'gemini' => array(
                'title' => 'Google Gemini API Key 获取说明',
                'official_url' => 'https://aistudio.google.com/app/apikey',
                'steps' => array(
                    '打开 Google AI Studio 并登录 Google 账号。',
                    '进入 Get API key / API keys 页面。',
                    '创建 API Key，选择或创建对应 Google Cloud 项目。',
                    '复制生成的 API Key；免费额度和地区限制以 Google 当前政策为准。',
                    '回到本插件选择“Google Gemini 官方”，Endpoint 使用默认 {model}:generateContent 地址。',
                    '模型可填 gemini-2.0-flash、gemini-1.5-pro、gemini-1.5-flash，然后测试连接。',
                ),
                'notes' => array('Gemini 官方接口 Key 会由插件自动放到 URL 参数中，不需要手动拼接 ?key=。', '中国大陆服务器可能无法直接访问 Google 官方接口，必要时使用中转站。'),
            ),
        );
    }

    public static function sanitize_language($language, $allow_empty = false)
    {
        $raw = trim((string)$language);
        if ($allow_empty && $raw === '') return '';

        $aliases = array(
            'zh' => 'zh-CN',
            'zh-cn' => 'zh-CN',
            'zh_cn' => 'zh-CN',
            'cn' => 'zh-CN',
            'chinese' => 'zh-CN',
            '中文' => 'zh-CN',
            '简体中文' => 'zh-CN',
            'en-us' => 'en',
            'en_gb' => 'en',
            'english' => 'en',
            '英文' => 'en',
            'es-es' => 'es',
            'es_mx' => 'es',
            'spanish' => 'es',
            'español' => 'es',
            'espanol' => 'es',
            '西班牙语' => 'es',
            '西班牙文' => 'es',
            'fr-fr' => 'fr',
            'french' => 'fr',
            '法语' => 'fr',
            '法文' => 'fr',
            'de-de' => 'de',
            'german' => 'de',
            '德语' => 'de',
            '德文' => 'de',
            'ja-jp' => 'ja',
            'japanese' => 'ja',
            '日语' => 'ja',
            '日文' => 'ja',
            'ko-kr' => 'ko',
            'korean' => 'ko',
            '韩语' => 'ko',
            '韩文' => 'ko',
            'none' => 'auto',
            'no' => 'auto',
            'auto' => 'auto',
            '不限制' => 'auto',
            '不检测' => 'auto',
            '不限制/不检测' => 'auto',
        );

        $key = function_exists('mb_strtolower') ? mb_strtolower($raw, 'UTF-8') : strtolower($raw);
        $key = str_replace(array('_', ' '), array('-', '-'), $key);
        if (isset($aliases[$key])) return $aliases[$key];

        $sanitized = sanitize_key($raw);
        if (isset($aliases[$sanitized])) return $aliases[$sanitized];
        return array_key_exists($sanitized, self::language_options()) ? $sanitized : 'zh-CN';
    }

    public static function language_label($language)
    {
        $language = self::sanitize_language($language);
        $options = self::language_options();
        return $options[$language] ?? $options['zh-CN'];
    }

    private static function language_prompt_instruction($language)
    {
        $language = self::sanitize_language($language);
        if ($language === 'auto') return '';

        $label = self::language_label($language);
        $extra = '';
        if ($language === 'es') {
            $extra = "
- 西班牙语请使用自然、流畅的通用西班牙语（español neutro），标题、正文、FAQ、按钮/小标题等可见文本都必须翻译为西班牙语。
- 保留 HTML 标签、链接 URL、图片地址、专有名词、品牌名和不可翻译代码，不要夹杂中文或英文解释。";
        }

        return "

语言要求：请将改写后的标题和正文全部输出为{$label}。如果原文不是{$label}，请先完整翻译再改写。该语言要求优先于默认 Prompt 中任何关于“中文网站/中文表达”的描述。{$extra}";
    }

    public static function detect_language_matches($title, $content, $language)
    {
        $language = self::sanitize_language($language);
        if ($language === 'auto') return true;

        $text = wp_strip_all_tags((string)$title . "\n" . (string)$content);
        $text = html_entity_decode($text, ENT_QUOTES, 'UTF-8');
        $text = preg_replace('/\s+/u', ' ', $text);
        $text = trim($text);
        if ($text === '') return false;

        preg_match_all('/[\p{Han}]/u', $text, $han);
        preg_match_all('/[\p{Hiragana}\p{Katakana}]/u', $text, $kana);
        preg_match_all('/[\x{AC00}-\x{D7AF}]/u', $text, $hangul);
        preg_match_all('/[A-Za-zÀ-ÿ]+/u', $text, $latin);

        $han_count = count($han[0]);
        $kana_count = count($kana[0]);
        $hangul_count = count($hangul[0]);
        $latin_count = count($latin[0]);
        $letter_count = max(1, $han_count + $kana_count + $hangul_count + $latin_count);
        $latin_ratio = $latin_count / $letter_count;

        if ($language === 'zh-CN') return $han_count >= 12 && ($han_count / $letter_count) >= 0.25;
        if ($language === 'ja') return $kana_count >= 8 || ($kana_count >= 3 && $han_count >= 8);
        if ($language === 'ko') return $hangul_count >= 12 && ($hangul_count / $letter_count) >= 0.30;

        $lower = mb_strtolower(' ' . $text . ' ', 'UTF-8');
        $scores = array(
            'en' => preg_match_all('/\b(the|and|or|of|to|in|for|with|that|is|are|this|from|by|as|will|can|has|have)\b/u', $lower),
            'es' => preg_match_all('/\b(el|la|los|las|un|una|unos|unas|de|del|que|para|con|por|como|este|esta|estos|estas|son|más|también|entre|sobre|desde|hasta|pero|porque|según|cada|años|año|ser|tiene|puede)\b/u', $lower),
            'fr' => preg_match_all('/\b(le|la|les|des|de|du|que|pour|avec|une|dans|est|sont|plus|sur|mais|par|aux|ce|cette)\b/u', $lower),
            'de' => preg_match_all('/\b(der|die|das|und|oder|von|mit|für|ist|sind|ein|eine|nicht|auf|zu|im|den|dem|des|als)\b/u', $lower),
        );
        if (isset($scores[$language])) {
            $max_other = 0;
            foreach ($scores as $key => $score) {
                if ($key !== $language) $max_other = max($max_other, (int)$score);
            }
            $score = (int)$scores[$language];
            $has_spanish_marks = preg_match('/[ñáéíóúü¿¡]/u', $lower) === 1;
            if ($language === 'es') {
                return $latin_ratio >= 0.55 && ($score >= 2 || ($has_spanish_marks && $score >= 1) || ($score >= 1 && $score >= $max_other + 1));
            }
            return $latin_ratio >= 0.55 && ($score >= 2 || ($score >= 1 && $score >= $max_other));
        }

        return true;
    }

    public static function get_api_key($settings = array())
    {
        $settings = wp_parse_args((array)$settings, WP_Caiji_DB::default_settings());
        return self::decrypt((string)($settings['ai_api_key'] ?? ''));
    }

    private static function crypto_key()
    {
        return hash('sha256', wp_salt('auth') . '|wp-caiji-ai', true);
    }

    private static function encrypt($plain)
    {
        if (!function_exists('openssl_encrypt')) return (string)$plain;
        $iv = random_bytes(16);
        $cipher = openssl_encrypt((string)$plain, 'AES-256-CBC', self::crypto_key(), OPENSSL_RAW_DATA, $iv);
        if ($cipher === false) return (string)$plain;
        return self::ENC_PREFIX . base64_encode($iv . $cipher);
    }

    private static function decrypt($value)
    {
        $value = (string)$value;
        if ($value === '') return '';
        if (strpos($value, self::ENC_PREFIX) !== 0) return $value;
        if (!function_exists('openssl_decrypt')) return '';
        $raw = base64_decode(substr($value, strlen(self::ENC_PREFIX)), true);
        if ($raw === false || strlen($raw) <= 16) return '';
        $iv = substr($raw, 0, 16);
        $cipher = substr($raw, 16);
        $plain = openssl_decrypt($cipher, 'AES-256-CBC', self::crypto_key(), OPENSSL_RAW_DATA, $iv);
        return $plain === false ? '' : $plain;
    }

    public static function normalize_endpoint($endpoint, $provider = 'openai_compatible', $model = '')
    {
        $provider = self::sanitize_provider($provider);
        $endpoint = trim((string)$endpoint);
        if ($endpoint === '') $endpoint = self::provider_default_endpoint($provider);
        $endpoint = rtrim($endpoint);
        if ($provider === 'gemini' && strpos($endpoint, '{model}') !== false) {
            $endpoint = str_replace('{model}', rawurlencode(trim((string)$model) ?: 'gemini-2.0-flash'), $endpoint);
        }
        $parts = wp_parse_url($endpoint);
        if (empty($parts['scheme']) || empty($parts['host'])) return '';

        $path = isset($parts['path']) ? rtrim($parts['path'], '/') : '';
        if ($provider === 'gemini') {
            if ($path === '') {
                $path = '/v1beta/models/' . rawurlencode(trim((string)$model) ?: 'gemini-2.0-flash') . ':generateContent';
            } elseif (!preg_match('#:generateContent$#i', $path) && !preg_match('#/generateContent$#i', $path)) {
                $path .= '/models/' . rawurlencode(trim((string)$model) ?: 'gemini-2.0-flash') . ':generateContent';
            }
        } elseif ($provider === 'anthropic') {
            if ($path === '') {
                $path = '/v1/messages';
            } elseif (preg_match('#/v1$#i', $path)) {
                $path .= '/messages';
            } elseif (!preg_match('#/messages$#i', $path)) {
                $path .= '/v1/messages';
            }
        } else {
            if ($path === '') {
                $path = '/v1/chat/completions';
            } elseif (preg_match('#/(chat/)?completions$#i', $path) || preg_match('#/text/chatcompletion_v2$#i', $path)) {
                // Already a full OpenAI-compatible chat completions endpoint.
            } elseif (preg_match('#/v1$#i', $path) || preg_match('#/compatible-mode/v1$#i', $path)) {
                $path .= '/chat/completions';
            } else {
                $path .= '/v1/chat/completions';
            }
        }

        $url = strtolower($parts['scheme']) . '://' . $parts['host'];
        if (!empty($parts['port'])) $url .= ':' . (int)$parts['port'];
        $url .= $path;
        if (!empty($parts['query'])) $url .= '?' . $parts['query'];
        return $url;
    }

    public static function validate_endpoint($endpoint, $provider = 'openai_compatible', $model = '')
    {
        $endpoint = self::normalize_endpoint($endpoint, $provider, $model);
        if ($endpoint === '' || !self::is_safe_public_endpoint($endpoint)) {
            return new WP_Error('wp_caiji_ai_endpoint_unsafe', 'AI API Endpoint 无效或不安全，必须是公网 HTTP/HTTPS 地址');
        }
        return $endpoint;
    }

    private static function build_request($provider, $endpoint, $api_key, $model, $temperature, $messages, $timeout, $max_tokens = 0)
    {
        $provider = self::sanitize_provider($provider);
        $headers = array('Content-Type' => 'application/json');
        $payload = array();

        if ($provider === 'anthropic') {
            $system = '';
            $anthropic_messages = array();
            foreach ((array)$messages as $message) {
                $role = (string)($message['role'] ?? 'user');
                $content = (string)($message['content'] ?? '');
                if ($role === 'system') {
                    $system .= ($system === '' ? '' : "\n\n") . $content;
                } else {
                    $anthropic_messages[] = array('role' => $role === 'assistant' ? 'assistant' : 'user', 'content' => $content);
                }
            }
            $headers['x-api-key'] = $api_key;
            $headers['anthropic-version'] = '2023-06-01';
            $payload = array(
                'model' => $model,
                'max_tokens' => $max_tokens > 0 ? $max_tokens : 4096,
                'temperature' => $temperature,
                'messages' => $anthropic_messages,
            );
            if ($system !== '') $payload['system'] = $system;
        } elseif ($provider === 'gemini') {
            $endpoint = self::append_query_arg($endpoint, 'key', $api_key);
            $parts = array();
            foreach ((array)$messages as $message) {
                $role = (string)($message['role'] ?? 'user');
                $content = (string)($message['content'] ?? '');
                $parts[] = array(
                    'role' => $role === 'assistant' ? 'model' : 'user',
                    'parts' => array(array('text' => ($role === 'system' ? "系统要求：\n" : '') . $content)),
                );
            }
            $payload = array(
                'contents' => $parts,
                'generationConfig' => array('temperature' => $temperature),
            );
            if ($max_tokens > 0) $payload['generationConfig']['maxOutputTokens'] = $max_tokens;
        } else {
            $headers['Authorization'] = 'Bearer ' . $api_key;
            $payload = array(
                'model' => $model,
                'temperature' => $temperature,
                'messages' => $messages,
            );
            if ($max_tokens > 0) $payload['max_tokens'] = $max_tokens;
        }

        return array($endpoint, array(
            'timeout' => $timeout,
            'redirection' => 0,
            'reject_unsafe_urls' => false,
            'headers' => $headers,
            'body' => wp_json_encode($payload, JSON_UNESCAPED_UNICODE),
        ));
    }

    private static function append_query_arg($url, $key, $value)
    {
        $parts = wp_parse_url($url);
        if (!$parts) return $url;
        $query = array();
        if (!empty($parts['query'])) wp_parse_str($parts['query'], $query);
        $query[$key] = $value;
        $rebuilt = strtolower($parts['scheme']) . '://' . $parts['host'];
        if (!empty($parts['port'])) $rebuilt .= ':' . (int)$parts['port'];
        $rebuilt .= $parts['path'] ?? '';
        $rebuilt .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        return $rebuilt;
    }

    private static function extract_response_message($provider, $body)
    {
        $data = json_decode((string)$body, true);
        if (!is_array($data)) return '';
        $provider = self::sanitize_provider($provider);
        if ($provider === 'anthropic') {
            $chunks = array();
            foreach ((array)($data['content'] ?? array()) as $part) {
                if (isset($part['text'])) $chunks[] = (string)$part['text'];
            }
            return implode("\n", $chunks);
        }
        if ($provider === 'gemini') {
            $chunks = array();
            foreach ((array)($data['candidates'][0]['content']['parts'] ?? array()) as $part) {
                if (isset($part['text'])) $chunks[] = (string)$part['text'];
            }
            return implode("\n", $chunks);
        }
        return (string)($data['choices'][0]['message']['content'] ?? $data['choices'][0]['text'] ?? '');
    }

    private static function response_is_json($body)
    {
        return is_array(json_decode((string)$body, true));
    }

    public static function is_safe_public_endpoint($url)
    {
        $url = trim((string)$url);
        if ($url === '') return false;

        $parts = wp_parse_url($url);
        if (!$parts || empty($parts['scheme']) || empty($parts['host'])) return false;
        if (!empty($parts['user']) || !empty($parts['pass'])) return false;

        $scheme = strtolower((string)$parts['scheme']);
        if (!in_array($scheme, array('http', 'https'), true)) return false;

        if (isset($parts['port'])) {
            $port = (int)$parts['port'];
            if ($port < 1 || $port > 65535) return false;
        }

        $host = trim((string)$parts['host'], "[] \t\n\r\0\x0B");
        if ($host === '') return false;

        $host_lc = strtolower($host);
        if (in_array($host_lc, array('localhost', 'localhost.localdomain'), true) || substr($host_lc, -6) === '.local') {
            return false;
        }

        $ips = array();
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            $ips[] = $host;
        } else {
            $resolved = gethostbynamel($host);
            if (!$resolved || !is_array($resolved)) return false;
            $ips = $resolved;
        }

        foreach ($ips as $ip) {
            if (!WP_Caiji_Utils::is_public_ip($ip)) return false;
        }

        return true;
    }

    public static function rewrite($title, $content, $rule, $settings)
    {
        $settings = wp_parse_args((array)$settings, WP_Caiji_DB::default_settings());
        $api_key = self::get_api_key($settings);
        if ($api_key === '') return new WP_Error('wp_caiji_ai_no_key', 'AI API Key 未设置');

        $provider = self::sanitize_provider($settings['ai_provider'] ?? 'openai_compatible');
        $model = trim((string)($settings['ai_model'] ?? '')) ?: 'gpt-5.5';
        $endpoint = self::validate_endpoint($settings['ai_endpoint'] ?? '', $provider, $model);
        if (is_wp_error($endpoint)) return $endpoint;
        $prompt = trim((string)($rule['ai_rewrite_prompt'] ?? ''));
        if ($prompt === '') $prompt = trim((string)($settings['ai_rewrite_prompt'] ?? ''));
        if ($prompt === '') $prompt = self::default_prompt();
        $target_language = self::sanitize_language($rule['ai_rewrite_language'] ?? '', true);
        if ($target_language === '') $target_language = self::sanitize_language($settings['ai_rewrite_language'] ?? 'zh-CN');
        if ($target_language !== 'auto') {
            $prompt .= self::language_prompt_instruction($target_language);
        }

        $max_chars = max(1000, min(60000, (int)($settings['ai_max_input_chars'] ?? 12000)));
        $clean_content = mb_substr((string)$content, 0, $max_chars);
        $temperature = max(0, min(2, (float)($settings['ai_temperature'] ?? 0.7)));
        $timeout = max(10, min(300, (int)($settings['ai_timeout_seconds'] ?? 45)));

        $payload = array(
            'model' => $model,
            'temperature' => $temperature,
            'messages' => array(
                array('role' => 'system', 'content' => $prompt),
                array('role' => 'user', 'content' => "标题：\n" . wp_strip_all_tags((string)$title) . "\n\n正文 HTML：\n" . $clean_content),
            ),
        );

        list($request_endpoint, $request_args) = self::build_request($provider, $endpoint, $api_key, $model, $temperature, $payload['messages'], $timeout);
        $response = wp_remote_post($request_endpoint, $request_args);

        if (is_wp_error($response)) return $response;
        $code = (int)wp_remote_retrieve_response_code($response);
        $body = (string)wp_remote_retrieve_body($response);
        if ($code < 200 || $code >= 300) {
            return new WP_Error('wp_caiji_ai_http_error', 'AI API 请求失败：HTTP ' . $code . ' ' . wp_html_excerpt($body, 300));
        }

        $message = self::extract_response_message($provider, $body);
        if (trim($message) === '') return new WP_Error('wp_caiji_ai_empty', 'AI API 返回内容为空');

        $parsed = self::parse_model_output($message);
        $new_title = trim(wp_strip_all_tags((string)($parsed['title'] ?? '')));
        $new_content = trim((string)($parsed['content'] ?? ''));
        if ($new_content === '') return new WP_Error('wp_caiji_ai_parse_failed', 'AI 改写结果解析失败或正文为空；响应片段：' . self::safe_excerpt($message, 260));
        if (mb_strlen(wp_strip_all_tags($new_content)) < 80) {
            return new WP_Error('wp_caiji_ai_too_short', 'AI 改写结果正文过短，已判定为失败；响应片段：' . self::safe_excerpt($message, 260));
        }
        $new_content = wp_kses_post($new_content);
        $new_title = $new_title !== '' ? $new_title : $title;
        if (!empty($settings['ai_language_check']) && !self::detect_language_matches($new_title, $new_content, $target_language)) {
            return new WP_Error('wp_caiji_ai_language_mismatch', 'AI 改写结果未通过目标语言检测，目标语言：' . self::language_label($target_language) . '；已判定为失败');
        }

        return array(
            'title' => $new_title,
            'content' => $new_content,
        );
    }

    public static function test_connection($settings)
    {
        $settings = wp_parse_args((array)$settings, WP_Caiji_DB::default_settings());
        $api_key = self::get_api_key($settings);
        $provider = self::sanitize_provider($settings['ai_provider'] ?? 'openai_compatible');
        $model = trim((string)($settings['ai_model'] ?? '')) ?: 'gpt-5.5';
        $endpoint = self::validate_endpoint($settings['ai_endpoint'] ?? '', $provider, $model);
        $timeout = max(10, min(300, (int)($settings['ai_timeout_seconds'] ?? 45)));
        $result = array(
            'ok' => false,
            'provider' => self::provider_label($provider),
            'endpoint' => is_wp_error($endpoint) ? self::normalize_endpoint($settings['ai_endpoint'] ?? '', $provider, $model) : $endpoint,
            'model' => $model,
            'http_code' => '',
            'latency_ms' => '',
            'message' => '',
        );
        if ($api_key === '') {
            $result['message'] = 'AI API Key 未设置';
            return $result;
        }
        if (is_wp_error($endpoint)) {
            $result['message'] = $endpoint->get_error_message();
            return $result;
        }

        $messages = array(
            array('role' => 'system', 'content' => 'You are an API connectivity test. Reply with OK only.'),
            array('role' => 'user', 'content' => 'OK'),
        );
        list($request_endpoint, $request_args) = self::build_request($provider, $endpoint, $api_key, $model, 0, $messages, $timeout, 16);
        $started = microtime(true);
        $response = wp_remote_post($request_endpoint, $request_args);
        $result['latency_ms'] = (string)round((microtime(true) - $started) * 1000);
        if (is_wp_error($response)) {
            $result['message'] = $response->get_error_message();
            return $result;
        }
        $code = (int)wp_remote_retrieve_response_code($response);
        $body = (string)wp_remote_retrieve_body($response);
        $result['http_code'] = (string)$code;
        if ($code < 200 || $code >= 300) {
            $result['message'] = 'HTTP ' . $code . ' ' . self::safe_excerpt($body, 260);
            return $result;
        }
        if (!self::response_is_json($body)) {
            $result['message'] = 'HTTP 成功，但返回不是 JSON：' . self::safe_excerpt($body, 220);
            return $result;
        }
        $message = self::extract_response_message($provider, $body);
        $result['ok'] = true;
        $result['message'] = trim($message) !== '' ? self::safe_excerpt($message, 160) : 'HTTP 成功，JSON 解析成功';
        return $result;
    }

    private static function parse_model_output($message)
    {
        $message = trim((string)$message);
        $message = preg_replace('/^```(?:json)?\s*/i', '', $message);
        $message = preg_replace('/\s*```$/', '', $message);
        $json = json_decode($message, true);
        if (!is_array($json) && preg_match('/\{.*\}/s', $message, $m)) {
            $json = json_decode($m[0], true);
        }
        if (is_array($json)) {
            return array(
                'title' => (string)($json['title'] ?? ''),
                'content' => (string)($json['content'] ?? ''),
            );
        }
        return array('title' => '', 'content' => $message);
    }

    private static function safe_excerpt($text, $length = 300)
    {
        $text = wp_strip_all_tags((string)$text);
        $text = preg_replace('/sk-[A-Za-z0-9_\-]{12,}/', 'sk-***', $text);
        $text = preg_replace('/Bearer\s+[A-Za-z0-9._\-]+/i', 'Bearer ***', $text);
        $text = preg_replace('/\s+/u', ' ', $text);
        return wp_html_excerpt(trim($text), max(80, (int)$length));
    }
}
