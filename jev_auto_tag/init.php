<?php
class Jev_Auto_Tag extends Plugin {
    private const DEFAULT_BASE_URL = "https://api.typesafe.ai/v1";
    private const DEFAULT_MODEL = "jev-latest";
    private const DEFAULT_THRESHOLD = 0.7;
    private const DEFAULT_MAX_TEXT_LENGTH = 400;

    private $host;

    function about() {
        return [1.1, "Assign tags synchronously using TypeSafe Jev", "powerivq"];
    }

    function api_version() {
        return 2;
    }

    function get_prefs_js() {
        return file_get_contents(__DIR__ . "/prefs.js");
    }

    function get_prefs_css() {
        return file_get_contents(__DIR__ . "/prefs.css");
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
            "max_text_length" => max(100, min(5000, (int)$this->host->get($this, "max_text_length", self::DEFAULT_MAX_TEXT_LENGTH))),
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
            $question = $parts[1] ?? "";
            $key = mb_strtolower($tag);

            if ($tag === "" || $question === "" || mb_strlen($tag) > 250 || isset($seen[$key])) continue;

            $rules[] = [
                "tag" => $tag,
                "question" => $question,
            ];
            $seen[$key] = true;
        }

        return $rules;
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

            $state = [
                "title" => (string)($article["title"] ?? ""),
                "content" => $content,
            ];
            $questions = [];
            foreach ($rules as $index => $rule) {
                $questions["tag_$index"] = [
                    "type" => "noul",
                    "instructions" => $rule["question"],
                ];
            }

