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
        });
    });
});
