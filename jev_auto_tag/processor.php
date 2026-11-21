<?php
class JevProcessor {
    static function db() {
        return new PDO(
            "pgsql:host=" . (getenv("TTRSS_DB_HOST") ?: "postgres") .
            ";port=" . (getenv("TTRSS_DB_PORT") ?: "5432") .
            ";dbname=" . (getenv("TTRSS_DB_NAME") ?: "ttrss"),
            getenv("TTRSS_DB_USER") ?: "ttrss",
            getenv("TTRSS_DB_PASS") ?: "",
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
        );
    }

    static function settings($pdo, $owner_uid) {
        $sth = $pdo->prepare("SELECT content FROM ttrss_plugin_storage WHERE owner_uid = ? AND name = 'Jev_Auto_Tag'");
        $sth->execute([$owner_uid]);
        $data = @unserialize($sth->fetchColumn() ?: '', ["allowed_classes" => false]);
        if (!is_array($data)) $data = [];
        return [
            "api_key" => trim($data["typesafe_api_key"] ?? ""),
            "base_url" => rtrim(trim($data["typesafe_base_url"] ?? "https://api.typesafe.ai/v1"), "/"),
            "model" => trim($data["jev_model"] ?? "jev-latest"),
            "threshold" => max(0.0, min(1.0, (float)($data["label_threshold"] ?? 0.7))),
            "max_text_length" => max(100, min(5000, (int)($data["max_text_length"] ?? 400))),
            "label_rules" => $data["label_rules"] ?? "",
        ];
    }

    static function parse_label_rules($value) {
        $rules = [];
        foreach (preg_split('/\R/u', (string)$value) as $line) {
            $parts = array_map("trim", explode("|", $line, 2));
            $id = filter_var($parts[0], FILTER_VALIDATE_INT, ["options" => ["min_range" => 1]]);
            if ($id && ($parts[1] ?? "") !== "") $rules[$id] = $parts[1];
        }
        return $rules;
    }

    static function parse_levels($value) {
        return array_values(array_filter(array_map("trim", preg_split('/\R/u', (string)$value)), fn($v) => $v !== ""));
    }

    static function article_text($content, $max_length) {
        $content = str_replace(["<br>", "<br/>", "<br />", "</p>"], "\n", (string)$content);
        return trim(mb_substr(html_entity_decode(strip_tags($content), ENT_QUOTES | ENT_HTML5, "UTF-8"), 0, $max_length));
    }