            $config_hash = hash("sha256", json_encode([
                $settings["model"],
                $settings["threshold"],
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
        $test_script = <<<'JS'
var form = dijit.byId('jev-auto-tag-form');
if (!form.validate()) return;
var values = form.getValues();
values.method = 'test_api';
Notify.progress('Testing TypeSafe API key...', true);
xhr.post('backend.php', values, function(reply) {
    try {
        var result = JSON.parse(reply);
        if (result.ok) {
            Notify.info(result.message);
        } else {
            Notify.error(result.message);
        }
    } catch (e) {
        Notify.error('TypeSafe API returned an invalid test response.');
    }
}, function() {
    Notify.error('TypeSafe API test request failed.');
});
JS;

        print '<div dojoType="dijit.layout.AccordionPane" title="<i class=\'material-icons\'>label</i> ' . __("Jev Auto Tag Settings") . '">';
        print '<p>' . __("Create a filter with the Generate Jev Tags action. Each article is sent at most once; every configured tag is evaluated as an independent yes/no Noul question in one synchronous request.") . '</p>';
        print '<form id="jev-auto-tag-form" dojoType="dijit.form.Form">';
        print '<script type="dojo/method" event="onSubmit" args="evt">evt.preventDefault(); var tagRules = JevTagRules.serialize(); if (this.validate() && tagRules !== false) { var values = this.getValues(); values.tag_rules = tagRules; xhr.post("backend.php", values, (reply) => { Notify.info(reply); }); }</script>';
        print \Controls\pluginhandler_tags($this, "save");

        print '<fieldset><legend>' . __("TypeSafe Connection") . '</legend>';
        print '<div class="form-group"><label for="jev-typesafe-api-key" style="display:block">' . __("API Key") . '</label><input id="jev-typesafe-api-key" dojoType="dijit.form.ValidationTextBox" type="password" required="1" name="typesafe_api_key" style="width:30em" value="' . $h($settings["api_key"]) . '"></div>';
        print '<div class="form-group"><label for="jev-typesafe-base-url" style="display:block">' . __("API Base URL") . '</label><input id="jev-typesafe-base-url" dojoType="dijit.form.ValidationTextBox" required="1" name="typesafe_base_url" style="width:30em" value="' . $h($settings["base_url"]) . '"></div>';
        print '<div class="form-group"><label for="jev-model" style="display:block">' . __("Model") . '</label><input id="jev-model" dojoType="dijit.form.ValidationTextBox" required="1" name="jev_model" style="width:20em" value="' . $h($settings["model"]) . '"></div>';
        print '<button dojoType="dijit.form.Button" type="button" onClick="' . $h($test_script) . '"><i class="material-icons">key</i> ' . __("Test API Key") . '</button>';
        print '</fieldset>';

        print '<fieldset><legend>' . __("Tag Decisions") . '</legend>';
        print '<div class="form-group"><label for="jev-tag-threshold" style="display:block">' . __("Tag Probability Threshold") . '</label><input id="jev-tag-threshold" dojoType="dijit.form.NumberSpinner" required="1" name="tag_threshold" style="width:7em" value="' . $h($settings["threshold"]) . '" min="0" max="1" smallDelta="0.05"></div>';
        print '<p class="text-muted">' . __("A tag is added when its Noul yes-probability meets this threshold.") . '</p>';
        print '<div class="form-group"><h3>' . __("Tag Rules") . '</h3>';
        print '<p>' . __("For each rule, enter the exact tag tt-rss should apply and the focused yes/no question Jev should answer.") . '</p>';
        print '<div id="jev-tag-rule-list">';
        $rules = self::parse_tag_rules($settings["tag_rules"]);
        if (!$rules) $rules = [["tag" => "", "question" => ""]];
        foreach ($rules as $index => $rule) {
            print '<div class="jev-tag-rule">';
            print '<label for="jev-tag-name-' . $index . '"><span>' . __("Tag name") . '</span><input id="jev-tag-name-' . $index . '" type="text" class="jev-tag-rule-name" placeholder="technology" value="' . $h($rule["tag"]) . '"></label>';
            print '<label for="jev-tag-question-' . $index . '"><span>' . __("Yes/no question") . '</span><input id="jev-tag-question-' . $index . '" type="text" class="jev-tag-rule-question" placeholder="Is this article primarily about technology?" value="' . $h($rule["question"]) . '"></label>';
            print '<button type="button" class="jev-tag-rule-remove" title="' . __("Remove tag rule") . '" aria-label="' . __("Remove tag rule") . '" onclick="JevTagRules.remove(this.parentNode)"><i class="material-icons">close</i></button>';
            print '</div>';
        }
        print '</div>';
        print '<button type="button" class="alt-primary" onclick="JevTagRules.add()"><i class="material-icons">add</i> ' . __("Add Tag Rule") . '</button></div>';
        print '</fieldset>';

        print '<fieldset><legend>' . __("Article Input") . '</legend>';
        print '<div class="form-group"><label for="jev-max-text-length" style="display:block">' . __("Article Text Limit (Chars)") . '</label><input id="jev-max-text-length" dojoType="dijit.form.NumberSpinner" required="1" name="max_text_length" style="width:8em" value="' . (int)$settings["max_text_length"] . '" min="100" max="5000"></div>';
        print '<p class="text-muted">' . __("HTML is removed before the first N characters are selected. The title and plain-text content are sent as separate structured fields. The default is 400 characters.") . '</p>';
        print '</fieldset>';

        print '<button dojoType="dijit.form.Button" type="submit" class="alt-primary">' . __("Save") . '</button>';
        print '</form></div>';
    }

    function test_api() {
        try {
            $settings = [
                "api_key" => trim($_POST["typesafe_api_key"] ?? ""),
                "base_url" => rtrim(trim($_POST["typesafe_base_url"] ?? self::DEFAULT_BASE_URL), "/"),
                "model" => trim($_POST["jev_model"] ?? self::DEFAULT_MODEL),
            ];
            if ($settings["api_key"] === "" || $settings["base_url"] === "" || $settings["model"] === "") {
                throw new RuntimeException("API key, base URL, and model are required.");
            }

            $response = self::call_api($settings, "This is a TypeSafe API connectivity test.", [
                "connectivity_test" => [
                    "type" => "noul",
                    "instructions" => "Is this state an API connectivity test?",
                ],
            ]);
            $answer = $response["answers"]["connectivity_test"] ?? null;
            if (!is_array($answer) || ($answer["type"] ?? null) !== "noul" || !is_numeric($answer["noul"] ?? null)) {
                throw new RuntimeException("API returned an invalid Noul answer.");
            }

            echo json_encode([
                "ok" => true,
                "message" => "TypeSafe API key works. Model: " . ($response["model"] ?? $settings["model"]),
            ], JSON_UNESCAPED_SLASHES);
        } catch (Throwable $e) {
            echo json_encode([
                "ok" => false,
                "message" => "TypeSafe API test failed: " . $e->getMessage(),
            ], JSON_UNESCAPED_SLASHES);
        }
    }

    function save() {
        $this->init_database();

        $tag_rules = trim($_POST["tag_rules"] ?? "");
        $active_lines = array_values(array_filter(
            preg_split('/\R/u', $tag_rules),
            function($line) {
                $line = trim($line);
                return $line !== "" && !str_starts_with($line, "#");
            }
        ));
        if (!$active_lines || count(self::parse_tag_rules($tag_rules)) !== count($active_lines)) {
            echo __("Settings not saved. Add at least one unique tag and a yes/no question for every active line.");
            return;
        }

        $this->host->set($this, "typesafe_api_key", trim($_POST["typesafe_api_key"] ?? ""));
        $this->host->set($this, "typesafe_base_url", rtrim(trim($_POST["typesafe_base_url"] ?? self::DEFAULT_BASE_URL), "/"));
        $this->host->set($this, "jev_model", trim($_POST["jev_model"] ?? self::DEFAULT_MODEL));
        $this->host->set($this, "tag_threshold", max(0.0, min(1.0, (float)($_POST["tag_threshold"] ?? self::DEFAULT_THRESHOLD))));
        $this->host->set($this, "max_text_length", max(100, min(5000, (int)($_POST["max_text_length"] ?? self::DEFAULT_MAX_TEXT_LENGTH))));
        $this->host->set($this, "tag_rules", $tag_rules);

        echo __("Settings saved.");
    }
}
?>
