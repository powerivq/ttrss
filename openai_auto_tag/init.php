<?php
class OpenAI_Auto_Tag extends Plugin {
    private $host;

    private static function default_prompt() {
        return "Choose every relevant tag for the article from the permissible tag list. " .
            "Return only a JSON array of tag names, for example [\"technology\", \"business\"]. " .
            "Do not invent tags and do not include explanations. An empty array is allowed.\n\n" .
            "Permissible tags:\n{permissible_tags}\n\nTitle:\n{title}\n\nArticle:\n{content}";
    }

    function about() {
        return [1.0, "Assign feed-specific tags using an OpenAI-compatible API", "powerivq"];
    }

    function api_version() {
        return 2;
    }

    function init($host) {
        $this->host = $host;

        if ($host->get_pdo()->getAttribute(PDO::ATTR_DRIVER_NAME) !== "pgsql") {
            user_error("OpenAI_Auto_Tag: Only PostgreSQL is supported", E_USER_ERROR);
        }

        $host->add_filter_action($this, "openai_auto_tag", __("Generate OpenAI Tags"));
        $host->add_hook($host::HOOK_PREFS_TAB, $this);
        $host->add_hook($host::HOOK_FETCH_FEED, $this);
    }

    private function init_database() {
        $this->host->get_pdo()->exec(file_get_contents(__DIR__ . "/init_pgsql.sql"));
    }

    function hook_fetch_feed($feed_data, $fetch_url, $owner_uid, $feed, $num, $auth_login, $auth_pass) {
        $this->init_database();
        return $feed_data;
    }

    function hook_article_filter_action($article, $action) {
        if ($action !== "openai_auto_tag") return $article;

        $guid = $article["guid_hashed"] ?? null;
        $owner_uid = $article["owner_uid"] ?? null;
        if (!$guid || !$owner_uid) return $article;

        try {
            $this->init_database();
            $sth = $this->host->get_pdo()->prepare(
                "INSERT INTO ttrss_auto_tag_queue (guid, owner_uid) VALUES (?, ?) " .
                "ON CONFLICT (guid, owner_uid) DO UPDATE SET failure_count = 0, last_failed = NULL"
            );
            $sth->execute([$guid, $owner_uid]);
            error_log("OpenAI_Auto_Tag: Queued article guid=$guid for user $owner_uid");
        } catch (Exception $e) {
            error_log("OpenAI_Auto_Tag: Unable to queue article: " . $e->getMessage());
        }

        return $article;
    }