    static function call_api($settings, $state, $questions) {
        $url = str_ends_with($settings["base_url"], "/systemone")
            ? $settings["base_url"] : $settings["base_url"] . "/systemone";
        $body = json_encode([
            "state" => $state, "model" => $settings["model"], "questions" => $questions,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => ["Authorization: Bearer " . $settings["api_key"], "Content-Type: application/json"],
            CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 180,
        ]);
        $response = curl_exec($ch);
        $error = curl_error($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($response === false) throw new RuntimeException("Connection error: $error");
        $decoded = json_decode($response, true);
        if (!is_array($decoded)) throw new RuntimeException("API returned invalid JSON");
        if ($code !== 200) {
            $detail = $decoded["detail"] ?? $decoded["message"] ?? $decoded["error"] ?? "HTTP $code";
            throw new RuntimeException(is_string($detail) ? $detail : json_encode($detail));
        }
        if (!isset($decoded["answers"]) || !is_array($decoded["answers"])) {
            throw new RuntimeException("API response contained no answers");
        }
        return $decoded;
    }

    static function answer($answers, $key, $type, $field) {
        $answer = $answers[$key] ?? null;
        if (!is_array($answer) || ($answer["type"] ?? null) !== $type ||
                !is_numeric($answer[$field] ?? null) || !is_finite((float)$answer[$field])) {
            throw new RuntimeException("API response omitted a valid $key answer");
        }
        return (float)$answer[$field];
    }

    static function process($pdo, $item) {
        $guid = $item["guid"];
        $owner_uid = (int)$item["owner_uid"];
        $feed_id = (int)$item["feed_id"];
        $previous = $pdo->prepare("SELECT status FROM ttrss_jev_label_attempts WHERE guid = ? AND owner_uid = ?");
        $previous->execute([$guid, $owner_uid]);
        if ($previous->fetchColumn() === "success") return "skip";
        $sth = $pdo->prepare(
            "SELECT e.id, e.title, e.content, s.label_enabled, s.rank_enabled, s.rank_question, " .
            "s.rank_levels, s.auto_read_enabled, s.auto_read_question, s.auto_read_threshold " .
            "FROM ttrss_entries e JOIN ttrss_user_entries ue ON ue.ref_id = e.id " .
            "AND ue.owner_uid = ? AND ue.feed_id = ? " .
            "JOIN ttrss_jev_feed_settings s ON s.owner_uid = ue.owner_uid AND s.feed_id = ue.feed_id " .
            "WHERE e.guid = ? LIMIT 1"
        );
        $sth->execute([$owner_uid, $feed_id, $guid]);
        $article = $sth->fetch();
        if (!$article) return "pending";

        $settings = self::settings($pdo, $owner_uid);
        $title = self::article_text($article["title"], 5000);
        $content = self::article_text($article["content"], $settings["max_text_length"]);
        // Leave no attempt record: a later import with meaningful text can evaluate it.
        if (!preg_match('/[^\s\p{Z}\p{C}]/u', $title . $content)) return "skip";
        $labels_enabled = filter_var($article["label_enabled"], FILTER_VALIDATE_BOOLEAN);
        $rank_enabled = filter_var($article["rank_enabled"], FILTER_VALIDATE_BOOLEAN);
        $read_enabled = filter_var($article["auto_read_enabled"], FILTER_VALIDATE_BOOLEAN);
        if (!$labels_enabled && !$rank_enabled && !$read_enabled) return "skip";

        $rules = $labels_enabled ? self::parse_label_rules($settings["label_rules"]) : [];
        $levels = $rank_enabled ? self::parse_levels($article["rank_levels"]) : [];
        if (($labels_enabled && !$rules) || ($rank_enabled &&
                (trim($article["rank_question"]) === "" || count($levels) < 2 || count($levels) > 10)) ||
                ($read_enabled && trim($article["auto_read_question"]) === "") ||
                $settings["api_key"] === "" || $settings["base_url"] === "" || $settings["model"] === "") {
            throw new RuntimeException("Jev configuration is incomplete for feed $feed_id");
        }
        if ($labels_enabled) {
            $find_label = $pdo->prepare("SELECT 1 FROM ttrss_labels2 WHERE id = ? AND owner_uid = ?");
            foreach (array_keys($rules) as $label_id) {
                $find_label->execute([$label_id, $owner_uid]);
                if (!$find_label->fetchColumn()) {
                    throw new RuntimeException("Configured label ID $label_id no longer exists");
                }
            }
        }
        $questions = [];
        foreach ($rules as $id => $question) {
            $questions["label_$id"] = ["type" => "noul", "instructions" => $question];
        }
        if ($rank_enabled) {
            $questions["article_rank"] = ["type" => "score", "instructions" => $article["rank_question"], "criteria" => $levels];
        }
        if ($read_enabled) {
            $questions["auto_read"] = ["type" => "noul", "instructions" => $article["auto_read_question"]];
        }

        $claim = $pdo->prepare(
            "INSERT INTO ttrss_jev_label_attempts (guid, owner_uid, model, config_hash) " .
            "VALUES (?, ?, ?, ?) ON CONFLICT (guid, owner_uid) DO UPDATE SET " .
            "status = 'processing', attempted_at = NOW(), completed_at = NULL, error = NULL, " .
            "model = EXCLUDED.model, config_hash = EXCLUDED.config_hash " .
            "WHERE ttrss_jev_label_attempts.status = 'failed' OR " .
            "(ttrss_jev_label_attempts.status = 'processing' AND " .
            "ttrss_jev_label_attempts.attempted_at < NOW() - INTERVAL '10 minutes') RETURNING guid"
        );
        $claim->execute([$guid, $owner_uid, $settings["model"], hash("sha256", json_encode($questions))]);
        if (!$claim->fetchColumn()) {
            $status = $pdo->prepare("SELECT status FROM ttrss_jev_label_attempts WHERE guid = ? AND owner_uid = ?");
            $status->execute([$guid, $owner_uid]);
            return $status->fetchColumn() === "success" ? "skip" : "retry";
        }

        try {
            error_log("Jev_Auto_Tag: Calling {$settings['model']} for guid=$guid, feed=$feed_id with " . count($questions) . " questions");
            $response = self::call_api($settings, ["title" => $title, "content" => $content], $questions);
            $selected = [];
            foreach ($rules as $id => $_) {
                if (self::answer($response["answers"], "label_$id", "noul", "noul") >= $settings["threshold"]) {
                    $selected[] = $id;
                }
            }
            $score = $rank_enabled
                ? (int)round(max(0.0, min(1.0, self::answer($response["answers"], "article_rank", "score", "score") / (count($levels) - 1))) * 1000)
                : null;
            $read_probability = $read_enabled
                ? self::answer($response["answers"], "auto_read", "noul", "noul") : null;
            $read = $read_probability !== null && $read_probability >= (float)$article["auto_read_threshold"];

            $pdo->beginTransaction();
            try {
                $selected_names = [];
                if ($selected) {
                    $find_label = $pdo->prepare("SELECT caption FROM ttrss_labels2 WHERE id = ? AND owner_uid = ?");
                    $attach = $pdo->prepare(
                        "INSERT INTO ttrss_user_labels2 (label_id, article_id) SELECT ?, ? " .
                        "WHERE NOT EXISTS (SELECT 1 FROM ttrss_user_labels2 WHERE label_id = ? AND article_id = ?)"
                    );
                    foreach ($selected as $label_id) {
                        $find_label->execute([$label_id, $owner_uid]);
                        $caption = $find_label->fetchColumn();
                        if ($caption === false) throw new RuntimeException("Configured label ID $label_id no longer exists");
                        $attach->execute([$label_id, $article["id"], $label_id, $article["id"]]);
                        $selected_names[] = $caption;
                    }
                    $pdo->prepare("UPDATE ttrss_user_entries SET label_cache = '' WHERE ref_id = ? AND owner_uid = ?")
                        ->execute([$article["id"], $owner_uid]);
                }
                if ($score !== null) {
                    $pdo->prepare("UPDATE ttrss_user_entries SET score = score + ? WHERE ref_id = ? AND owner_uid = ?")
                        ->execute([$score, $article["id"], $owner_uid]);
                }
                $marked_read = 0;
                if ($read) {
                    $mark_read = $pdo->prepare("UPDATE ttrss_user_entries SET unread = FALSE, last_read = NOW() " .
                        "WHERE ref_id = ? AND owner_uid = ? AND unread = TRUE");
                    $mark_read->execute([$article["id"], $owner_uid]);
                    $marked_read = $mark_read->rowCount();
                }
                $pdo->prepare(
                    "UPDATE ttrss_jev_label_attempts SET completed_at = NOW(), status = 'success', " .
                    "selected_labels = ?, score_modifier = ? WHERE guid = ? AND owner_uid = ?"
                )->execute([json_encode($selected_names, JSON_UNESCAPED_UNICODE), $score, $guid, $owner_uid]);
                $pdo->commit();
                error_log("Jev_Auto_Tag: guid=$guid labels=[" . implode(", ", $selected_names) . "] score=" .
                    ($score ?? "off") . " auto_read=" . ($read ? "yes" : "no") .
                    " probability=" . ($read_probability === null ? "off" : sprintf("%.3f", $read_probability)) .
                    " threshold=" . ($read_enabled ? $article["auto_read_threshold"] : "off") .
                    " marked_read=$marked_read");
            } catch (Throwable $e) {
                $pdo->rollBack();
                throw $e;
            }
        } catch (Throwable $e) {
            $pdo->prepare(
                "UPDATE ttrss_jev_label_attempts SET completed_at = NOW(), status = 'failed', error = ? " .
                "WHERE guid = ? AND owner_uid = ?"
            )->execute([mb_substr($e->getMessage(), 0, 2000), $guid, $owner_uid]);
            error_log("Jev_Auto_Tag: Failed guid=$guid: " . $e->getMessage());
            return "retry";
        }
        return "done";
    }
}
