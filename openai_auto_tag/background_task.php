<?php
function auto_tag_db() {
    $host = getenv("TTRSS_DB_HOST") ?: "postgres";
    $port = getenv("TTRSS_DB_PORT") ?: "5432";
    $name = getenv("TTRSS_DB_NAME") ?: "ttrss";
    $user = getenv("TTRSS_DB_USER") ?: "ttrss";
    $pass = getenv("TTRSS_DB_PASS") ?: "";

    return new PDO("pgsql:host=$host;port=$port;dbname=$name", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
}

function auto_label_default_prompt() {
    return "Choose every relevant label for the article from the permissible label list. " .
        "Return only a JSON array of label names, for example [\"technology\", \"business\"]. " .
        "Do not invent labels and do not include explanations. An empty array is allowed.\n\n" .
        "Permissible labels:\n{permissible_labels}\n\nTitle:\n{title}\n\nArticle:\n{content}";
}

function auto_tag_legacy_default_prompt() {
    return "Choose every relevant tag for the article from the permissible tag list. " .
        "Return only a JSON array of tag names, for example [\"technology\", \"business\"]. " .
        "Do not invent tags and do not include explanations. An empty array is allowed.\n\n" .
        "Permissible tags:\n{permissible_tags}\n\nTitle:\n{title}\n\nArticle:\n{content}";
}

function auto_tag_global_settings($pdo, $owner_uid) {
    $sth = $pdo->prepare("SELECT content FROM ttrss_plugin_storage WHERE owner_uid = ? AND name = ?");
    $sth->execute([$owner_uid, "OpenAI_Auto_Tag"]);
    $row = $sth->fetch();
    if (!$row) return null;

    $data = @unserialize($row["content"], ["allowed_classes" => false]);
    if (!is_array($data)) return null;

    return [
        "api_key" => trim($data["openai_api_key"] ?? ""),
        "base_url" => rtrim(trim($data["openai_base_url"] ?? "https://api.openai.com/v1"), "/"),
        "model" => trim($data["openai_model"] ?? "gpt-4o-mini"),
        "max_text_length" => max(500, min(20000, (int)($data["max_text_length"] ?? 4000))),
    ];
}

function auto_label_parse_allowlist($value) {
    $parts = preg_split('/[,\r\n]+/', (string)$value);
    $labels = [];
    $seen = [];
    foreach ($parts as $part) {
        $label = trim($part);
        $key = mb_strtolower($label);
        if ($label !== "" && mb_strlen($label) <= 250 && !isset($seen[$key])) {
            $labels[] = $label;
            $seen[$key] = true;
        }
    }
    return $labels;
}

function auto_tag_article_and_rule($pdo, $guid, $owner_uid) {
    $sth = $pdo->prepare(
        "SELECT e.id, e.title, e.content, ue.feed_id, " .
        "s.enabled, s.prompt, s.permissible_tags AS permissible_labels " .
        "FROM ttrss_entries e JOIN ttrss_user_entries ue ON ue.ref_id = e.id " .
        "LEFT JOIN ttrss_auto_tag_feed_settings s " .
        "ON s.feed_id = ue.feed_id AND s.owner_uid = ue.owner_uid " .
        "WHERE e.guid = ? AND ue.owner_uid = ? LIMIT 1"
    );
    $sth->execute([$guid, $owner_uid]);
    return $sth->fetch();
}

function auto_tag_call_api($prompt, $settings) {
    $payload = [
        "model" => $settings["model"],
        "messages" => [["role" => "user", "content" => $prompt]],
        "temperature" => 0,
        "max_tokens" => 500,
    ];

    $ch = curl_init($settings["base_url"] . "/chat/completions");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_HTTPHEADER => [
            "Content-Type: application/json",
            "Authorization: Bearer " . $settings["api_key"],
        ],
        CURLOPT_TIMEOUT => 180,
        CURLOPT_CONNECTTIMEOUT => 10,
    ]);

    $response = curl_exec($ch);
    $curl_error = curl_error($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($response === false) {
        return ["success" => false, "count_failure" => false, "message" => $curl_error];
    }

    $decoded = json_decode($response, true);
    if ($http_code !== 200) {
        $message = $decoded["error"]["message"] ?? "HTTP $http_code";
        $count_failure = $http_code >= 400 && $http_code < 500 && !in_array($http_code, [402, 429], true);
        return ["success" => false, "count_failure" => $count_failure, "message" => $message];
    }

    $content = $decoded["choices"][0]["message"]["content"] ?? null;
    if (!is_string($content)) {
        return ["success" => false, "count_failure" => true, "message" => "Response contained no message content"];
    }

    return ["success" => true, "content" => trim($content)];
}

