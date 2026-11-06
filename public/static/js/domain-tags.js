(function () {
    if (typeof Vue === 'undefined') return;

    Vue.component('domain-tags', {
        props: {
            value: { default: '' },
            placeholder: { type: String, default: '输入域名后回车，支持粘贴多个' },
            disabled: { type: Boolean, default: false }
        },
        data: function () {
            return {
                input: '',
                focused: false
            };
        },
        computed: {
            domains: function () {
                var list = [];
                var seen = {};
                String(this.value || '').split(',').forEach(function (item) {
                    var domain = item.trim().toLowerCase();
                    if (!domain || seen[domain]) return;
                    seen[domain] = true;
                    list.push(domain);
                });
                return list;
            }
        },
        methods: {
            emit: function (list) {
                this.$emit('input', list.join(','));
            },
            add: function (raw) {
                if (this.disabled) return;
                var list = this.domains.slice();
                var seen = {};
                list.forEach(function (item) {
                    seen[item] = true;
                });
                String(raw || '').split(/[\s,;，；]+/).forEach(function (item) {
                    var domain = item.trim().toLowerCase().replace(/^https?:\/\//, '').replace(/\/+$/, '');
                    if (!domain || seen[domain]) return;
                    seen[domain] = true;
                    list.push(domain);
                });
                this.input = '';
                this.emit(list);
            },
            remove: function (index) {
                if (this.disabled) return;
                var list = this.domains.slice();
                list.splice(index, 1);
                this.emit(list);
            },
            clearAll: function () {
                if (this.disabled) return;
                this.input = '';
                this.emit([]);
            },
            onKeydown: function (event) {
                if (event.key === 'Enter' || event.key === ',' || event.key === '，') {
                    event.preventDefault();
                    this.add(this.input);
                } else if (event.key === 'Backspace' && !this.input && this.domains.length) {
                    this.remove(this.domains.length - 1);
                }
            },
            onPaste: function (event) {
                var text = (event.clipboardData || window.clipboardData).getData('text');
                if (!text || !/[\s,;，；]/.test(text)) return;
                event.preventDefault();
                this.add(text);
            },
            onBlur: function () {
                this.focused = false;
                if (this.input.trim()) this.add(this.input);
            }
        },
        template:
            '<div class="domain-tags-wrap">' +
                '<div class="domain-tags" :class="{ focused: focused, disabled: disabled }" @click="$refs.input.focus()">' +
                    '<span class="domain-tag" v-for="(domain, index) in domains" :key="index" :title="domain">' +
                        '<span class="domain-tag-text">{{ domain }}</span>' +
                        '<a class="domain-tag-remove" v-if="!disabled" @click.stop="remove(index)" title="移除">&times;</a>' +
                    '</span>' +
                    '<input ref="input" class="domain-tag-input" type="text" v-model="input"' +
                        ' :placeholder="domains.length ? \'\' : placeholder" :disabled="disabled"' +
                        ' @keydown="onKeydown" @paste="onPaste" @blur="onBlur" @focus="focused = true">' +
                '</div>' +
                '<div class="domain-tags-meta" v-if="domains.length && !disabled">' +
                    '<span>已添加 {{ domains.length }} 个域名</span>' +
                    '<a class="domain-tags-clear" @click.stop="clearAll">清空</a>' +
                '</div>' +
            '</div>'
    });
})();
