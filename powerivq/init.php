<?php
class PowerIVQ extends Plugin {
    private $host;

    function about() {
        return array(1.0,
            "Customizations for powerivq",
            "powerivq",
            false);
    }

    function init($host) {
        $this->host = $host;
    }

    function get_js() {
        return file_get_contents(dirname(__FILE__) . "/init.js");
    }

    function api_version() {
        return 2;
    }
}
