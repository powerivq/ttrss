<?php
class Jev_Auto_Tag extends Plugin {
    private const DEFAULT_BASE_URL = "https://api.typesafe.ai/v1";
    private const DEFAULT_MODEL = "jev-latest";
    private const DEFAULT_THRESHOLD = 0.7;
    private const DEFAULT_MAX_TEXT_LENGTH = 12000;

    private $host;

    private static function default_state_template() {
        return "Title:\n{title}\n\nArticle:\n{content}";
    }

    private static function default_question_template() {
        return "Is this article primarily and materially about the tag \"{tag}\"?";
    }

    private static function default_true_criteria() {
        return "The article's main subject substantially matches this tag definition: {description}";
    }

    private static function default_false_criteria() {
        return "The tag is absent, incidental, or only briefly mentioned: {description}";
    }

    function about() {
        return [1.0, "Assign tags synchronously using TypeSafe Jev", "powerivq"];
    }

    function api_version() {
        return 2;
    }

    function init($host) {
        $this->host = $host;

        if ($host->get_pdo()->getAttribute(PDO::ATTR_DRIVER_NAME) !== "pgsql") {
            user_error("Jev_Auto_Tag: Only PostgreSQL is supported", E_USER_ERROR);
        }

        $host->add_filter_action($this, "jev_auto_tag", __("Generate Jev Tags"));
        $host->add_hook($host::HOOK_PREFS_TAB, $this);
    }

    private function init_database() {
        $this->host->get_pdo()->exec(file_get_contents(__DIR__ . "/init_pgsql.sql"));
    }

    private function settings() {
        return [
            "api_key" => trim($this->host->get($this, "typesafe_api_key", "")),
            "base_url" => rtrim(trim($this->host->get($this, "typesafe_base_url", self::DEFAULT_BASE_URL)), "/"),
            "model" => trim($this->host->get($this, "jev_model", self::DEFAULT_MODEL)),
            "threshold" => max(0.0, min(1.0, (float)$this->host->get($this, "tag_threshold", self::DEFAULT_THRESHOLD))),
            "max_text_length" => max(500, min(100000, (int)$this->host->get($this, "max_text_length", self::DEFAULT_MAX_TEXT_LENGTH))),
            "state_template" => $this->host->get($this, "state_template", self::default_state_template()),
            "question_template" => $this->host->get($this, "question_template", self::default_question_template()),
            "true_criteria" => $this->host->get($this, "true_criteria", self::default_true_criteria()),
            "false_criteria" => $this->host->get($this, "false_criteria", self::default_false_criteria()),
            "tag_rules" => $this->host->get($this, "tag_rules", ""),
        ];
    }

    private static function parse_tag_rules($value) {
        $rules = [];
        $seen = [];

        foreach (preg_split('/\R/u', (string)$value) as $line) {
            $line = trim($line);
            if ($line === "" || str_starts_with($line, "#")) continue;

            $parts = array_map("trim", explode("|", $line, 2));
            $tag = $parts[0];
            $description = $parts[1] ?? $tag;
            $key = mb_strtolower($tag);

            if ($tag === "" || mb_strlen($tag) > 250 || isset($seen[$key])) continue;

            $rules[] = [
                "tag" => $tag,
                "description" => $description !== "" ? $description : $tag,
            ];
            $seen[$key] = true;
        }

        return $rules;
    }

    private static function render_template($template, $values) {
        $search = [];
        $replace = [];
        foreach ($values as $key => $value) {
            $search[] = "{" . $key . "}";
            $replace[] = $value;
        }
        return str_replace($search, $replace, $template);
    }

    private static function article_text($content, $max_length) {
        $content = str_replace(["<br>", "<br/>", "<br />", "</p>"], "\n", (string)$content);
        $content = html_entity_decode(strip_tags($content), ENT_QUOTES | ENT_HTML5, "UTF-8");
        return trim(mb_substr($content, 0, $max_length));
    }

