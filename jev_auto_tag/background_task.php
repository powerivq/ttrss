<?php
require_once __DIR__ . "/processor.php";

if (function_exists("pcntl_async_signals")) pcntl_async_signals(true);
if (function_exists("pcntl_signal")) {
    pcntl_signal(SIGTERM, fn() => exit(0));
    pcntl_signal(SIGINT, fn() => exit(0));
}

$pdo = null;
while (true) {
    try {
        if (!$pdo) {
            $pdo = JevProcessor::db();
            $pdo->exec(file_get_contents(__DIR__ . "/init_pgsql.sql"));
        }
        $sth = $pdo->query(
            "SELECT guid, owner_uid, feed_id, queued_at, retry_count FROM ttrss_jev_queue " .
            "WHERE available_at <= NOW() ORDER BY queued_at LIMIT 1"
        );
        $item = $sth->fetch();
        if (!$item) {
            sleep(5);
            continue;
        }
        try {
            $result = JevProcessor::process($pdo, $item);
        } catch (Throwable $e) {
            error_log("Jev_Auto_Tag: Unable to process guid={$item['guid']}: " . $e->getMessage());
            $result = "retry";
        }
        if ($result === "retry" || ($result === "pending" && strtotime($item["queued_at"]) > time() - 3600)) {
            $retry_count = (int)$item["retry_count"] + ($result === "retry" ? 1 : 0);
            $delay = $result === "pending" ? 30 : min(3600, 60 * (2 ** min($retry_count - 1, 6)));
            $pdo->prepare(
                "UPDATE ttrss_jev_queue SET available_at = NOW() + (? * INTERVAL '1 second'), retry_count = ? " .
                "WHERE guid = ? AND owner_uid = ?"
            )->execute([$delay, $retry_count, $item["guid"], $item["owner_uid"]]);
            if ($result === "retry") {
                error_log("Jev_Auto_Tag: Retry $retry_count scheduled in {$delay}s for guid={$item['guid']}");
            }
        } else {
            $pdo->prepare("DELETE FROM ttrss_jev_queue WHERE guid = ? AND owner_uid = ?")
                ->execute([$item["guid"], $item["owner_uid"]]);
        }
    } catch (Throwable $e) {
        error_log("Jev_Auto_Tag: Worker error: " . $e->getMessage());
        $pdo = null;
        sleep(10);
    }
}
