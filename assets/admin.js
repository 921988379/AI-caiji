(function () {
    var i18n = window.wpCaijiI18n || {};
    function t(key, fallback) { return i18n[key] || fallback; }
    function ready(fn) {
        if (document.readyState !== 'loading') fn();
        else document.addEventListener('DOMContentLoaded', fn);
    }

    function closest(el, selector) {
        while (el && el.nodeType === 1) {
            if (el.matches(selector)) return el;
            el = el.parentElement;
        }
        return null;
    }

    ready(function () {
    var i18n = window.wpCaijiI18n || {};
    function t(key, fallback) { return i18n[key] || fallback; }
        document.querySelectorAll('.wp-caiji-section > h2').forEach(function (heading) {
            heading.addEventListener('click', function () {
                var modal = closest(heading, '.wp-caiji-modal');
                if (modal) return;
                heading.parentElement.classList.toggle('is-collapsed');
            });
        });

        document.querySelectorAll('[data-wp-caiji-copy]').forEach(function (btn) {
            btn.addEventListener('click', function (e) {
                e.preventDefault();
                var text = btn.getAttribute('data-wp-caiji-copy') || '';
                if (navigator.clipboard) {
                    navigator.clipboard.writeText(text).then(function () {
    var i18n = window.wpCaijiI18n || {};
    function t(key, fallback) { return i18n[key] || fallback; }
                        var old = btn.textContent;
                        btn.textContent = t('copied', '已复制');
                        setTimeout(function () {
    var i18n = window.wpCaijiI18n || {};
    function t(key, fallback) { return i18n[key] || fallback; } btn.textContent = old; }, 1200);
                    });
                }
            });
        });

        document.querySelectorAll('.wp-caiji-confirm').forEach(function (el) {
            el.addEventListener('click', function (e) {
                var msg = el.getAttribute('data-confirm') || t('confirmDefault', '确定执行？');
                if (!confirm(msg)) e.preventDefault();
            });
        });

        document.querySelectorAll('.wp-caiji-form-action').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var action = btn.getAttribute('data-wp-caiji-action') || '';
                var form = closest(btn, 'form');
                if (!action || !form) return;
                var actionInput = form.querySelector('input[name="action"]');
                if (actionInput) actionInput.value = action;
            });
        });

        function updateRuleMethod(select) {
            var group = select.getAttribute('data-wp-caiji-rule-group') || '';
            var method = select.value || 'selector';
            var form = closest(select, 'form') || document;
            if (!group) return;
            form.querySelectorAll('[data-wp-caiji-rule-group="' + group + '"][data-wp-caiji-rule-method]').forEach(function (row) {
                row.hidden = row.getAttribute('data-wp-caiji-rule-method') !== method;
            });
        }

        document.querySelectorAll('.wp-caiji-rule-method').forEach(function (select) {
            updateRuleMethod(select);
            select.addEventListener('change', function () {
                updateRuleMethod(select);
            });
        });

        document.querySelectorAll('.wp-caiji-modal').forEach(function (modal) {
            var form = modal.querySelector('form');
            var dirty = false;
            if (form) {
                form.addEventListener('input', function () { dirty = true; });
                form.addEventListener('change', function () { dirty = true; });
                form.addEventListener('submit', function () { dirty = false; });
            }
            modal.wpCaijiCanClose = function () {
                if (!dirty) return true;
                return confirm(t('unsavedConfirm', '有未保存内容，确定关闭吗？'));
            };

            var tabs = modal.querySelector('.wp-caiji-rule-tabs');
            var sections = Array.prototype.slice.call(modal.querySelectorAll('.wp-caiji-section'));
            if (!tabs || !sections.length) return;

            function activate(index) {
                sections.forEach(function (section, i) {
                    var active = i === index;
                    section.classList.toggle('is-active-tab', active);
                    section.hidden = !active;
                });
                tabs.querySelectorAll('button').forEach(function (btn, i) {
                    var active = i === index;
                    btn.classList.toggle('is-active', active);
                    btn.setAttribute('aria-selected', active ? 'true' : 'false');
                    btn.tabIndex = active ? 0 : -1;
                });
                var body = modal.querySelector('.wp-caiji-modal-body');
                if (body) body.scrollTop = 0;
            }

            sections.forEach(function (section, i) {
                var heading = section.querySelector(':scope > h2');
                var label = heading ? heading.textContent.trim() : t('groupPrefix', '分组') + ' ' + (i + 1);
                var btn = document.createElement('button');
                btn.type = 'button';
                btn.textContent = label;
                btn.setAttribute('role', 'tab');
                btn.setAttribute('aria-selected', i === 0 ? 'true' : 'false');
                btn.addEventListener('click', function () { activate(i); });
                btn.addEventListener('keydown', function (e) {
                    if (e.key !== 'ArrowRight' && e.key !== 'ArrowLeft') return;
                    e.preventDefault();
                    var next = e.key === 'ArrowRight' ? i + 1 : i - 1;
                    if (next < 0) next = sections.length - 1;
                    if (next >= sections.length) next = 0;
                    activate(next);
                    var nextBtn = tabs.querySelectorAll('button')[next];
                    if (nextBtn) nextBtn.focus();
                });
                tabs.appendChild(btn);
            });
            activate(0);
        });

        function openModal(id) {
            var modal = document.getElementById(id);
            if (!modal) return;
            modal.classList.add('is-open');
            modal.setAttribute('aria-hidden', 'false');
            document.body.classList.add('wp-caiji-modal-opened');
            var focusTarget = modal.querySelector('input[name="name"]') || modal.querySelector('button, input, textarea, select, a[href]');
            if (focusTarget) setTimeout(function () {
    var i18n = window.wpCaijiI18n || {};
    function t(key, fallback) { return i18n[key] || fallback; } focusTarget.focus(); }, 50);
        }

        function closeModal(modal) {
            if (!modal) return;
            if (typeof modal.wpCaijiCanClose === 'function' && !modal.wpCaijiCanClose()) return;
            var openAfterClose = modal.getAttribute('data-wp-caiji-open-after-close') || '';
            modal.classList.remove('is-open');
            modal.setAttribute('aria-hidden', 'true');
            if (openAfterClose) {
                openModal(openAfterClose);
                return;
            }
            if (!document.querySelector('.wp-caiji-modal.is-open')) {
                document.body.classList.remove('wp-caiji-modal-opened');
            }
        }

        document.querySelectorAll('.wp-caiji-modal-open').forEach(function (btn) {
            btn.addEventListener('click', function () {
                openModal(btn.getAttribute('data-target'));
            });
        });

        document.querySelectorAll('[data-wp-caiji-modal-close]').forEach(function (el) {
            el.addEventListener('click', function () {
                closeModal(closest(el, '.wp-caiji-modal'));
            });
        });

        document.addEventListener('keydown', function (e) {
            if (e.key !== 'Escape') return;
            var openModals = document.querySelectorAll('.wp-caiji-modal.is-open');
            var open = openModals.length ? openModals[openModals.length - 1] : null;
            if (open) closeModal(open);
        });

        if (document.querySelector('.wp-caiji-modal.is-open')) {
            document.body.classList.add('wp-caiji-modal-opened');
        }


        document.querySelectorAll('.wp-caiji-ai-provider-cascade').forEach(function (wrap) {
            var form = closest(wrap, 'form') || document;
            var kindSelect = wrap.querySelector('.wp-caiji-ai-provider-kind');
            var providerSelect = wrap.querySelector('.wp-caiji-ai-provider');
            var tutorialBtn = wrap.querySelector('.wp-caiji-ai-tutorial-open');
            var endpointInput = form.querySelector('.wp-caiji-ai-endpoint');
            var modelInput = form.querySelector('.wp-caiji-ai-model');
            var hint = form.querySelector('.wp-caiji-ai-provider-hint');
            var fillBtn = form.querySelector('.wp-caiji-ai-fill-endpoint');

            function selectedProvider() {
                return providerSelect ? providerSelect.options[providerSelect.selectedIndex] : null;
            }

            function syncProviderOptions() {
                if (!kindSelect || !providerSelect) return;
                var kind = kindSelect.value || 'transit';
                var firstVisible = null;
                var selectedVisible = false;
                Array.prototype.forEach.call(providerSelect.options, function (option) {
                    var visible = (option.getAttribute('data-kind') || '') === kind;
                    option.hidden = !visible;
                    option.disabled = !visible;
                    if (visible && !firstVisible) firstVisible = option;
                    if (visible && option.selected) selectedVisible = true;
                });
                if (!selectedVisible && firstVisible) providerSelect.value = firstVisible.value;
            }

            function updateHint() {
                var provider = selectedProvider();
                if (!provider || !hint) return;
                var models = (provider.getAttribute('data-models') || '').split(',').filter(Boolean);
                var region = provider.getAttribute('data-region') || '';
                var billing = provider.getAttribute('data-billing') || '';
                var desc = provider.getAttribute('data-description') || '';
                var prefix = [region, billing].filter(Boolean).join(' · ');
                var modelText = models.length ? (t('recommendedModelsPrefix', '推荐模型：') + models.join('、') + t('customModelHint', '；也可以手动输入中转站支持的任意模型名。')) : t('manualModelHint', '可以手动输入服务商或中转站支持的模型名。');
                hint.textContent = (prefix ? prefix + '。' : '') + (desc ? desc + ' ' : '') + modelText;
            }

            function fillEndpoint(force) {
                var provider = selectedProvider();
                if (!provider || !endpointInput) return;
                var endpoint = provider.getAttribute('data-endpoint') || '';
                if (force || !endpointInput.value.trim()) endpointInput.value = endpoint;
            }

            function fillModelIfEmpty() {
                var provider = selectedProvider();
                if (!provider || !modelInput || modelInput.value.trim()) return;
                var models = (provider.getAttribute('data-models') || '').split(',').filter(Boolean);
                if (models.length) modelInput.value = models[0];
            }

            function providerChanged(forceEndpoint) {
                fillEndpoint(!!forceEndpoint);
                fillModelIfEmpty();
                updateHint();
            }

            if (kindSelect) kindSelect.addEventListener('change', function () {
                syncProviderOptions();
                providerChanged(true);
            });
            if (providerSelect) providerSelect.addEventListener('change', function () { providerChanged(true); });
            if (fillBtn) fillBtn.addEventListener('click', function () { fillEndpoint(true); });
            if (tutorialBtn) tutorialBtn.addEventListener('click', function () {
                var provider = selectedProvider();
                var target = provider ? provider.getAttribute('data-tutorial-target') : '';
                if (target) openModal(target);
            });
            syncProviderOptions();
            providerChanged(false);
        });
    });
}());
