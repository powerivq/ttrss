<?php
// Background task that processes summary queue
// This script is called from the HOOK_HOUSE_KEEPING hook

// Function to get a fresh database connection
function get_db_connection() {
    // Get database credentials from environment
    $db_host = getenv('TTRSS_DB_HOST') ?: 'postgres';
    $db_port = getenv('TTRSS_DB_PORT') ?: '5432';
    $db_name = getenv('TTRSS_DB_NAME') ?: 'ttrss';
    $db_user = getenv('TTRSS_DB_USER') ?: 'ttrss';
    $db_pass = getenv('TTRSS_DB_PASS') ?: '';

    try {
        $dsn = "pgsql:host=$db_host;port=$db_port;dbname=$db_name";
        $pdo = new PDO($dsn, $db_user, $db_pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]);
        return $pdo;
    } catch (PDOException $e) {
        error_log("OpenAI_Auto_Summary: Database connection failed: " . $e->getMessage());
        throw $e;
    }
}

// Support multiple workers - each gets its own lock file
$worker_id = getenv('SUPERVISOR_PROCESS_NUM') ?: '0';
$lock_file = "/tmp/ttrss-summary-background-{$worker_id}.lock";
$pid = getmypid();

// Write our PID to the lock file
file_put_contents($lock_file, $pid);

// Register cleanup on script termination
register_shutdown_function(function() use ($lock_file, $pid) {
    // Only remove the lock file if it still contains our PID
    if (file_exists($lock_file)) {
        $current_pid = file_get_contents($lock_file);
        if ($current_pid == $pid) {
            unlink($lock_file);
        }
    }
});

// Handle SIGTERM and SIGINT to allow graceful shutdown
if (function_exists('pcntl_signal')) {
    pcntl_signal(SIGTERM, function() {
        exit(0);
    });
    pcntl_signal(SIGINT, function() {
        exit(0);
    });
}

// Get plugin settings for a specific owner
function get_plugin_settings($pdo, $owner_uid) {
    $sth = $pdo->prepare("SELECT content FROM ttrss_plugin_storage WHERE owner_uid = ? AND name = ?");
    $sth->execute([$owner_uid, 'OpenAI_Auto_Summary']);
    
    if ($row = $sth->fetch()) {
        $data = unserialize($row['content']);
        return [
            'openai_api_key' => isset($data['openai_api_key']) ? $data['openai_api_key'] : '',
            'openai_base_url' => isset($data['openai_base_url']) ? $data['openai_base_url'] : 'https://api.openai.com/v1',
            'openai_model' => isset($data['openai_model']) ? $data['openai_model'] : 'gpt-4o-mini',
            'max_text_length' => isset($data['max_text_length']) ? (int)$data['max_text_length'] : 2000,
            'summary_prompt' => isset($data['summary_prompt']) ? $data['summary_prompt'] : get_default_prompt()
        ];
    }
    
    return null;
}

function get_default_prompt() {
    return "Read the article below and produce output in French at B2 level. Format the output exactly using ONLY the tags shown below (no extra headings, no bullet points, no commentary).\n\n<title>\nStart with a clear, informative title in French.\n</title>\n\n<summary>\nWrite a concise summary in French that may be divided into short paragraphs if useful. The total length must be under 200 words. Use clear, natural, and accessible B2-level French with a neutral journalistic tone, mostly simple-to-moderate sentence structures, and common vocabulary. Focus on the main ideas and general context, avoid technical or minor details, and do not translate word for word—rewrite it as a native French journalist would for a general audience.\n</summary>\n\nThis is the end of the example output. The article is provided below.\n\nTitle:\n{title}\n\nArticle:\n{content}";
}