    private function claim_attempt($guid, $owner_uid, $model, $config_hash) {
        $sth = $this->host->get_pdo()->prepare(
            "INSERT INTO ttrss_jev_tag_attempts (guid, owner_uid, model, config_hash) " .
            "VALUES (?, ?, ?, ?) ON CONFLICT (guid, owner_uid) DO NOTHING RETURNING guid"
        );
        $sth->execute([$guid, $owner_uid, $model, $config_hash]);
        return (bool)$sth->fetchColumn();
    }

    private function finish_attempt($guid, $owner_uid, $status, $selected_tags = [], $error = null) {
        $sth = $this->host->get_pdo()->prepare(
            "UPDATE ttrss_jev_tag_attempts SET completed_at = NOW(), status = ?, selected_tags = ?, error = ? " .
            "WHERE guid = ? AND owner_uid = ?"
        );
        $sth->execute([
            $status,
            json_encode(array_values($selected_tags), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            $error === null ? null : mb_substr($error, 0, 2000),
            $guid,
            $owner_uid,
        ]);
    }

    private static function response_error($decoded, $http_code) {
        foreach (["detail", "message", "error"] as $key) {
            if (!isset($decoded[$key])) continue;
            if (is_string($decoded[$key])) return $decoded[$key];
            $encoded = json_encode($decoded[$key], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($encoded !== false) return $encoded;
        }
        return "HTTP $http_code";
    }

    private static function call_api($settings, $state, $questions) {
        $url = str_ends_with($settings["base_url"], "/systemone")
            ? $settings["base_url"]
            : $settings["base_url"] . "/systemone";
        $payload = [
            "state" => $state,
            "model" => $settings["model"],
            "questions" => $questions,
        ];
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => [
                "Authorization: Bearer " . $settings["api_key"],
                "Content-Type: application/json",
            ],
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 180,
        ]);

        $response = curl_exec($ch);
        $curl_error = curl_error($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false) throw new RuntimeException("Connection error: $curl_error");

        $decoded = json_decode($response, true);
        if (!is_array($decoded)) throw new RuntimeException("API returned invalid JSON");
        if ($http_code !== 200) throw new RuntimeException(self::response_error($decoded, $http_code));
        if (!isset($decoded["answers"]) || !is_array($decoded["answers"])) {
            throw new RuntimeException("API response contained no answers");
        }

        return $decoded;
    }

    private static function merge_tags($existing_tags, $selected_tags) {
        $tags = [];
        foreach ([...(array)$existing_tags, ...$selected_tags] as $tag) {
            $tag = trim((string)$tag);
            if ($tag !== "") $tags[mb_strtolower($tag)] = $tag;
        }
        return array_values($tags);
    }

