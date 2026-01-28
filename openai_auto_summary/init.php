<?php
class OpenAI_Auto_Summary extends Plugin {
    private $host;
    private $openai_api_key;
    private $openai_base_url;
    private $openai_model;
    private $summary_prompt;
    private $max_text_length;

    function about() {
        return array(1.0,
            "Automatically summarize articles using OpenAI API",
            "powerivq");
    }

    function init($host) {
        $this->host = $host;
        
        // Only support PostgreSQL
        $pdo = $this->host->get_pdo();
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver !== 'pgsql') {
            user_error("OpenAI_Auto_Summary: Only PostgreSQL is supported", E_USER_ERROR);
        }
        
        $this->openai_api_key = $this->host->get($this, "openai_api_key");
        $this->openai_base_url = $this->host->get($this, "openai_base_url", "https://api.openai.com/v1");
        $this->openai_model = $this->host->get($this, "openai_model", "gpt-4o-mini");
        $this->max_text_length = (int)$this->host->get($this, "max_text_length", 2000);
        
        // Updated default prompt for B2 French summary with specific XML-like tags
        $default_prompt = "Read the article below and produce output in French at B2 level. Format the output exactly using ONLY the tags shown below (no extra headings, no bullet points, no commentary).\n\n<title>\nStart with a clear, informative title in French.\n</title>\n\n<summary>\nWrite a concise summary in French that may be divided into short paragraphs if useful. The total length must be under 200 words. Use clear, natural, and accessible B2-level French with a neutral journalistic tone, mostly simple-to-moderate sentence structures, and common vocabulary. Focus on the main ideas and general context, avoid technical or minor details, and do not translate word for word—rewrite it as a native French journalist would for a general audience.\n</summary>\n\nThis is the end of the example output. The article is provided below.\n\nTitle:\n{title}\n\nArticle:\n{content}";
        
        $this->summary_prompt = $this->host->get($this, "summary_prompt", $default_prompt);

        if (empty($this->openai_base_url)) {
            $this->openai_base_url = "https://api.openai.com/v1";
        }

