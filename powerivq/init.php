<?php
class PowerIVQ extends Plugin {
    private $host;

    function about() {
        return array(1.0,
            'Customizations for powerivq',
            'powerivq',
            false);
    }

    function init($host) {
        $this->host = $host;
        $host->add_hook($host::HOOK_HOTKEY_MAP, $this);
    }

    function get_js() {
        return file_get_contents(dirname(__FILE__) . '/init.js');
    }

    function hook_hotkey_map($hotkeys) {
        $hotkey_overrides = array(
            "j" => "next_article_noscroll",
            "J" => "next_article_noscroll",
            "k" => "prev_article_noscroll",
            "K" => "prev_article_noscroll"
        );

        return array_merge($hotkeys, $hotkey_overrides);
    }

    function api_version() {
        return 2;
    }
}
