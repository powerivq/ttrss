window.JevTagRules = {
    add(tag = '', question = '') {
        const container = document.getElementById('jev-tag-rule-list');
        if (!container) return;

        const row = document.createElement('div');
        row.className = 'jev-tag-rule';
        row.innerHTML = `
            <label>
                <span>Tag name</span>
                <input type="text" class="jev-tag-rule-name" placeholder="technology">
            </label>
            <label>
                <span>Yes/no question</span>
                <input type="text" class="jev-tag-rule-question" placeholder="Is this article primarily about technology?">
            </label>
            <button type="button" class="jev-tag-rule-remove" title="Remove tag rule" aria-label="Remove tag rule">
                <i class="material-icons">close</i>
            </button>`;

        row.querySelector('.jev-tag-rule-name').value = tag;
        row.querySelector('.jev-tag-rule-question').value = question;
        row.querySelector('.jev-tag-rule-remove').addEventListener('click', () => this.remove(row));
        container.appendChild(row);
        row.querySelector('.jev-tag-rule-name').focus();
    },

    remove(row) {
        const container = document.getElementById('jev-tag-rule-list');
        row.remove();
        if (container && !container.querySelector('.jev-tag-rule')) this.add();
    },

    serialize() {
        const rows = document.querySelectorAll('#jev-tag-rule-list .jev-tag-rule');
        const rules = [];
        const seen = new Set();

        for (const row of rows) {
            const tag = row.querySelector('.jev-tag-rule-name').value.trim();
            const question = row.querySelector('.jev-tag-rule-question').value.trim();
            if (!tag && !question) continue;
            if (!tag || !question) {
                Notify.error('Every tag rule needs both a tag name and a yes/no question.');
                return false;
            }
            if (tag.includes('|')) {
                Notify.error('Tag names cannot contain the | character.');
                return false;
            }

            const key = tag.toLowerCase();
            if (seen.has(key)) {
                Notify.error(`Duplicate tag name: ${tag}`);
                return false;
            }
            seen.add(key);
            rules.push(`${tag} | ${question}`);
        }

        if (!rules.length) {
            Notify.error('Add at least one tag rule.');
            return false;
        }

        return rules.join('\n');
    }
};
