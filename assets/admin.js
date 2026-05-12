(function () {
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
                        var old = btn.textContent;
                        btn.textContent = '已复制';
                        setTimeout(function () { btn.textContent = old; }, 1200);
                    });
                }
            });
        });

        document.querySelectorAll('.wp-caiji-confirm').forEach(function (el) {
            el.addEventListener('click', function (e) {
                var msg = el.getAttribute('data-confirm') || '确定执行？';
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
                return confirm('有未保存内容，确定关闭吗？');
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
                var label = heading ? heading.textContent.trim() : '分组 ' + (i + 1);
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
            if (focusTarget) setTimeout(function () { focusTarget.focus(); }, 50);
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
    });
}());