function call_openai_api($prompt, $settings) {
    $url = rtrim($settings['openai_base_url'], '/') . '/chat/completions';

    $data = array(
        'model' => $settings['openai_model'],
        'messages' => array(
            array(
                'role' => 'user',
                'content' => $prompt
            )
        ),
        'temperature' => 0.5,
        'max_tokens' => 5000
    );

    // Start timer
    $start_time = microtime(true);

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, array(
        'Content-Type: application/json',
        'Authorization: Bearer ' . $settings['openai_api_key']
    ));
    curl_setopt($ch, CURLOPT_TIMEOUT, 180);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    
    // Calculate response time
    $end_time = microtime(true);
    $response_time_ms = round(($end_time - $start_time) * 1000, 2);

    if (curl_errno($ch)) {
        error_log("OpenAI_Auto_Summary: Connection error: " . curl_error($ch) . " (response time: {$response_time_ms}ms)");
        curl_close($ch);
        return ["success" => false, "error" => "connection", "message" => curl_error($ch), "response_time_ms" => $response_time_ms];
    }
    curl_close($ch);

    $response_data = json_decode($response, true);

    if ($http_code !== 200) {
        $error_msg = isset($response_data['error']['message']) ? $response_data['error']['message'] : 'Unknown error';
        error_log("OpenAI_Auto_Summary: API Error ($http_code): $error_msg (response time: {$response_time_ms}ms)");
        
        // Determine error type
        $error_type = "client"; // default to client error (count failure)
        if ($http_code >= 500) {
            $error_type = "server"; // server error (don't count)
        } elseif ($http_code == 429 || $http_code == 402) {
            $error_type = "billing"; // rate limit or billing (don't count)
        }
        
        return ["success" => false, "error" => $error_type, "http_code" => $http_code, "message" => $error_msg, "response_time_ms" => $response_time_ms];
    }

    if (isset($response_data['choices'][0]['message']['content'])) {
        // Extract token usage if available
        $tokens = [];
        if (isset($response_data['usage'])) {
            $tokens = [
                'prompt_tokens' => $response_data['usage']['prompt_tokens'] ?? 0,
                'completion_tokens' => $response_data['usage']['completion_tokens'] ?? 0,
                'total_tokens' => $response_data['usage']['total_tokens'] ?? 0
            ];
        }
        
        error_log("OpenAI_Auto_Summary: API call successful (response time: {$response_time_ms}ms)");
        
        return [
            "success" => true, 
            "content" => trim($response_data['choices'][0]['message']['content']),
            "tokens" => $tokens,
            "response_time_ms" => $response_time_ms
        ];
    }

    return ["success" => false, "error" => "client", "message" => "No content in response", "response_time_ms" => $response_time_ms];
}

function process_queue_item($pdo, $guid, $owner_uid) {
    // Get plugin settings
    $settings = get_plugin_settings($pdo, $owner_uid);
    if (!$settings || empty($settings['openai_api_key'])) {
        error_log("OpenAI_Auto_Summary: No API key for user $owner_uid");
        return ["success" => false, "count_failure" => false]; // Don't count missing API key as failure
    }
    
    // Fetch article content from TT-RSS by guid
    $sth = $pdo->prepare("SELECT id, title, content FROM ttrss_entries WHERE guid = ?");
    $sth->execute([$guid]);
    $article = $sth->fetch();
    
    if (!$article) {
        error_log("OpenAI_Auto_Summary: Article with guid=$guid not found");
        return ["success" => false, "count_failure" => true]; // Count as failure - article doesn't exist
    }
    
    $article_id = $article['id'];
    $title = $article['title'] ?? '';
    $raw_content = $article['content'] ?? '';

    // Convert breaks to newlines to prevent word concatenation, then strip tags
    $content = str_replace(array('<br>', '<br/>', '<br />', '</p>'), "\n", $raw_content);
    $content = strip_tags($content);
    $content = trim($content);
    
    // Skip if content is empty
    if (empty($content)) {
        error_log("OpenAI_Auto_Summary: Empty content for article $article_id");
        return ["success" => false, "count_failure" => true]; // Count as failure
    }
    
    // Truncate content to avoid hitting token limits
    $content = mb_substr($content, 0, $settings['max_text_length']);
    error_log("OpenAI_Auto_Summary: Content length: " . mb_strlen($content) . " chars (max: {$settings['max_text_length']})");
    
    // Replace placeholders in prompt
    $prompt = str_replace(
        ['{title}', '{content}'],
        [$title, $content],
        $settings['summary_prompt']
    );
    
    // Call OpenAI API
    error_log("OpenAI_Auto_Summary: Calling OpenAI API with model: {$settings['openai_model']}");
    $api_response = call_openai_api($prompt, $settings);
    
    if (!$api_response['success']) {
        // Determine if we should count this failure
        $count_failure = ($api_response['error'] == 'client');
        error_log("OpenAI_Auto_Summary: API call failed for guid=$guid, error type: {$api_response['error']}");
        return ["success" => false, "count_failure" => $count_failure];
    }
    
    // Log token usage
    if (isset($api_response['tokens']) && !empty($api_response['tokens'])) {
        $tokens = $api_response['tokens'];
        $response_time = $api_response['response_time_ms'] ?? 0;
        error_log("OpenAI_Auto_Summary: Token usage - prompt: {$tokens['prompt_tokens']}, completion: {$tokens['completion_tokens']}, total: {$tokens['total_tokens']}, response time: {$response_time}ms");
    }
    
    $response = $api_response['content'];
    
    if (!empty($response)) {
        $extracted_title = "";
        $extracted_summary = "";
        
        // Parse <title> and <summary> tags
        if (preg_match('/<title>(.*?)<\/title>/s', $response, $matches)) {
            $extracted_title = trim($matches[1]);
        }
        if (preg_match('/<summary>(.*?)<\/summary>/s', $response, $matches)) {
            $extracted_summary = trim($matches[1]);
            // Replace multiple consecutive newlines (with optional whitespace between) with a single newline
            $extracted_summary = preg_replace('/(\s*\n){2,}/', "\n", $extracted_summary);
        }
        
        // Fallback: If no tags found, treat the whole response as summary
        if (empty($extracted_title) && empty($extracted_summary)) {
            $extracted_summary = trim($response);
        }
        
        // Log extracted content
        $summary_preview = mb_substr($extracted_summary, 0, 100);
        error_log("OpenAI_Auto_Summary: Extracted title: \"$extracted_title\", summary preview: \"$summary_preview...\"");
        
        // Construct summary text
        $summary = "";
        if (!empty($extracted_title)) {
            $summary .= "<h2>" . htmlspecialchars($extracted_title) . "</h2><br/>";
        }   
        $summary .= nl2br(htmlspecialchars($extracted_summary));
        
        // Store in database
        $sth = $pdo->prepare(
            "INSERT INTO ttrss_summary (guid, owner_uid, summary) VALUES (?, ?, ?) 
             ON CONFLICT (guid, owner_uid) DO UPDATE SET summary = EXCLUDED.summary"
        );
        $sth->execute([$guid, $owner_uid, $summary]);
        
        error_log("OpenAI_Auto_Summary: Successfully stored summary for article guid=$guid (user $owner_uid)");
        return ["success" => true, "count_failure" => false];
    }
    
    return ["success" => false, "count_failure" => true];
}

