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

            var move = Headlines.move;
            Headlines.move = function(mode, params = {}) {
                Article.cdmMoveToId(Article.getActive(), {force_to_top: false});
                move.bind(this)(mode, params);
            }
        });

        PluginHost.register(
            PluginHost.HOOK_ARTICLE_RENDERED_CDM,
		    function (doc) {
                var inner = doc.querySelector('div.content-inner');
                if ([...inner.childNodes].map(x=>x.tagName).join(',') == 'DIV,BR,HR,BR,DIV'
                    && inner.firstChild.childNodes.length
                    && inner.firstChild.firstChild.outerHTML == '<h2>SUMMARY</h2>') {
                    var content = inner.lastChild;
                    var results = [...content.childNodes].map(x => x.nodeName == '#text' ? x.textContent : "\n");
                    var pre = document.createElement('pre');
                    pre.textContent = results.join('');
                    inner.replaceChild(pre, content);
                }
                return true;
            });
    });
});

