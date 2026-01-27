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
    }

    private function call_openai_api($prompt) {
        $url = rtrim($this->openai_base_url, '/') . '/chat/completions';

        $data = array(
            'model' => $this->openai_model,
            'messages' => array(
                array(
                    'role' => 'user',
                    'content' => $prompt
                )
            ),
            'temperature' => 0.5,
            'max_tokens' => 5000 // Increased to 5000
        );

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'Content-Type: application/json',
            'Authorization: Bearer ' . $this->openai_api_key
        ));
        curl_setopt($ch, CURLOPT_TIMEOUT, 300); // Increased to 300 seconds (5 minutes)
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);

        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if (curl_errno($ch)) {
            error_log("OpenAI_Auto_Summary: Connection error: " . curl_error($ch));
            curl_close($ch);
            return "";
        }
        curl_close($ch);

        $response_data = json_decode($response, true);

        if ($http_code !== 200) {
            $error_msg = isset($response_data['error']['message']) ? $response_data['error']['message'] : 'Unknown error';
            error_log("OpenAI_Auto_Summary: API Error ($http_code): $error_msg");
            return "";
        }

        if (isset($response_data['choices'][0]['message']['content'])) {
            return trim($response_data['choices'][0]['message']['content']);
        }

        return "";
    }

    function hook_article_filter_action($article, $action) {
        try {
            if ($action != "openai_summary") return $article;

            if (!$this->openai_api_key) {
                return $article;
            }

            // Prepare content for the prompt
            $title = isset($article["title"]) ? $article["title"] : "";
            
            // Convert breaks to newlines to prevent word concatenation, then strip tags
            $raw_content = isset($article["content"]) ? $article["content"] : "";
            $content = str_replace(array('<br>', '<br/>', '<br />', '</p>'), "\n", $raw_content);
            $content = strip_tags($content);
            $content = trim($content);

            // Skip and early return if content is empty
            if (empty($content)) {
                return $article;
            }
            
            // Truncate content to avoid hitting token limits and control costs
            $content = mb_substr($content, 0, $this->max_text_length);

            // Replace placeholders in prompt
            $prompt = str_replace(
                ['{title}', '{content}'], 
                [$title, $content], 
                $this->summary_prompt
            );

            $response = $this->call_openai_api($prompt);

            if (!empty($response)) {
                $extracted_title = "";
                $extracted_summary = "";

                // Parse <title> and <summary> tags
                if (preg_match('/<title>(.*?)<\/title>/s', $response, $matches)) {
                    $extracted_title = trim($matches[1]);
                }
                if (preg_match('/<summary>(.*?)<\/summary>/s', $response, $matches)) {
                    $extracted_summary = trim($matches[1]);
                }

                // Fallback: If no tags found, treat the whole response as summary
                if (empty($extracted_title) && empty($extracted_summary)) {
                    $extracted_summary = trim($response);
                }

                // Construct HTML according to requested format: <div><h2>TITLE</h2>CONTENT</div><br/><hr/><br/><div>%s</div>
                $summary_html = "<div>";
                if (!empty($extracted_title)) {
                    $summary_html .= "<h2>" . htmlspecialchars($extracted_title) . "</h2>";
                }
                $summary_html .= nl2br(htmlspecialchars($extracted_summary));
                $summary_html .= "</div><br/><hr/><br/>";

                // Wrap original content in div and prepend summary structure
                $article["content"] = $summary_html . "<div>" . $article["content"] . "</div>";
            }

            return $article;
        } catch (Exception $e) {
            error_log("OpenAI_Auto_Summary: Error processing article: " . $e->getMessage());
            return $article;
        }
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
