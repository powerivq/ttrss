window.JevLabelRules = {
    add(label = '', question = '') {
        const container = document.getElementById('jev-label-rule-list');
        if (!container) return;

        const row = document.createElement('div');
        row.className = 'jev-label-rule';
        row.innerHTML = `
            <label>
                <span>Label name</span>
                <input type="text" class="jev-label-rule-name" placeholder="technology">
            </label>
            <label>
                <span>Yes/no question</span>
                <input type="text" class="jev-label-rule-question" placeholder="Is this article primarily about technology?">
            </label>
            <button type="button" class="jev-label-rule-remove" title="Remove label rule" aria-label="Remove label rule">
                <i class="material-icons">close</i>
            </button>`;

        row.querySelector('.jev-label-rule-name').value = label;
        row.querySelector('.jev-label-rule-question').value = question;
        row.querySelector('.jev-label-rule-remove').addEventListener('click', () => this.remove(row));
        container.appendChild(row);
        row.querySelector('.jev-label-rule-name').focus();
    },

    remove(row) {
        const container = document.getElementById('jev-label-rule-list');
        row.remove();
        if (container && !container.querySelector('.jev-label-rule')) this.add();
    },

    serialize() {
        const rows = document.querySelectorAll('#jev-label-rule-list .jev-label-rule');
        const rules = [];
        const seen = new Set();

        for (const row of rows) {
            const label = row.querySelector('.jev-label-rule-name').value.trim();
            const question = row.querySelector('.jev-label-rule-question').value.trim();
            if (!label && !question) continue;
            if (!label || !question) {
                Notify.error('Every label rule needs both a label name and a yes/no question.');
                return false;
            }
            if (label.includes('|')) {
                Notify.error('Label names cannot contain the | character.');
                return false;
            }

            const key = label.toLowerCase();
            if (seen.has(key)) {
                Notify.error(`Duplicate label name: ${label}`);
                return false;
            }
            seen.add(key);
            rules.push(`${label} | ${question}`);
        }

        if (!rules.length) {
            Notify.error('Add at least one label rule.');
            return false;
        }

        return rules.join('\n');
    }
};
