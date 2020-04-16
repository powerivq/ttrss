require(['dojo/_base/kernel', 'dojo/ready'], function(dojo, ready) {
    ready(function() {
        PluginHost.register(PluginHost.HOOK_INIT_COMPLETE, function() {
            var origOpenInNewWindow = Article.openInNewWindow;
            Article.openInNewWindow = function(id) {
                const row = $('RROW-' + id);
                if (!row) return origOpenInNewWindow(id);
                var title = row.querySelector('.title');
                var href = title.getAttribute('href');
                window.open(href, '_blank', 'noopener');
                Headlines.toggleUnread(id, 0);
            }

            var origSetActive = Article.setActive;
            Article.setActive = function(id) {
                var activeId = Article.getActive();
                if (activeId && activeId != id) {
                    const row = $('RROW-' + activeId);
                    if (row && !row.hasAttribute('data-content')) {
                        const container = row.querySelector('.content-inner');
                        row.setAttribute('data-content', container.innerHTML);
                        container.innerHTML = '';
                    }
                }
                return origSetActive(id);
            }
        });
    });
});