// Main loop - runs forever
while (true) {
    $pdo = null; // Ensure connection is null at start
    
    try {
        // Get a fresh database connection
        $pdo = get_db_connection();
        
        // Fetch one item from queue (excluding items that have failed 10+ times)
        // Use FOR UPDATE SKIP LOCKED to prevent multiple workers from grabbing the same item
        $sth = $pdo->prepare("SELECT guid, owner_uid, failure_count FROM ttrss_summary_queue WHERE failure_count < 10 LIMIT 1 FOR UPDATE SKIP LOCKED");
        $sth->execute();
        $queue_item = $sth->fetch();
        
        if ($queue_item) {
            $guid = $queue_item['guid'];
            $owner_uid = $queue_item['owner_uid'];
            $failure_count = $queue_item['failure_count'];
            
            error_log("OpenAI_Auto_Summary: Processing article guid=$guid for user $owner_uid (failures: $failure_count)");
            
            // Process the item
            $result = process_queue_item($pdo, $guid, $owner_uid);
            
            if ($result['success']) {
                // Success - remove from queue
                $sth = $pdo->prepare("DELETE FROM ttrss_summary_queue WHERE guid = ? AND owner_uid = ?");
                $sth->execute([$guid, $owner_uid]);
                error_log("OpenAI_Auto_Summary: Successfully processed article guid=$guid");
            } else {
                if ($result['count_failure']) {
                    // Failure that should be counted - increment counter
                    $new_failure_count = $failure_count + 1;
                    
                    if ($new_failure_count >= 10) {
                        // Remove after 10 failures
                        $sth = $pdo->prepare("DELETE FROM ttrss_summary_queue WHERE guid = ? AND owner_uid = ?");
                        $sth->execute([$guid, $owner_uid]);
                        error_log("OpenAI_Auto_Summary: Removed article guid=$guid after 10 failures");
                    } else {
                        // Update failure count and timestamp
                        $sth = $pdo->prepare("UPDATE ttrss_summary_queue SET failure_count = ?, last_failed = NOW() WHERE guid = ? AND owner_uid = ?");
                        $sth->execute([$new_failure_count, $guid, $owner_uid]);
                        error_log("OpenAI_Auto_Summary: Failed to process article guid=$guid (failures: $new_failure_count)");
                    }
                } else {
                    // Failure that shouldn't be counted (server/billing error) - don't update counter
                    error_log("OpenAI_Auto_Summary: Failed to process article guid=$guid (not counted - server/billing error)");
                }
            }
        } else {
            // Check if there are any items with 10+ failures to clean up
            $sth = $pdo->prepare("DELETE FROM ttrss_summary_queue WHERE failure_count >= 10");
            $deleted = $sth->execute();
            if ($sth->rowCount() > 0) {
                error_log("OpenAI_Auto_Summary: Cleaned up " . $sth->rowCount() . " items with 10+ failures");
            }
            
            // Close connection before sleeping
            $pdo = null;
            
            // Queue is empty, sleep for 10 seconds
            error_log("OpenAI_Auto_Summary: Queue empty, waiting...");
            sleep(10);
        }
    } catch (Exception $e) {
        error_log("OpenAI_Auto_Summary: Error in main loop: " . $e->getMessage());
        sleep(10); // Sleep on error to avoid tight loop
    } finally {
        // Always close the connection at the end of each iteration
        $pdo = null; // PHP PDO: setting to null closes the connection
    }
    
    // Process signals if available
    if (function_exists('pcntl_signal_dispatch')) {
        pcntl_signal_dispatch();
    }
}
?>
