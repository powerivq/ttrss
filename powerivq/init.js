require(['dojo/_base/kernel', 'dojo/ready'], function(dojo, ready) {
    ready(function() {
        var cache = {};
        var prevId = null;

        PluginHost.register(PluginHost.HOOK_INIT_COMPLETE, function() {
            Article.openInNewWindow = function(id) {
                var doc = document.getElementById('RROW-' + id);
                var title = doc.select('.title')[0];
                var href = title.getAttribute('href');
                window.open(href, '_blank', 'noopener');
                Headlines.toggleUnread(id, 0);
            }
        });

        PluginHost.register(PluginHost.HOOK_ARTICLE_SET_ACTIVE, function(id) {
            if (cache.hasOwnProperty(id)) {
                var doc = document.getElementById('RROW-' + id);
                var content = doc.select('.content-inner')[0];
                content.innerHTML = cache[id];
            }
            if (prevId) {
                var doc = document.getElementById('RROW-' + prevId);
                var content = doc.select('.content-inner')[0];
                cache[prevId] = content.innerHTML;
                content.innerHTML = '';
            }
            prevId = id;
            return true;
        });

        PluginHost.register(PluginHost.FEED_SET_ACTIVE, function() {
            cache = {};
            prevId = null;
            return true;
        });
    });
});
