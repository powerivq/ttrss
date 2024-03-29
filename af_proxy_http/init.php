<?php
class Af_Proxy_Http extends Plugin {

	/* @var PluginHost $host */
	private $host;

	/* @var DiskCache $cache */
	private $cache;

	function about() {
		return array(null,
			"Loads media served over plain HTTP via built-in secure proxy",
			"fox");
	}

	private $ssl_known_whitelist = "imgur.com gfycat.com i.reddituploads.com pbs.twimg.com i.redd.it i.sli.mg media.tumblr.com";

	function is_public_method($method) {
		return $method === "imgproxy";
	}

	function init($host) {
		$this->host = $host;
		$this->cache = new DiskCache("images");

		$host->add_hook($host::HOOK_RENDER_ARTICLE, $this, 150);
		$host->add_hook($host::HOOK_RENDER_ARTICLE_CDM, $this, 150);
		$host->add_hook($host::HOOK_ENCLOSURE_ENTRY, $this);

		$host->add_hook($host::HOOK_PREFS_TAB, $this);

		if (empty($_SESSION['af_proxy_http_token']))
			$_SESSION['af_proxy_http_token'] = bin2hex(get_random_bytes(16));
	}

	function hook_enclosure_entry($enc, $id, $rv) {
		if (preg_match("/image/", $enc["content_type"])) {
			$proxy_all = $this->host->get($this, "proxy_all");

			$enc["content_url"] = $this->rewrite_url_if_needed($enc["content_url"], $proxy_all);
		}

		return $enc;
	}

	function hook_render_article($article) {
		return $this->hook_render_article_cdm($article);
	}

	public function imgproxy() {
		$url = UrlHelper::validate(clean($_REQUEST["url"]));

		// immediately redirect to original URL if:
		// - url points back to ourselves
		// - called without user context
		// - session-spefific token is invalid
		if (
			strpos($url, get_self_url_prefix()) === 0 ||
			empty($_SESSION["uid"]) ||
			isset($_SESSION['af_proxy_http_token']) &&
			($_REQUEST['af_proxy_http_token'] ?? null) != $_SESSION['af_proxy_http_token']) {
			header("Location: $url");
			return;
		}

		$local_filename = sha1($url);

		if ($this->cache->exists($local_filename)) {
			header("Location: " . $this->cache->get_url($local_filename));
			return;
		} else {
			$data = UrlHelper::fetch(["url" => $url, "max_size" => Config::get(Config::MAX_CACHE_FILE_SIZE)]);

			if ($data) {
				if ($this->cache->put($local_filename, $data)) {
					header("Location: " . $this->cache->get_url($local_filename));
					return;
				}
			} else {
				if (function_exists("imagecreate") && !isset($_REQUEST["text"])) {
					$img = imagecreate(450, 75);

					/*$bg =*/ imagecolorallocate($img, 255, 255, 255);
					$textcolor = imagecolorallocate($img, 255, 0, 0);

					imagerectangle($img, 0, 0, 450-1, 75-1, $textcolor);

					imagestring($img, 5, 5, 5, "Proxy request failed", $textcolor);
					imagestring($img, 5, 5, 30, truncate_middle($url, 46, "..."), $textcolor);
					imagestring($img, 5, 5, 55, "HTTP Code: ".UrlHelper::$fetch_last_error_code, $textcolor);

					header("Content-type: image/png");
					print imagepng($img);
					imagedestroy($img);

				} else {
					header("Content-type: text/plain");

					http_response_code(400);

					print "Proxy request failed.\n".
						"Fetch error ".UrlHelper::$fetch_last_error." (".UrlHelper::$fetch_last_error_code.")\n".
						"Requested URL: $url";
				}
			}
		}
	}

