<?php
class Jev_Auto_Tag extends Plugin {
    private const DEFAULT_BASE_URL = "https://api.typesafe.ai/v1";
    private const DEFAULT_MODEL = "jev-latest";
    private const DEFAULT_THRESHOLD = 0.7;
    private const DEFAULT_AUTO_READ_THRESHOLD = 0.8;
    private const DEFAULT_MAX_TEXT_LENGTH = 400;

    private $host;
    private bool $database_initialized = false;

    function about() {
        return [1.3, "Assign labels, rank articles, and mark them read using TypeSafe Jev", "powerivq"];
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

        $host->add_filter_action($this, "jev_auto_tag", __("Apply Jev Article Decisions"));
        $host->add_hook($host::HOOK_PREFS_TAB, $this);
    }

    private function init_database() {
        if ($this->database_initialized) return;
        $this->host->get_pdo()->exec(file_get_contents(__DIR__ . "/init_pgsql.sql"));
        $this->database_initialized = true;
    }

    private function settings() {
        return [
            "api_key" => trim($this->host->get($this, "typesafe_api_key", "")),
            "base_url" => rtrim(trim($this->host->get($this, "typesafe_base_url", self::DEFAULT_BASE_URL)), "/"),
            "model" => trim($this->host->get($this, "jev_model", self::DEFAULT_MODEL)),
            "threshold" => max(0.0, min(1.0, (float)$this->host->get($this, "label_threshold", self::DEFAULT_THRESHOLD))),
            "max_text_length" => max(100, min(5000, (int)$this->host->get($this, "max_text_length", self::DEFAULT_MAX_TEXT_LENGTH))),
            "label_rules" => $this->host->get($this, "label_rules", ""),
        ];
    }

    private static function parse_label_rules($value) {
        $rules = [];
        $seen = [];

        foreach (preg_split('/\R/u', (string)$value) as $line) {
            $line = trim($line);
            if ($line === "" || str_starts_with($line, "#")) continue;

            $parts = array_map("trim", explode("|", $line, 2));
            $label_id = filter_var($parts[0], FILTER_VALIDATE_INT, ["options" => ["min_range" => 1]]);
            $question = $parts[1] ?? "";

            if (!$label_id || $question === "" || isset($seen[$label_id])) continue;

            $rules[] = [
                "label_id" => (int)$label_id,
                "question" => $question,
            ];
            $seen[$label_id] = true;
        }

        return $rules;
    }

    private static function default_rank_levels() {
        return "Low priority; unlikely to be worth reading\n" .
            "Normal priority; potentially worth reading\n" .
            "High priority; especially relevant or important";
    }

    private static function parse_rank_levels($value) {
        return array_values(array_filter(
            array_map("trim", preg_split('/\R/u', (string)$value)),
            fn($level) => $level !== ""
        ));
    }

    private function feed_settings($feed_id, $owner_uid) {
        $defaults = [
            "rank_enabled" => false,
            "rank_question" => "",
            "rank_levels" => self::default_rank_levels(),
            "auto_read_enabled" => false,
            "auto_read_question" => "",
            "auto_read_threshold" => self::DEFAULT_AUTO_READ_THRESHOLD,
        ];
        if (!$feed_id) return $defaults;

        $sth = $this->host->get_pdo()->prepare(
            "SELECT rank_enabled, rank_question, rank_levels, auto_read_enabled, " .
            "auto_read_question, auto_read_threshold FROM ttrss_jev_feed_settings " .
            "WHERE feed_id = ? AND owner_uid = ?"
        );
        $sth->execute([$feed_id, $owner_uid]);
        $row = $sth->fetch(PDO::FETCH_ASSOC);
        if (!$row) return $defaults;

        return [
            "rank_enabled" => filter_var($row["rank_enabled"], FILTER_VALIDATE_BOOLEAN),
            "rank_question" => trim((string)$row["rank_question"]),
            "rank_levels" => trim((string)$row["rank_levels"]),
            "auto_read_enabled" => filter_var($row["auto_read_enabled"], FILTER_VALIDATE_BOOLEAN),
            "auto_read_question" => trim((string)$row["auto_read_question"]),
            "auto_read_threshold" => max(0.0, min(1.0, (float)$row["auto_read_threshold"])),
        ];
    }

