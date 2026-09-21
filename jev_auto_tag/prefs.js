window.JevLabelRules = {
    options(selectedLabelId = '') {
        const options = [new Option('Choose a label...', '')];
        const labels = [...document.querySelectorAll('#jev-available-labels option')]
            .map(option => ({id: option.value, caption: option.textContent}));
        const selectedId = String(selectedLabelId);

        for (const label of labels) {
            options.push(new Option(label.caption, label.id, false, label.id === selectedId));
        }

        if (selectedId && !labels.some(label => label.id === selectedId)) {
            options.push(new Option(`Deleted label #${selectedId}; choose a replacement`, '', false, true));
        }

        if (!labels.length && !selectedId) {
            options[0].text = 'No labels available';
            options[0].disabled = true;
        }

        return options;
    },

    add(labelId = '', question = '', focus = true) {
        const container = document.getElementById('jev-label-rule-list');
        if (!container) return;

        const row = document.createElement('div');
        row.className = 'jev-label-rule';
        row.innerHTML = `
            <label>
                <span>Label</span>
                <select class="jev-label-rule-name"></select>
            </label>
            <label>
                <span>Yes/no question</span>
                <input type="text" class="jev-label-rule-question" placeholder="Is this article primarily about technology?">
            </label>
            <button type="button" class="jev-label-rule-remove" title="Remove label rule" aria-label="Remove label rule">
                <i class="material-icons">close</i>
            </button>`;

        const select = row.querySelector('.jev-label-rule-name');
        select.append(...this.options(labelId));
        row.querySelector('.jev-label-rule-question').value = question;
        row.querySelector('.jev-label-rule-remove').addEventListener('click', () => this.remove(row));
        container.appendChild(row);
        if (focus) select.focus();
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
            const labelId = row.querySelector('.jev-label-rule-name').value;
            const question = row.querySelector('.jev-label-rule-question').value.trim();
            if (!labelId && !question) continue;
            if (!labelId || !question) {
                Notify.error('Every label rule needs both a selected label and a yes/no question.');
                return false;
            }
            if (seen.has(labelId)) {
                Notify.error('Each label can only be used once.');
                return false;
            }
            seen.add(labelId);
            rules.push(`${labelId} | ${question}`);
        }

        if (!rules.length) {
            Notify.error('Add at least one label rule.');
            return false;
        }

        return rules.join('\n');
    }
};


window.JevFeedDecisions = {
    toggle(optionsId, enabled) {
        const options = document.getElementById(optionsId);
        if (options) options.hidden = !enabled;
    }
};