    function hook_prefs_tab($args) {
        if ($args !== "prefFeeds") return;

        $this->init_database();
        $pdo = $this->host->get_pdo();
        $owner_uid = $_SESSION["uid"];

        $api_key = $this->host->get($this, "openai_api_key", "");
        $base_url = $this->host->get($this, "openai_base_url", "https://api.openai.com/v1");
        $model = $this->host->get($this, "openai_model", "gpt-4o-mini");
        $max_text_length = (int)$this->host->get($this, "max_text_length", 4000);

        $sth = $pdo->prepare(
            "SELECT f.id, f.title, COALESCE(s.enabled, FALSE) AS enabled, " .
            "COALESCE(s.prompt, ?) AS prompt, COALESCE(s.permissible_tags, '') AS permissible_tags " .
            "FROM ttrss_feeds f LEFT JOIN ttrss_auto_tag_feed_settings s " .
            "ON s.feed_id = f.id AND s.owner_uid = f.owner_uid " .
            "WHERE f.owner_uid = ? ORDER BY LOWER(f.title)"
        );
        $sth->execute([self::default_prompt(), $owner_uid]);
        $feeds = $sth->fetchAll(PDO::FETCH_ASSOC);

        $h = function($value) { return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8"); };

        print '<div dojoType="dijit.layout.AccordionPane" title="<i class=\'material-icons\'>label</i> ' . __("OpenAI Auto Tag Settings") . '">';
        print "<h2>" . __("API Configuration") . "</h2>";
        print '<form dojoType="dijit.form.Form">';
        print '<script type="dojo/method" event="onSubmit" args="evt">evt.preventDefault(); if (this.validate()) { xhr.post("backend.php", this.getValues(), (reply) => { Notify.info(reply); }); }</script>';
        print \Controls\pluginhandler_tags($this, "save");

        print '<div class="form-group"><input dojoType="dijit.form.ValidationTextBox" required="1" name="openai_api_key" style="width:30em" value="' . $h($api_key) . '">&nbsp;<label>' . __("API Key") . '</label></div>';
        print '<div class="form-group"><input dojoType="dijit.form.ValidationTextBox" required="1" name="openai_base_url" style="width:30em" value="' . $h($base_url) . '">&nbsp;<label>' . __("API Base URL") . '</label></div>';
        print '<div class="form-group"><input dojoType="dijit.form.ValidationTextBox" required="1" name="openai_model" style="width:20em" value="' . $h($model) . '">&nbsp;<label>' . __("Model") . '</label></div>';
        print '<div class="form-group"><input dojoType="dijit.form.NumberSpinner" required="1" name="max_text_length" style="width:7em" value="' . $max_text_length . '" min="500" max="20000">&nbsp;<label>' . __("Max Context Length (Chars)") . '</label></div>';

        print "<h2>" . __("Feed Rules") . "</h2>";
        print '<p>' . __("Enable and configure tagging independently for each feed. Enter one permissible tag per line (commas are also accepted).") . '</p>';

        foreach ($feeds as $feed) {
            $id = (int)$feed["id"];
            $checked = filter_var($feed["enabled"], FILTER_VALIDATE_BOOLEAN) ? " checked" : "";
            print '<fieldset style="margin:1em 0;padding:1em;border:1px solid var(--border-default)">';
            print '<legend><strong>' . $h($feed["title"]) . '</strong></legend>';
            print '<input dojoType="dijit.form.TextBox" type="hidden" name="feed_ids[]" value="' . $id . '">';
            print '<label><input dojoType="dijit.form.CheckBox" type="checkbox" name="feed_enabled_' . $id . '" value="1"' . $checked . '> ' . __("Enable automatic tag selection for this feed") . '</label>';
            print '<div class="form-group" style="margin-top:1em"><label style="display:block">' . __("Permissible Tags") . '</label>';
            print '<textarea dojoType="dijit.form.SimpleTextarea" name="feed_tags_' . $id . '" style="width:90%;height:7em;font-family:monospace">' . $h($feed["permissible_tags"]) . '</textarea></div>';
            print '<div class="form-group"><label style="display:block">' . __("Prompt Template") . '</label>';
            print '<textarea dojoType="dijit.form.SimpleTextarea" name="feed_prompt_' . $id . '" style="width:90%;height:14em;font-family:monospace">' . $h($feed["prompt"]) . '</textarea>';
            print '<p class="text-muted">' . __("Available placeholders: {title}, {content}, {permissible_tags}") . '</p></div>';
            print '</fieldset>';
        }

        print '<button dojoType="dijit.form.Button" type="submit" class="alt-primary">' . __("Save") . '</button>';
        print '</form></div>';
    }

    function save() {
        $this->init_database();
        $pdo = $this->host->get_pdo();
        $owner_uid = $_SESSION["uid"];

        $this->host->set($this, "openai_api_key", trim($_POST["openai_api_key"] ?? ""));
        $this->host->set($this, "openai_base_url", rtrim(trim($_POST["openai_base_url"] ?? ""), "/"));
        $this->host->set($this, "openai_model", trim($_POST["openai_model"] ?? ""));
        $this->host->set($this, "max_text_length", max(500, min(20000, (int)($_POST["max_text_length"] ?? 4000))));

        $feed_ids = array_values(array_unique(array_map("intval", (array)($_POST["feed_ids"] ?? []))));
        $owns_feed = $pdo->prepare("SELECT 1 FROM ttrss_feeds WHERE id = ? AND owner_uid = ?");
        $upsert = $pdo->prepare(
            "INSERT INTO ttrss_auto_tag_feed_settings (owner_uid, feed_id, enabled, prompt, permissible_tags) " .
            "VALUES (?, ?, ?, ?, ?) ON CONFLICT (owner_uid, feed_id) DO UPDATE SET " .
            "enabled = EXCLUDED.enabled, prompt = EXCLUDED.prompt, permissible_tags = EXCLUDED.permissible_tags"
        );

        $pdo->beginTransaction();
        try {
            foreach ($feed_ids as $feed_id) {
                $owns_feed->execute([$feed_id, $owner_uid]);
                if (!$owns_feed->fetchColumn()) continue;

                $enabled = isset($_POST["feed_enabled_$feed_id"]);
                $prompt = trim($_POST["feed_prompt_$feed_id"] ?? self::default_prompt());
                $tags = trim($_POST["feed_tags_$feed_id"] ?? "");
                if ($prompt === "") $prompt = self::default_prompt();
                $upsert->execute([$owner_uid, $feed_id, $enabled ? "true" : "false", $prompt, $tags]);
            }
            $pdo->commit();
        } catch (Exception $e) {
            $pdo->rollBack();
            throw $e;
        }

        echo __("Settings saved.");
    }
}
?>