    private static function score_to_modifier($score, $level_count) {
        if ($level_count < 2) throw new InvalidArgumentException("A score rubric needs at least two levels");
        $normalized = max(0.0, min(1.0, (float)$score / ($level_count - 1)));
        return (int)round($normalized * 1000);
    }

    private static function article_text($content, $max_length) {
        $content = str_replace(["<br>", "<br/>", "<br />", "</p>"], "\n", (string)$content);
        $content = html_entity_decode(strip_tags($content), ENT_QUOTES | ENT_HTML5, "UTF-8");
        return trim(mb_substr($content, 0, $max_length));
    }

    private function claim_attempt($guid, $owner_uid, $model, $config_hash) {
        $sth = $this->host->get_pdo()->prepare(
            "INSERT INTO ttrss_jev_label_attempts (guid, owner_uid, model, config_hash) " .
            "VALUES (?, ?, ?, ?) ON CONFLICT (guid, owner_uid) DO NOTHING RETURNING guid"
        );
        $sth->execute([$guid, $owner_uid, $model, $config_hash]);
        return (bool)$sth->fetchColumn();
    }

    private function finish_attempt($guid, $owner_uid, $status, $selected_labels = [], $error = null) {
        $sth = $this->host->get_pdo()->prepare(
            "UPDATE ttrss_jev_label_attempts SET completed_at = NOW(), status = ?, selected_labels = ?, error = ? " .
            "WHERE guid = ? AND owner_uid = ?"
        );
        $sth->execute([
            $status,
            json_encode(array_values($selected_labels), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
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

    private static function merge_labels($existing_labels, $selected_label_ids, $label_definitions) {
        $labels = array_values((array)$existing_labels);
        $seen = [];
        foreach ($labels as $label) {
            if (is_array($label) && isset($label[1])) $seen[mb_strtolower((string)$label[1])] = true;
        }

        foreach ($selected_label_ids as $label_id) {
            $definition = $label_definitions[$label_id] ?? null;
            if (!$definition) throw new RuntimeException("Configured label ID $label_id no longer exists");

            $key = mb_strtolower($definition["caption"]);
            if (isset($seen[$key])) continue;

            $labels[] = [
                Labels::label_to_feed_id($label_id),
                $definition["caption"],
                $definition["fg_color"],
                $definition["bg_color"],
            ];
            $seen[$key] = true;
        }
        return $labels;
    }

    function hook_article_filter_action($article, $action) {
        if ($action !== "jev_auto_tag") return $article;

        $guid = $article["guid_hashed"] ?? null;
        $owner_uid = $article["owner_uid"] ?? null;
        if (!$guid || !$owner_uid) return $article;

        try {
            $this->init_database();
            $settings = $this->settings();
            $rules = self::parse_label_rules($settings["label_rules"]);
            $label_definitions = Labels::get_as_hash((int)$owner_uid);
            $feed_id = (int)($article["feed"]["id"] ?? 0);
            $feed_settings = $this->feed_settings($feed_id, (int)$owner_uid);
            $rank_levels = self::parse_rank_levels($feed_settings["rank_levels"]);
            $content = self::article_text($article["content"] ?? "", $settings["max_text_length"]);

            if ($settings["api_key"] === "" || $settings["base_url"] === "" || $settings["model"] === "") {
                throw new RuntimeException("API configuration is incomplete");
            }
            if (!$rules) throw new RuntimeException("No label rules are configured");
            foreach ($rules as $rule) {
                if (!isset($label_definitions[$rule["label_id"]])) {
                    throw new RuntimeException("Configured label ID " . $rule["label_id"] . " no longer exists");
                }
            }
            if ($feed_settings["rank_enabled"] &&
                    ($feed_settings["rank_question"] === "" || count($rank_levels) < 2 || count($rank_levels) > 10)) {
                throw new RuntimeException("Feed $feed_id has an invalid ranking rule");
            }
            if ($feed_settings["auto_read_enabled"] && $feed_settings["auto_read_question"] === "") {
                throw new RuntimeException("Feed $feed_id has an invalid auto-read rule");
            }
            if ($content === "") throw new RuntimeException("Article content is empty");

            $state = [
                "title" => (string)($article["title"] ?? ""),
                "content" => $content,
            ];
            $questions = [];
            foreach ($rules as $index => $rule) {
                $questions["label_$index"] = [
                    "type" => "noul",
                    "instructions" => $rule["question"],
                ];
            }
            if ($feed_settings["rank_enabled"]) {
                $questions["article_rank"] = [
                    "type" => "score",
                    "instructions" => $feed_settings["rank_question"],
                    "criteria" => $rank_levels,
                ];
            }
            if ($feed_settings["auto_read_enabled"]) {
                $questions["auto_read"] = [
                    "type" => "noul",
                    "instructions" => $feed_settings["auto_read_question"],
                ];
            }

            $config_hash = hash("sha256", json_encode([
                $settings["model"],
                $settings["threshold"],
                $rules,
                $feed_settings,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            if (!$this->claim_attempt($guid, $owner_uid, $settings["model"], $config_hash)) {
                error_log("Jev_Auto_Tag: Skipping previously attempted guid=$guid for user $owner_uid");
                return $article;
            }

            try {
                error_log("Jev_Auto_Tag: Calling {$settings['model']} for guid=$guid with " . count($questions) . " questions");
                $response = self::call_api($settings, $state, $questions);
                $selected_label_ids = [];

                foreach ($rules as $index => $rule) {
                    $answer = $response["answers"]["label_$index"] ?? null;
                    if (!is_array($answer) || ($answer["type"] ?? null) !== "noul" || !is_numeric($answer["noul"] ?? null)) {
                        $caption = $label_definitions[$rule["label_id"]]["caption"];
                        throw new RuntimeException("API response omitted a valid answer for label '$caption'");
                    }
                    if ((float)$answer["noul"] >= $settings["threshold"]) {
                        $selected_label_ids[] = $rule["label_id"];
                    }
                }

                $rank_score = null;
                if ($feed_settings["rank_enabled"]) {
                    $answer = $response["answers"]["article_rank"] ?? null;
                    if (!is_array($answer) || ($answer["type"] ?? null) !== "score" || !is_numeric($answer["score"] ?? null)) {
                        throw new RuntimeException("API response omitted a valid article ranking score");
                    }
                    $rank_score = self::score_to_modifier($answer["score"], count($rank_levels));
                }

                $auto_read_probability = null;
                if ($feed_settings["auto_read_enabled"]) {
                    $answer = $response["answers"]["auto_read"] ?? null;
                    if (!is_array($answer) || ($answer["type"] ?? null) !== "noul" || !is_numeric($answer["noul"] ?? null)) {
                        throw new RuntimeException("API response omitted a valid auto-read answer");
                    }
                    $auto_read_probability = (float)$answer["noul"];
                }

                $selected_labels = array_map(
                    fn($label_id) => $label_definitions[$label_id]["caption"],
                    $selected_label_ids
                );
                $article["labels"] = self::merge_labels(
                    $article["labels"] ?? [],
                    $selected_label_ids,
                    $label_definitions
                );
                if ($rank_score !== null) {
                    $article["score_modifier"] = (int)($article["score_modifier"] ?? 0) + $rank_score;
                }
                if ($auto_read_probability !== null &&
                        $auto_read_probability >= $feed_settings["auto_read_threshold"]) {
                    $article["force_catchup"] = true;
                }

                $this->finish_attempt($guid, $owner_uid, "success", $selected_labels);
                $rank_log = $rank_score === null ? "off" : (string)$rank_score;
                $read_log = $auto_read_probability === null
                    ? "off"
                    : sprintf("%.3f%s", $auto_read_probability, ($article["force_catchup"] ?? false) ? " (read)" : "");
                error_log(
                    "Jev_Auto_Tag: guid=$guid labels=[" . implode(", ", $selected_labels) .
                    "] score=$rank_log auto_read=$read_log"
                );
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
        $owner_uid = (int)$_SESSION["uid"];
        $sth = $this->host->get_pdo()->prepare(
            "SELECT f.id, f.title, s.rank_enabled, s.rank_question, s.rank_levels, " .
            "s.auto_read_enabled, s.auto_read_question, s.auto_read_threshold " .
            "FROM ttrss_feeds f LEFT JOIN ttrss_jev_feed_settings s " .
            "ON s.feed_id = f.id AND s.owner_uid = f.owner_uid " .
            "WHERE f.owner_uid = ? ORDER BY LOWER(f.title)"
        );
        $sth->execute([$owner_uid]);
        $feeds = $sth->fetchAll(PDO::FETCH_ASSOC);
        foreach ($feeds as &$feed) {
            $feed["rank_enabled"] = filter_var($feed["rank_enabled"] ?? false, FILTER_VALIDATE_BOOLEAN);
            $feed["rank_question"] = trim((string)($feed["rank_question"] ?? ""));
            $feed["rank_levels"] = trim((string)($feed["rank_levels"] ?? "")) ?: self::default_rank_levels();
            $feed["auto_read_enabled"] = filter_var($feed["auto_read_enabled"] ?? false, FILTER_VALIDATE_BOOLEAN);
            $feed["auto_read_question"] = trim((string)($feed["auto_read_question"] ?? ""));
            $feed["auto_read_threshold"] = $feed["auto_read_threshold"] === null
                ? self::DEFAULT_AUTO_READ_THRESHOLD
                : max(0.0, min(1.0, (float)$feed["auto_read_threshold"]));
        }
        unset($feed);

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

        print '<div dojoType="dijit.layout.AccordionPane" title="<i class=\'material-icons\'>tune</i> ' . __("Jev Article Automation") . '">';
        print '<p>' . __("Create a filter with the Apply Jev Article Decisions action. Matching articles are sent at most once. Label, ranking, and auto-read decisions are evaluated together in one synchronous request.") . '</p>';
        print '<form id="jev-auto-tag-form" dojoType="dijit.form.Form">';
        print '<script type="dojo/method" event="onSubmit" args="evt">evt.preventDefault(); var labelRules = JevLabelRules.serialize(); if (this.validate() && labelRules !== false) { var values = this.getValues(); values.label_rules = labelRules; xhr.post("backend.php", values, (reply) => { Notify.info(reply); }); }</script>';
        print \Controls\pluginhandler_tags($this, "save");

        print '<fieldset><legend>' . __("TypeSafe Connection") . '</legend>';
        print '<div class="form-group"><label for="jev-typesafe-api-key" style="display:block">' . __("API Key") . '</label><input id="jev-typesafe-api-key" dojoType="dijit.form.ValidationTextBox" type="password" required="1" name="typesafe_api_key" style="width:30em" value="' . $h($settings["api_key"]) . '"></div>';
        print '<div class="form-group"><label for="jev-typesafe-base-url" style="display:block">' . __("API Base URL") . '</label><input id="jev-typesafe-base-url" dojoType="dijit.form.ValidationTextBox" required="1" name="typesafe_base_url" style="width:30em" value="' . $h($settings["base_url"]) . '"></div>';
        print '<div class="form-group"><label for="jev-model" style="display:block">' . __("Model") . '</label><input id="jev-model" dojoType="dijit.form.ValidationTextBox" required="1" name="jev_model" style="width:20em" value="' . $h($settings["model"]) . '"></div>';
        print '<button dojoType="dijit.form.Button" type="button" onClick="' . $h($test_script) . '"><i class="material-icons">key</i> ' . __("Test API Key") . '</button>';
        print '</fieldset>';

        print '<fieldset><legend>' . __("Label Decisions") . '</legend>';
        print '<div class="form-group"><label for="jev-label-threshold" style="display:block">' . __("Label Probability Threshold") . '</label><input id="jev-label-threshold" dojoType="dijit.form.NumberSpinner" required="1" name="label_threshold" style="width:7em" value="' . $h($settings["threshold"]) . '" min="0" max="1" smallDelta="0.05"></div>';
        print '<p class="text-muted">' . __("A label is applied when its Noul yes-probability meets this threshold. Deleted labels must be replaced before settings can be saved.") . '</p>';
        print '<div class="form-group"><h3>' . __("Label Rules") . '</h3>';
        print '<p>' . __("Choose an existing tt-rss label, then enter the focused yes/no question Jev should answer. Create and color labels under Preferences > Labels.") . '</p>';
        $available_labels = Labels::get_all((int)$_SESSION["uid"]);
        print '<select id="jev-available-labels" hidden aria-hidden="true">';
        foreach ($available_labels as $label) {
            print '<option value="' . (int)$label["id"] . '">' . $h($label["caption"]) . '</option>';
        }
        print '</select>';
        print '<div id="jev-label-rule-list">';
        $rules = self::parse_label_rules($settings["label_rules"]);
        if (!$rules) $rules = [["label_id" => 0, "question" => ""]];
        foreach ($rules as $index => $rule) {
            $selected_found = false;
            print '<div class="jev-label-rule">';
            print '<label for="jev-label-name-' . $index . '"><span>' . __("Label") . '</span><select id="jev-label-name-' . $index . '" class="jev-label-rule-name">';
            print '<option value="">' . __("Choose a label...") . '</option>';
            foreach ($available_labels as $label) {
                $selected = (int)$label["id"] === (int)$rule["label_id"];
                if ($selected) $selected_found = true;
                print '<option value="' . (int)$label["id"] . '"' . ($selected ? ' selected' : '') . '>' . $h($label["caption"]) . '</option>';
            }
            if ($rule["label_id"] && !$selected_found) {
                print '<option value="" selected>' . $h("Deleted label #" . $rule["label_id"] . "; choose a replacement") . '</option>';
            }
            if (!$available_labels && !$rule["label_id"]) {
                print '<option value="" disabled>' . __("No labels available") . '</option>';
            }
            print '</select></label>';
            print '<label for="jev-label-question-' . $index . '"><span>' . __("Yes/no question") . '</span><input id="jev-label-question-' . $index . '" type="text" class="jev-label-rule-question" placeholder="Is this article primarily about technology?" value="' . $h($rule["question"]) . '"></label>';
            print '<button type="button" class="jev-label-rule-remove" title="' . __("Remove label rule") . '" aria-label="' . __("Remove label rule") . '" onclick="JevLabelRules.remove(this.parentNode)"><i class="material-icons">close</i></button>';
            print '</div>';
        }
        print '</div>';
        print '<button type="button" class="alt-primary" onclick="JevLabelRules.add()"><i class="material-icons">add</i> ' . __("Add Label Rule") . '</button></div>';
        print '</fieldset>';

        print '<fieldset><legend>' . __("Per-feed Decisions") . '</legend>';
        print '<p>' . __("Ranking and auto-read are optional and independent for each feed. They run only when that article matches a filter using the Apply Jev Article Decisions action.") . '</p>';
        print '<div class="jev-feed-list">';
        if (!$feeds) print '<p class="text-muted">' . __("No feeds are available.") . '</p>';
        foreach ($feeds as $feed) {
            $id = (int)$feed["id"];
            $rank_checked = $feed["rank_enabled"] ? " checked" : "";
            $read_checked = $feed["auto_read_enabled"] ? " checked" : "";
            $rank_hidden = $feed["rank_enabled"] ? "" : " hidden";
            $read_hidden = $feed["auto_read_enabled"] ? "" : " hidden";
            print '<details class="jev-feed-rule">';
            print '<summary><i class="material-icons">rss_feed</i><strong>' . $h($feed["title"]) . '</strong></summary>';
            print '<div class="jev-feed-rule-body">';
            print '<input dojoType="dijit.form.TextBox" type="hidden" name="feed_ids[]" value="' . $id . '">';

            print '<section class="jev-feed-decision">';
            print '<h4>' . __("Article Ranking") . '</h4>';
            print '<label><input dojoType="dijit.form.CheckBox" type="checkbox" name="feed_rank_enabled_' . $id . '" value="1" onChange="JevFeedDecisions.toggle(\'jev-rank-options-' . $id . '\', this.checked)"' . $rank_checked . '> ' . __("Set a tt-rss score for this feed") . '</label>';
            print '<div id="jev-rank-options-' . $id . '" class="jev-feed-options"' . $rank_hidden . '>';
            print '<div class="form-group"><label for="jev-rank-question-' . $id . '"><span>' . __("Ranking question") . '</span><textarea id="jev-rank-question-' . $id . '" dojoType="dijit.form.SimpleTextarea" name="feed_rank_question_' . $id . '" placeholder="How valuable is this article to me?">' . $h($feed["rank_question"]) . '</textarea></label></div>';
            print '<div class="form-group"><label for="jev-rank-levels-' . $id . '"><span>' . __("Ordered levels (lowest to highest, one per line)") . '</span><textarea id="jev-rank-levels-' . $id . '" dojoType="dijit.form.SimpleTextarea" name="feed_rank_levels_' . $id . '" class="jev-rank-levels">' . $h($feed["rank_levels"]) . '</textarea></label></div>';
            print '<p class="text-muted">' . __("Jev scores the ordered rubric; the result becomes a native tt-rss score from 0 to 1000.") . '</p>';
            print '</div>';
            print '</section>';

            print '<section class="jev-feed-decision">';
            print '<h4>' . __("Automatic Read State") . '</h4>';
            print '<label><input dojoType="dijit.form.CheckBox" type="checkbox" name="feed_auto_read_enabled_' . $id . '" value="1" onChange="JevFeedDecisions.toggle(\'jev-read-options-' . $id . '\', this.checked)"' . $read_checked . '> ' . __("Mark matching articles as read automatically") . '</label>';
            print '<div id="jev-read-options-' . $id . '" class="jev-feed-options"' . $read_hidden . '>';
            print '<div class="form-group"><label for="jev-read-question-' . $id . '"><span>' . __("Yes/no Noul question") . '</span><textarea id="jev-read-question-' . $id . '" dojoType="dijit.form.SimpleTextarea" name="feed_auto_read_question_' . $id . '" placeholder="Should I skip this article without reading it?">' . $h($feed["auto_read_question"]) . '</textarea></label></div>';
            print '<div class="form-group"><label for="jev-read-threshold-' . $id . '"><span>' . __("Mark-read probability threshold") . '</span><input id="jev-read-threshold-' . $id . '" dojoType="dijit.form.NumberSpinner" name="feed_auto_read_threshold_' . $id . '" value="' . $h($feed["auto_read_threshold"]) . '" min="0" max="1" smallDelta="0.05" style="width:7em"></label></div>';
            print '<p class="text-muted">' . __("tt-rss marks the article read when the Noul yes-probability meets this threshold.") . '</p>';
            print '</div>';
            print '</section>';

            print '</div></details>';
        }
        print '</div></fieldset>';

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

        $label_rules = trim($_POST["label_rules"] ?? "");
        $active_lines = array_values(array_filter(
            preg_split('/\R/u', $label_rules),
            function($line) {
                $line = trim($line);
                return $line !== "" && !str_starts_with($line, "#");
            }
        ));
        $parsed_rules = self::parse_label_rules($label_rules);
        $available_labels = Labels::get_as_hash((int)$_SESSION["uid"]);
        $labels_are_valid = array_all(
            $parsed_rules,
            fn($rule) => isset($available_labels[$rule["label_id"]])
        );
        if (!$active_lines || count($parsed_rules) !== count($active_lines) || !$labels_are_valid) {
            echo __("Settings not saved. Choose one existing, unique label and enter a yes/no question for every active line.");
            return;
        }

        $pdo = $this->host->get_pdo();
        $owner_uid = (int)$_SESSION["uid"];
        $feed_ids = array_values(array_unique(array_filter(
            array_map("intval", (array)($_POST["feed_ids"] ?? [])),
            fn($feed_id) => $feed_id > 0
        )));
        $owns_feed = $pdo->prepare("SELECT 1 FROM ttrss_feeds WHERE id = ? AND owner_uid = ?");
        $feed_configs = [];

        foreach ($feed_ids as $feed_id) {
            $owns_feed->execute([$feed_id, $owner_uid]);
            if (!$owns_feed->fetchColumn()) continue;

            $rank_enabled = isset($_POST["feed_rank_enabled_$feed_id"]);
            $rank_question = trim($_POST["feed_rank_question_$feed_id"] ?? "");
            $rank_levels_text = trim($_POST["feed_rank_levels_$feed_id"] ?? "");
            $rank_levels = self::parse_rank_levels($rank_levels_text);
            if ($rank_enabled && ($rank_question === "" || count($rank_levels) < 2 || count($rank_levels) > 10)) {
                echo __("Settings not saved. Each enabled ranking rule needs a question and 2 to 10 ordered levels.");
                return;
            }

            $auto_read_enabled = isset($_POST["feed_auto_read_enabled_$feed_id"]);
            $auto_read_question = trim($_POST["feed_auto_read_question_$feed_id"] ?? "");
            $threshold_value = $_POST["feed_auto_read_threshold_$feed_id"] ?? self::DEFAULT_AUTO_READ_THRESHOLD;
            if ($auto_read_enabled && ($auto_read_question === "" || !is_numeric($threshold_value) ||
                    (float)$threshold_value < 0 || (float)$threshold_value > 1)) {
                echo __("Settings not saved. Each enabled auto-read rule needs a yes/no question and a threshold from 0 to 1.");
                return;
            }
            $auto_read_threshold = max(0.0, min(1.0, (float)$threshold_value));

            $feed_configs[] = [
                $owner_uid,
                $feed_id,
                $rank_enabled ? "true" : "false",
                $rank_question,
                $rank_levels_text,
                $auto_read_enabled ? "true" : "false",
                $auto_read_question,
                $auto_read_threshold,
            ];
        }

        $this->host->set($this, "typesafe_api_key", trim($_POST["typesafe_api_key"] ?? ""));
        $this->host->set($this, "typesafe_base_url", rtrim(trim($_POST["typesafe_base_url"] ?? self::DEFAULT_BASE_URL), "/"));
        $this->host->set($this, "jev_model", trim($_POST["jev_model"] ?? self::DEFAULT_MODEL));
        $this->host->set($this, "label_threshold", max(0.0, min(1.0, (float)($_POST["label_threshold"] ?? self::DEFAULT_THRESHOLD))));
        $this->host->set($this, "max_text_length", max(100, min(5000, (int)($_POST["max_text_length"] ?? self::DEFAULT_MAX_TEXT_LENGTH))));
        $this->host->set($this, "label_rules", $label_rules);

        $upsert = $pdo->prepare(
            "INSERT INTO ttrss_jev_feed_settings " .
            "(owner_uid, feed_id, rank_enabled, rank_question, rank_levels, " .
            "auto_read_enabled, auto_read_question, auto_read_threshold) " .
            "VALUES (?, ?, ?, ?, ?, ?, ?, ?) ON CONFLICT (owner_uid, feed_id) DO UPDATE SET " .
            "rank_enabled = EXCLUDED.rank_enabled, rank_question = EXCLUDED.rank_question, " .
            "rank_levels = EXCLUDED.rank_levels, auto_read_enabled = EXCLUDED.auto_read_enabled, " .
            "auto_read_question = EXCLUDED.auto_read_question, " .
            "auto_read_threshold = EXCLUDED.auto_read_threshold"
        );
        $pdo->beginTransaction();
        try {
            foreach ($feed_configs as $feed_config) $upsert->execute($feed_config);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        echo __("Settings saved.");
    }
}
?>