	private function rewrite_url_if_needed($url, $all_remote = false) {
		/* don't rewrite urls pointing to ourselves */

		if (strpos($url, get_self_url_prefix()) === 0)
			return $url;

		/* we don't need to handle URLs where local cache already exists, tt-rss rewrites those automatically */
		if (!$this->cache->exists(sha1($url))) {

			$scheme = parse_url($url, PHP_URL_SCHEME);

			if ($all_remote) {
				$host = parse_url($url, PHP_URL_HOST);
				$self_host = parse_url(get_self_url_prefix(), PHP_URL_HOST);

				$is_remote = $host != $self_host;
			} else {
				$is_remote = false;
			}

			if (($scheme != 'https' && $scheme != "") || $is_remote) {
				if (strpos($url, "data:") !== 0) {
					$parts = parse_url($url);

					foreach (explode(" " , $this->ssl_known_whitelist) as $host) {
						if (substr(strtolower($parts['host']), -strlen($host)) === strtolower($host)) {
							$parts['scheme'] = 'https';
							$url = UrlHelper::build_url($parts);
							if ($all_remote && $is_remote) {
								break;
							} else {
								return $url;
							}
						}
					}

					return $this->host->get_public_method_url($this, "imgproxy",
						["url" => $url, "af_proxy_http_token" => $_SESSION["af_proxy_http_token"]]);
				}
			}
		}

		return $url;
	}

	function hook_render_article_cdm($article, $api_mode = false) {

		$need_saving = false;
		$proxy_all = $this->host->get($this, "proxy_all");

		$doc = new DOMDocument();
		if (@$doc->loadHTML('<?xml encoding="UTF-8">' . $article["content"])) {
			$xpath = new DOMXPath($doc);
			$imgs = $xpath->query("//img[@src]");

			foreach ($imgs as $img) {
				$new_src = $this->rewrite_url_if_needed($img->getAttribute("src"), $proxy_all);

				if ($new_src != $img->getAttribute("src")) {
					$img->setAttribute("src", $new_src);
					$img->removeAttribute("srcset");

					$need_saving = true;
				}
			}

			$vids = $xpath->query("(//video|//picture)");

			foreach ($vids as $vid) {
				if ($vid->hasAttribute("poster")) {
					$new_src = $this->rewrite_url_if_needed($vid->getAttribute("poster"), $proxy_all);

					if ($new_src != $vid->getAttribute("poster")) {
						$vid->setAttribute("poster", $new_src);

						$need_saving = true;
					}
				}

				$vsrcs = $xpath->query("source", $vid);

				foreach ($vsrcs as $vsrc) {
					$new_src = $this->rewrite_url_if_needed($vsrc->getAttribute("src"), $proxy_all);

					if ($new_src != $vsrc->getAttribute("src")) {
						$vid->setAttribute("src", $new_src);

						$need_saving = true;
					}
				}
			}
		}

		if ($need_saving) $article["content"] = $doc->saveHTML();

		return $article;
	}

	function hook_prefs_tab($args) {
		if ($args != "prefFeeds") return;
		?>

		<div dojoType="dijit.layout.AccordionPane"
			title="<i class='material-icons'>extension</i> <?= __('Image proxy settings (af_proxy_http)') ?>">

			<form dojoType="dijit.form.Form">

				<?= \Controls\pluginhandler_tags($this, "save") ?>

				<script type="dojo/method" event="onSubmit" args="evt">
					evt.preventDefault();
					if (this.validate()) {
						xhr.post("backend.php", this.getValues(), (reply) => {
							Notify.info(reply);
						})
					}
				</script>

				<fieldset>
					<label class="checkbox">
						<?= \Controls\checkbox_tag("proxy_all", $this->host->get($this, "proxy_all")) ?>
						<?=  __("Enable proxy for all remote images.") ?>
					</label>
				</fieldset>

				<hr/>

				<?= \Controls\submit_tag(__("Save")) ?>
			</form>
		</div>
		<?php
	}

	function save() {
		$proxy_all = checkbox_to_sql_bool($_POST["proxy_all"] ?? "");

		$this->host->set($this, "proxy_all", $proxy_all);

		echo __("Configuration saved");
	}

	function api_version() {
		return 2;
	}
}