        $host->add_filter_action($this, 'openai_summary', __('Generate OpenAI Summary'));
        $host->add_hook($host::HOOK_PREFS_TAB, $this);
        $host->add_hook($host::HOOK_HOUSE_KEEPING, $this);
        $host->add_hook($host::HOOK_RENDER_ARTICLE_API, $this);
        $host->add_hook($host::HOOK_RENDER_ARTICLE_CDM, $this);
        $host->add_hook($host::HOOK_FETCH_FEED, $this);
    }

    function init_database() {
        $sql_file = __DIR__ . '/init_pgsql.sql';
        $sql = file_get_contents($sql_file);
        $this->host->get_pdo()->exec($sql);
    }

    function hook_article_filter_action($article, $action) {
        // Add article to queue for processing
        if ($action == "openai_summary") {
            $guid = isset($article["guid_hashed"]) ? $article["guid_hashed"] : null;
            $owner_uid = isset($article["owner_uid"]) ? $article["owner_uid"] : null;
            error_log("OpenAI_Auto_Summary: Adding article guid=$guid to queue for user $owner_uid");
            
            if ($guid && $owner_uid) {
                try {
                    $this->host->get_pdo()->prepare(
                        "INSERT INTO ttrss_summary_queue (guid, owner_uid) VALUES (?, ?) 
                         ON CONFLICT (guid, owner_uid) DO NOTHING"
                    )->execute([$guid, $owner_uid]);
                } catch (Exception $e) {
                    error_log("OpenAI_Auto_Summary: Error adding to queue: " . $e->getMessage());
                }
            }
        }
        
        return $article;
    }

    function api_version() {
        return 2;
    }

    function hook_prefs_tab($args) {
        if ($args != "prefFeeds") return;

        print "<div dojoType=\"dijit.layout.AccordionPane\" 
            title=\"<i class='material-icons'>description</i> ".__("OpenAI Summary Settings")."\">";

        print "<h2>" . __("Configuration") . "</h2>";

        print "<form dojoType=\"dijit.form.Form\">";

        print "<script type=\"dojo/method\" event=\"onSubmit\" args=\"evt\">
            evt.preventDefault();
            if (this.validate()) {
                xhr.post('backend.php', this.getValues(), (reply) => {
                    Notify.info(reply);
                });
            }
            </script>";

        print \Controls\pluginhandler_tags($this, "save");

        $openai_api_key = $this->host->get($this, "openai_api_key");
        $openai_base_url = $this->host->get($this, "openai_base_url", "https://api.openai.com/v1");
        $openai_model = $this->host->get($this, "openai_model", "gpt-4o-mini");
        $max_text_length = $this->host->get($this, "max_text_length", 2000);
        
        // Default prompt duplicated here for the settings form fallback
        $default_prompt = "Read the article below and produce output in French at B2 level. Format the output exactly using ONLY the tags shown below (no extra headings, no bullet points, no commentary).\n\n<title>\nStart with a clear, informative title in French.\n</title>\n\n<summary>\nWrite a concise summary in French that may be divided into short paragraphs if useful. The total length must be under 200 words. Use clear, natural, and accessible B2-level French with a neutral journalistic tone, mostly simple-to-moderate sentence structures, and common vocabulary. Focus on the main ideas and general context, avoid technical or minor details, and do not translate word for word—rewrite it as a native French journalist would for a general audience.\n</summary>\n\nThis is the end of the example output. The article is provided below.\n\nTitle:\n{title}\n\nArticle:\n{content}";
        
        $summary_prompt = $this->host->get($this, "summary_prompt", $default_prompt);

        print "<div class=\"form-group\">";
        print "<input dojoType=\"dijit.form.ValidationTextBox\" required=\"1\" name=\"openai_api_key\" style=\"width: 30em;\" value=\"$openai_api_key\">";
        print "&nbsp;<label for=\"openai_api_key\">" . __("API Key") . "</label>";
        print "</div>";

        print "<div class=\"form-group\">";
        print "<input dojoType=\"dijit.form.ValidationTextBox\" required=\"1\" name=\"openai_base_url\" style=\"width: 30em;\" value=\"$openai_base_url\">";
        print "&nbsp;<label for=\"openai_base_url\">" . __("API Base URL") . "</label>";
        print "</div>";

        print "<div class=\"form-group\">";
        print "<input dojoType=\"dijit.form.ValidationTextBox\" required=\"1\" name=\"openai_model\" style=\"width: 20em;\" value=\"$openai_model\">";
        print "&nbsp;<label for=\"openai_model\">" . __("Model") . "</label>";
        print "</div>";

        print "<div class=\"form-group\">";
        print "<input dojoType=\"dijit.form.NumberSpinner\" required=\"1\" name=\"max_text_length\" style=\"width: 7em;\" value=\"$max_text_length\" min=\"500\" max=\"10000\">";
        print "&nbsp;<label for=\"max_text_length\">" . __("Max Context Length (Chars)") . "</label>";
        print "</div>";

        print "<div class=\"form-group\">";
        print "<label for=\"summary_prompt\" style=\"display:block; margin-bottom:5px;\">" . __("Summary Prompt Template") . "</label>";
        print "<textarea dojoType=\"dijit.form.SimpleTextarea\" name=\"summary_prompt\" style=\"width: 90%; height: 250px; font-family: monospace;\">$summary_prompt</textarea>";
        print "<p class=\"text-muted\">" . __("Available placeholders: {title}, {content}") . "</p>";
        print "</div>";

        print "<button dojoType=\"dijit.form.Button\" type=\"submit\" class=\"alt-primary\">".__("Save")."</button>";
        print "</form>";
        print "</div>";
    }

    function hook_house_keeping() {
        $script_path = __DIR__ . '/background_task.php';
        
        // Check if any worker is already running
        $lock_files = glob('/tmp/ttrss-summary-background-*.lock');
        foreach ($lock_files as $lock_file) {
            if (file_exists($lock_file)) {
                $pid = file_get_contents($lock_file);
                // Check if process is still running
                if (file_exists("/proc/$pid")) {
                    // Process is still running, don't start a new one
                    return;
                }
            }
        }
        
        // Start the background task
        shell_exec("php " . escapeshellarg($script_path) . " > /dev/null 2>&1 &");
    }

    private function render_article_with_summary($article) {
        // Get guid from article data
        $guid = isset($article["guid"]) ? $article["guid"] : null;
        $owner_uid = isset($article["owner_uid"]) ? $article["owner_uid"] : $_SESSION['uid'];

        if (!$guid || !$owner_uid) {
            return $article;
        }

        // Query database for summary using guid
        $sth = $this->host->get_pdo()->prepare('SELECT summary FROM ttrss_summary WHERE guid = ? AND owner_uid = ?');
        $sth->execute([$guid, $owner_uid]);
        $result = $sth->fetch();
        
        if ($result && !empty($result['summary'])) {
            $summary = $result['summary'];
            
            // Construct HTML: <div><h2>TITLE</h2>CONTENT</div><br/><hr/><br/><div>%s</div>
            $summary_html = "<div>" . nl2br($summary) . "</div><br/><hr/><br/>";
            
            // Prepend summary to content
            $article["content"] = $summary_html . "<div>" . $article["content"] . "</div>";
        }
        
        return $article;
    }

    function hook_render_article_api($params) {
        $headline = $params["headline"];
        $headline = $this->render_article_with_summary($headline);
        return $headline;
    }

    function hook_render_article_cdm($article) {
        return $this->render_article_with_summary($article);
    }

    function hook_fetch_feed($feed_data, $fetch_url, $owner_uid, $feed, $num, $auth_login, $auth_pass) {
        $this->init_database();
        return $feed_data;
    }

    function save() {
        $this->host->set($this, "openai_api_key", $_POST["openai_api_key"]);
        $this->host->set($this, "openai_base_url", $_POST["openai_base_url"]);
        $this->host->set($this, "openai_model", $_POST["openai_model"]);
        $this->host->set($this, "max_text_length", (int)$_POST["max_text_length"]);
        $this->host->set($this, "summary_prompt", $_POST["summary_prompt"]);
        echo __("Settings saved.");
    }
}
?>