    function hook_article_filter_action($article, $action) {
        if ($action !== "jev_auto_tag") return $article;

        $guid = $article["guid_hashed"] ?? null;
        $owner_uid = $article["owner_uid"] ?? null;
        if (!$guid || !$owner_uid) return $article;

        try {
            $this->init_database();
            $settings = $this->settings();
            $rules = self::parse_tag_rules($settings["tag_rules"]);
            $content = self::article_text($article["content"] ?? "", $settings["max_text_length"]);

            if ($settings["api_key"] === "" || $settings["base_url"] === "" || $settings["model"] === "") {
                throw new RuntimeException("API configuration is incomplete");
            }
            if (!$rules) throw new RuntimeException("No tag rules are configured");
            if ($content === "") throw new RuntimeException("Article content is empty");

            $state = self::render_template($settings["state_template"], [
                "title" => (string)($article["title"] ?? ""),
                "content" => $content,
            ]);
            $questions = [];
            foreach ($rules as $index => $rule) {
                $values = ["tag" => $rule["tag"], "description" => $rule["description"]];
                $questions["tag_$index"] = [
                    "type" => "noul",
                    "instructions" => self::render_template($settings["question_template"], $values),
                    "criteria" => [
                        "true" => self::render_template($settings["true_criteria"], $values),
                        "false" => self::render_template($settings["false_criteria"], $values),
                    ],
                ];
            }

            $config_hash = hash("sha256", json_encode([
                $settings["model"],
                $settings["threshold"],
                $settings["state_template"],
                $settings["question_template"],
                $settings["true_criteria"],
                $settings["false_criteria"],
                $rules,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            if (!$this->claim_attempt($guid, $owner_uid, $settings["model"], $config_hash)) {
                error_log("Jev_Auto_Tag: Skipping previously attempted guid=$guid for user $owner_uid");
                return $article;
            }

            try {
                error_log("Jev_Auto_Tag: Calling {$settings['model']} for guid=$guid with " . count($questions) . " tag questions");
                $response = self::call_api($settings, $state, $questions);
                $selected_tags = [];

                foreach ($rules as $index => $rule) {
                    $answer = $response["answers"]["tag_$index"] ?? null;
                    if (!is_array($answer) || ($answer["type"] ?? null) !== "noul" || !is_numeric($answer["noul"] ?? null)) {
                        throw new RuntimeException("API response omitted a valid answer for tag '{$rule['tag']}'");
                    }
                    if ((float)$answer["noul"] >= $settings["threshold"]) {
                        $selected_tags[] = $rule["tag"];
                    }
                }

                $article["tags"] = self::merge_tags($article["tags"] ?? [], $selected_tags);
                $this->finish_attempt($guid, $owner_uid, "success", $selected_tags);
                error_log("Jev_Auto_Tag: Selected tags [" . implode(", ", $selected_tags) . "] for guid=$guid");
            } catch (Throwable $e) {
                $this->finish_attempt($guid, $owner_uid, "failed", [], $e->getMessage());
                error_log("Jev_Auto_Tag: Call failed for guid=$guid: " . $e->getMessage());
            }
        } catch (Throwable $e) {
            error_log("Jev_Auto_Tag: Unable to process guid=$guid: " . $e->getMessage());
        }

        return $article;
    }

    function hook_prefs_tab($args) {
        if ($args !== "prefFeeds") return;

        $this->init_database();
        $settings = $this->settings();
        $h = function($value) { return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8"); };

        print '<div dojoType="dijit.layout.AccordionPane" title="<i class=\'material-icons\'>label</i> ' . __("Jev Auto Tag Settings") . '">';
        print '<h2>' . __("TypeSafe API Configuration") . '</h2>';
        print '<p>' . __("Create a tt-rss filter with the Generate Jev Tags action. Each article is sent at most once, and all tag questions are evaluated in one synchronous request.") . '</p>';
        print '<form dojoType="dijit.form.Form">';
        print '<script type="dojo/method" event="onSubmit" args="evt">evt.preventDefault(); if (this.validate()) { xhr.post("backend.php", this.getValues(), (reply) => { Notify.info(reply); }); }</script>';
        print \Controls\pluginhandler_tags($this, "save");

        print '<div class="form-group"><input dojoType="dijit.form.ValidationTextBox" type="password" required="1" name="typesafe_api_key" style="width:30em" value="' . $h($settings["api_key"]) . '">&nbsp;<label>' . __("API Key") . '</label></div>';
        print '<div class="form-group"><input dojoType="dijit.form.ValidationTextBox" required="1" name="typesafe_base_url" style="width:30em" value="' . $h($settings["base_url"]) . '">&nbsp;<label>' . __("API Base URL") . '</label></div>';
        print '<div class="form-group"><input dojoType="dijit.form.ValidationTextBox" required="1" name="jev_model" style="width:20em" value="' . $h($settings["model"]) . '">&nbsp;<label>' . __("Model") . '</label></div>';
        print '<div class="form-group"><input dojoType="dijit.form.NumberSpinner" required="1" name="tag_threshold" style="width:7em" value="' . $h($settings["threshold"]) . '" min="0" max="1" smallDelta="0.05">&nbsp;<label>' . __("Tag Probability Threshold") . '</label></div>';
        print '<div class="form-group"><input dojoType="dijit.form.NumberSpinner" required="1" name="max_text_length" style="width:8em" value="' . (int)$settings["max_text_length"] . '" min="500" max="100000">&nbsp;<label>' . __("Max Article Length (Chars)") . '</label></div>';

        print '<h2>' . __("Tag Questions") . '</h2>';
        print '<div class="form-group"><label style="display:block">' . __("Tags and Definitions") . '</label>';
        print '<textarea dojoType="dijit.form.SimpleTextarea" name="tag_rules" style="width:90%;height:10em;font-family:monospace">' . $h($settings["tag_rules"]) . '</textarea>';
        print '<p class="text-muted">' . __("One tag per line. Optional format: tag | definition. Lines beginning with # are ignored.") . '</p></div>';

        print '<div class="form-group"><label style="display:block">' . __("State Template") . '</label>';
        print '<textarea dojoType="dijit.form.SimpleTextarea" name="state_template" style="width:90%;height:8em;font-family:monospace">' . $h($settings["state_template"]) . '</textarea>';
        print '<p class="text-muted">' . __("Available placeholders: {title}, {content}") . '</p></div>';

        print '<div class="form-group"><label style="display:block">' . __("Question Template") . '</label>';
        print '<textarea dojoType="dijit.form.SimpleTextarea" name="question_template" style="width:90%;height:6em;font-family:monospace">' . $h($settings["question_template"]) . '</textarea></div>';
        print '<div class="form-group"><label style="display:block">' . __("Positive Criteria Template") . '</label>';
        print '<textarea dojoType="dijit.form.SimpleTextarea" name="true_criteria" style="width:90%;height:6em;font-family:monospace">' . $h($settings["true_criteria"]) . '</textarea></div>';
        print '<div class="form-group"><label style="display:block">' . __("Negative Criteria Template") . '</label>';
        print '<textarea dojoType="dijit.form.SimpleTextarea" name="false_criteria" style="width:90%;height:6em;font-family:monospace">' . $h($settings["false_criteria"]) . '</textarea>';
        print '<p class="text-muted">' . __("Question and criteria placeholders: {tag}, {description}") . '</p></div>';

        print '<button dojoType="dijit.form.Button" type="submit" class="alt-primary">' . __("Save") . '</button>';
        print '</form></div>';
    }

    function save() {
        $this->init_database();

        $this->host->set($this, "typesafe_api_key", trim($_POST["typesafe_api_key"] ?? ""));
        $this->host->set($this, "typesafe_base_url", rtrim(trim($_POST["typesafe_base_url"] ?? self::DEFAULT_BASE_URL), "/"));
        $this->host->set($this, "jev_model", trim($_POST["jev_model"] ?? self::DEFAULT_MODEL));
        $this->host->set($this, "tag_threshold", max(0.0, min(1.0, (float)($_POST["tag_threshold"] ?? self::DEFAULT_THRESHOLD))));
        $this->host->set($this, "max_text_length", max(500, min(100000, (int)($_POST["max_text_length"] ?? self::DEFAULT_MAX_TEXT_LENGTH))));
        $this->host->set($this, "tag_rules", trim($_POST["tag_rules"] ?? ""));
        $this->host->set($this, "state_template", trim($_POST["state_template"] ?? "") ?: self::default_state_template());
        $this->host->set($this, "question_template", trim($_POST["question_template"] ?? "") ?: self::default_question_template());
        $this->host->set($this, "true_criteria", trim($_POST["true_criteria"] ?? "") ?: self::default_true_criteria());
        $this->host->set($this, "false_criteria", trim($_POST["false_criteria"] ?? "") ?: self::default_false_criteria());

        echo __("Settings saved.");
    }
}
?>
