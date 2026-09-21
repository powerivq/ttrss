require(['dojo/_base/kernel', 'dojo/ready'], function(dojo, ready) {
    ready(function() {
        PluginHost.register(PluginHost.HOOK_INIT_COMPLETE, function() {
            const origOpenInNewWindow = Article.openInNewWindow;
            Article.openInNewWindow = function(id) {
                const href = Headlines.objectById(id)?.link;
                if (!href) return origOpenInNewWindow.call(this, id);

                window.open(href, '_blank', 'noopener,noreferrer');
                Headlines.toggleUnread(id, 0);
            };

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
                    var pre = document.createElement('pre');
                    pre.innerHTML = inner.lastChild.innerHTML;
                    inner.replaceChild(pre, inner.lastChild);
                }
                return true;
            });
    });
});