function auto_label_parse_response($content, $permissible_labels) {
    $content = preg_replace('/^```(?:json)?\s*|\s*```$/i', '', trim($content));
    $decoded = json_decode($content, true);
    if (is_array($decoded) && isset($decoded["labels"]) && is_array($decoded["labels"])) {
        $decoded = $decoded["labels"];
    } else if (is_array($decoded) && isset($decoded["tags"]) && is_array($decoded["tags"])) {
        // Accept the legacy response envelope for existing custom prompts.
        $decoded = $decoded["tags"];
    }
    if (!is_array($decoded) || !array_is_list($decoded)) return null;

    $allowed = [];
    foreach ($permissible_labels as $label) $allowed[mb_strtolower($label)] = $label;

    $selected = [];
    foreach ($decoded as $label) {
        if (!is_string($label)) continue;
        $key = mb_strtolower(trim($label));
        if (isset($allowed[$key])) $selected[$key] = $allowed[$key];
    }
    return array_values($selected);
}

function auto_label_apply($pdo, $article_id, $owner_uid, $selected_labels) {
    if (!$selected_labels) return;

    $pdo->beginTransaction();
    try {
        $find = $pdo->prepare(
            "SELECT id FROM ttrss_labels2 WHERE LOWER(caption) = LOWER(?) AND owner_uid = ? LIMIT 1"
        );
        $create = $pdo->prepare(
            "INSERT INTO ttrss_labels2 (caption, owner_uid, fg_color, bg_color) " .
            "VALUES (?, ?, '', '') ON CONFLICT DO NOTHING RETURNING id"
        );
        $attach = $pdo->prepare(
            "INSERT INTO ttrss_user_labels2 (label_id, article_id) " .
            "SELECT ?, ? WHERE NOT EXISTS (" .
                "SELECT 1 FROM ttrss_user_labels2 WHERE label_id = ? AND article_id = ?" .
            ")"
        );

        foreach ($selected_labels as $caption) {
            $find->execute([$caption, $owner_uid]);
            $label_id = $find->fetchColumn();
            if (!$label_id) {
                $create->execute([$caption, $owner_uid]);
                $label_id = $create->fetchColumn();
            }
            if (!$label_id) {
                $find->execute([$caption, $owner_uid]);
                $label_id = $find->fetchColumn();
            }
            if (!$label_id) throw new RuntimeException("Unable to create label '$caption'");
            $attach->execute([(int)$label_id, $article_id, (int)$label_id, $article_id]);
        }

        $update = $pdo->prepare(
            "UPDATE ttrss_user_entries SET label_cache = '' WHERE ref_id = ? AND owner_uid = ?"
        );
        $update->execute([$article_id, $owner_uid]);
        $pdo->commit();
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

function auto_tag_process($pdo, $guid, $owner_uid) {
    $settings = auto_tag_global_settings($pdo, $owner_uid);
    if (!$settings || $settings["api_key"] === "" || $settings["base_url"] === "" || $settings["model"] === "") {
        return ["success" => false, "count_failure" => false, "message" => "API configuration is incomplete"];
    }

    $article = auto_tag_article_and_rule($pdo, $guid, $owner_uid);
    if (!$article) return ["success" => false, "count_failure" => true, "message" => "Article not found"];

    if (!filter_var($article["enabled"], FILTER_VALIDATE_BOOLEAN)) {
        error_log("OpenAI_Auto_Tag: Feed {$article['feed_id']} has no enabled rule; skipping guid=$guid");
        return ["success" => true];
    }

    $permissible_labels = auto_label_parse_allowlist($article["permissible_labels"]);
    if (!$permissible_labels) {
        return ["success" => false, "count_failure" => true, "message" => "Feed has an empty permissible label list"];
    }

    $content = str_replace(["<br>", "<br/>", "<br />", "</p>"], "\n", $article["content"] ?? "");
    $content = trim(html_entity_decode(strip_tags($content), ENT_QUOTES | ENT_HTML5, "UTF-8"));
    $content = mb_substr($content, 0, $settings["max_text_length"]);
    if ($content === "") return ["success" => false, "count_failure" => true, "message" => "Article content is empty"];

    $prompt_template = $article["prompt"] === auto_tag_legacy_default_prompt()
        ? auto_label_default_prompt()
        : $article["prompt"];
    $prompt = str_replace(
        ["{title}", "{content}", "{permissible_labels}", "{permissible_tags}"],
        [$article["title"] ?? "", $content, implode("\n", $permissible_labels), implode("\n", $permissible_labels)],
        $prompt_template
    );

    error_log("OpenAI_Auto_Tag: Calling model {$settings['model']} for guid=$guid, feed={$article['feed_id']}");
    $response = auto_tag_call_api($prompt, $settings);
    if (!$response["success"]) return $response;

    $selected_labels = auto_label_parse_response($response["content"], $permissible_labels);
    if ($selected_labels === null) {
        return ["success" => false, "count_failure" => true, "message" => "Model response was not a JSON label array"];
    }

    auto_label_apply($pdo, $article["id"], $owner_uid, $selected_labels);
    error_log("OpenAI_Auto_Tag: Applied labels [" . implode(", ", $selected_labels) . "] to guid=$guid");
    return ["success" => true];
}

$worker_id = getenv("SUPERVISOR_PROCESS_NUM") ?: "0";
$lock_file = "/tmp/ttrss-auto-tag-$worker_id.lock";
file_put_contents($lock_file, getmypid());
register_shutdown_function(function() use ($lock_file) { if (file_exists($lock_file)) unlink($lock_file); });
if (function_exists("pcntl_async_signals")) pcntl_async_signals(true);
if (function_exists("pcntl_signal")) {
    pcntl_signal(SIGTERM, function() { exit(0); });
    pcntl_signal(SIGINT, function() { exit(0); });
}

while (true) {
    $pdo = null;
    try {
        $pdo = auto_tag_db();
        $sth = $pdo->query(
            "SELECT guid, owner_uid, failure_count FROM ttrss_auto_tag_queue " .
            "WHERE failure_count < 10 AND (last_failed IS NULL OR last_failed < NOW() - INTERVAL '60 seconds') " .
            "ORDER BY last_failed NULLS FIRST LIMIT 1"
        );
        $item = $sth->fetch();

        if (!$item) {
            $pdo = null;
            sleep(10);
            continue;
        }

        $result = auto_tag_process($pdo, $item["guid"], $item["owner_uid"]);
        if ($result["success"]) {
            $delete = $pdo->prepare("DELETE FROM ttrss_auto_tag_queue WHERE guid = ? AND owner_uid = ?");
            $delete->execute([$item["guid"], $item["owner_uid"]]);
        } else {
            $failure_count = (int)$item["failure_count"] + ($result["count_failure"] ? 1 : 0);
            error_log("OpenAI_Auto_Tag: Failed guid={$item['guid']}: {$result['message']}");
            if ($failure_count >= 10) {
                $delete = $pdo->prepare("DELETE FROM ttrss_auto_tag_queue WHERE guid = ? AND owner_uid = ?");
                $delete->execute([$item["guid"], $item["owner_uid"]]);
            } else {
                $update = $pdo->prepare("UPDATE ttrss_auto_tag_queue SET failure_count = ?, last_failed = NOW() WHERE guid = ? AND owner_uid = ?");
                $update->execute([$failure_count, $item["guid"], $item["owner_uid"]]);
            }
        }
    } catch (Exception $e) {
        error_log("OpenAI_Auto_Tag: Worker error: " . $e->getMessage());
        sleep(10);
    } finally {
        $pdo = null;
    }
}
?>
