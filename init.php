<?php

class af_refspoof extends Plugin
{
    private const LEGACY_ENABLED_FEEDS = 'feeds'; // 2021-03-14
    private const ENABLED_FEEDS = "enabled_feeds";
    private const ENABLED_DOMAINS = "enabled_domains";
    private const ENABLED_GLOBALLY = "enabled_globally";

    private $host;

    public function about()
    {
        return array(
            null,
            "Fakes referral header on images",
            "Alexander Chernov",
            false,
            "https://github.com/klempin/af_refspoof"
        );
    }

    public function csrf_ignore($method)
    {
        if ($method === "proxy") {
            return true;
        }

        return false;
    }

    public function api_version()
    {
        return 2;
    }

    public function init($host)
    {
        $this->host = $host;
        $this->host->add_hook($host::HOOK_PREFS_EDIT_FEED, $this);
        $this->host->add_hook($host::HOOK_PREFS_SAVE_FEED, $this);
        $this->host->add_hook($host::HOOK_PREFS_TAB, $this);
        $this->host->add_hook($host::HOOK_RENDER_ARTICLE_CDM, $this);

        $legacyEnabledFeeds = $this->host->get($this, static::LEGACY_ENABLED_FEEDS, false);
        if (is_array($legacyEnabledFeeds)) {
            $enabledFeeds = $this->host->get($this, static::ENABLED_FEEDS, array());

            foreach ($legacyEnabledFeeds as $key => $value) {
                if (!array_key_exists($key, $enabledFeeds)) {
                    $enabledFeeds[$key] = $value;
                }
            }

            $this->host->set($this, static::LEGACY_ENABLED_FEEDS, null);
            $this->host->set($this, static::ENABLED_FEEDS, $enabledFeeds);
        }
    }

    public function hook_prefs_edit_feed($feedId)
    {
        $feed = ORM::for_table("ttrss_feeds")
            ->where("id", $feedId)
            ->where("owner_uid", $_SESSION["uid"])
            ->find_one();
        $result = $this->feedEnabled($feedId, $feed["site_url"], true);

        if ($result["enabled"] === false) {
            $checked = "";
            $message = "";
        } elseif ($result["reason"] === static::ENABLED_FEEDS) {
            $checked = " checked";
            $message = "";
        } elseif ($result["reason"] === static::ENABLED_DOMAINS) {
            $checked = " checked disabled";
            $message = "<p>" . __("This feed is enabled based on the domain list") . "</p>";
        } elseif ($result["reason"] === static::ENABLED_GLOBALLY) {
            $checked = " checked disabled";
            $message = "<p>" . __("Fake referral is enabled globally.") . "</p>";
        } else {
            $checked = " checked";
            $message = "";
        }

        $title = __("Fake referral");
        $label = __('Fake referral for this feed');
        echo <<<EOF
<header>{$title}</header>
{$message}
<section>
    <fieldset>
        <input dojoType="dijit.form.CheckBox" type="checkbox" id="af_refspoof_enabled" name="af_refspoof_enabled"{$checked}>
        <label class="checkbox" for="af_refspoof_enabled">
            {$label}
        </label>
    </fieldset>
</section>
EOF;
    }

    public function hook_prefs_save_feed($feedId)
    {
        $enabledFeeds = $this->host->get($this, static::ENABLED_FEEDS, array());

        if (checkbox_to_sql_bool($_POST["af_refspoof_enabled"] ?? false)) {
            $enabledFeeds[$feedId] = $feedId;
        } else {
            unset($enabledFeeds[$feedId]);
        }

        $this->host->set($this, static::ENABLED_FEEDS, $enabledFeeds);
    }

    public function hook_prefs_tab($args)
    {
        if ($args !== "prefFeeds") {
            return;
        }

        $title = __("Fake referral");
        $heading = __("Enable referral spoofing based on the feed domain (enter one domain per line)");
        $enabledFeedsHeading = __("Fake referral is enabled for these feeds");
        $enabledDomains = htmlspecialchars(implode("\n", $this->host->get($this, static::ENABLED_DOMAINS, array())));
        $enabledGloballyCheckbox = \Controls\checkbox_tag(
            "af_refspoof_enabled_globally",
            $this->host->get($this, static::ENABLED_GLOBALLY, false) === true ? true : false,
            "on",
            array(),
            "af_refspoof_enabled_globally"
        );
        $enabledGlobally = __("Enabled globally");
        $pluginHandlerTags = \Controls\pluginhandler_tags($this, "save_settings");
        $submitTag = \Controls\submit_tag(__("Save"));
        $feeds = ORM::for_table("ttrss_feeds")
            ->where("owner_uid", $_SESSION["uid"])
            ->find_many();
        $enabledFeeds = "";
        foreach ($feeds as $feed) {
            $result = $this->feedEnabled($feed["id"], $feed["site_url"], true);
            if ($result["enabled"] !== true) {
                continue;
            }
            $enabledFeeds .= <<<EOT
<tr>
    <td><i class="material-icons">rss_feed</i></td>
    <td><a href="#"	onclick="CommonDialogs.editFeed({$feed["id"]})">{$feed["title"]}</a></td>
    <td>{$result["comment"]}</td>
</tr>
EOT;
        }

        echo <<<EOT
<div dojoType="dijit.layout.AccordionPane" title="<i class='material-icons'>image</i> {$title}">
    <h3>{$heading}</h3>

    <form dojoType='dijit.form.Form'>
        {$pluginHandlerTags}
        <script type="dojo/method" event="onSubmit" args="evt">
            evt.preventDefault();
            if (this.validate()) {
                Notify.progress('Saving data...', true);
                xhr.post("backend.php", this.getValues(), (reply) => {
                    Notify.info(reply);
                })
            }
        </script>

        <fieldset>
            <textarea name="af_refspoof_domains" data-dojo-type="dijit/form/SimpleTextarea" style="height:400px;box-sizing:border-box;">{$enabledDomains}</textarea>
        </fieldset>

        <fieldset>
            <label for="af_refspoof_enabled_globally" class="checkbox">{$enabledGloballyCheckbox} {$enabledGlobally}</label>
        </fieldset>

        <hr>

        <fieldset>
            {$submitTag}
        </fieldset>
    </form>

    <hr>

    <h3>{$enabledFeedsHeading}</h3>
    <div class="panel panel-scrollable">
        <table>
            {$enabledFeeds}
        </table>
    </div>
</div>
EOT;
    }

    public function hook_render_article_cdm($article)
    {
        if ($this->feedEnabled($article["feed_id"], $article["site_url"]) === true) {
            $doc = new DOMDocument();
            if (!empty($article["content"]) && $doc->loadHTML($article["content"])) {
                $xpath = new DOMXPath($doc);
                $entries = $xpath->query("(//img[@src])");
                $backendURL = Config::get_self_url() . '/backend.php?op=pluginhandler&method=proxy&plugin=af_refspoof';

                foreach ($entries as $entry) {
                    $origSrc = $entry->getAttribute("src");
                    if ($origSrcSet = $entry->getAttribute("srcset")) {
                        $srcSet = preg_replace_callback('#([^\s]+://[^\s]+)#', function ($m) use ($backendURL, $article) {
                            return $backendURL . '&url=' . urlencode($m[0]) . '&ref=' . urlencode($article['link']);
                        }, $origSrcSet);
                        $entry->setAttribute("srcset", $srcSet);
                    }
                    $url = $backendURL . '&url=' . urlencode($origSrc) . '&ref=' . urlencode($article['link']);
                    $entry->setAttribute("src", $url);
                }
                $article["content"] = $doc->saveXML();
            }
        }

        return $article;
    }

    public function proxy()
    {
        $url = parse_url($_REQUEST["url"]);
        $ref = parse_url($_REQUEST["ref"]);
        $requestUri = "";

        if (strpos($_REQUEST["url"], "/") === 0) {
            $requestUri .= ($ref["scheme"] ?? "http") . ":";

            if (strpos($_REQUEST["url"], "//") !== 0) {
                $requestUri .= "/";
            }
        }

        $requestUri .= $_REQUEST["url"];
        $userAgent = "Mozilla/5.0 (Windows NT 6.0; WOW64; rv:66.0) Gecko/20100101 Firefox/66.0";

        $curl = curl_init($requestUri);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($curl, CURLOPT_REFERER, $_REQUEST["ref"]);
        curl_setopt($curl, CURLOPT_USERAGENT, $userAgent);
        $curlData = curl_exec($curl);
        $curlInfo = curl_getinfo($curl);
        curl_close($curl);

        if (($_REQUEST["origin_info"] ?? false) && $_SESSION["access_level"] >= 10) {
            header("Content-Type: text/plain");
            echo "Request url:                  " . $_REQUEST["url"] . "\n";
            echo "Request url after processing: " . $requestUri . "\n";
            echo "Referrer url:                 " . $_REQUEST["ref"] . "\n";
            echo "Base name:                    " . basename($url["path"]) . "\n\n";
            echo "CURL information:\n";
            print_r($curlInfo);
            echo "\nCURL data:\n";
            echo $curlData;
        } else if (($curlInfo["http_code"] ?? false) === 200) {
            if (($url["path"] ?? null) !== null) {
                $fileName = basename($url["path"]);
                if (strlen(pathinfo($fileName, PATHINFO_EXTENSION)) === 0) {
                    $contentTypes = [
                        "image/bmp" => ".bmp",
                        "image/gif" => ".gif",
                        "image/vnd.microsoft.icon" => ".ico",
                        "image/jpeg" => ".jpg",
                        "image/png" => ".png",
                        "image/svg+xml" => ".svg",
                        "image/tiff" => ".tif",
                        "image/webp" => ".webp"
                    ];

                    $fileName .= $contentTypes[$curlInfo["content_type"]] ?? "";
                }

                header('Content-Disposition: inline; filename="' . $fileName . '"');
            }
            header("Content-Type: " . $curlInfo["content_type"]);
            echo $curlData;
        } {
            http_response_code($curlInfo["http_code"]);
        }
    }

    public function save_settings()
    {
        $enabledDomains = str_replace("\r", "", $_POST["af_refspoof_domains"] ?? "");
        $enabledDomains = explode("\n", $enabledDomains);

        foreach ($enabledDomains as $key => $value) {
            if (strlen(trim($value)) === 0) {
                unset($enabledDomains[$key]);
            }
        }

        $this->host->set_array($this, [
            static::ENABLED_DOMAINS => $enabledDomains ?? [],
            static::ENABLED_GLOBALLY => ($_POST["af_refspoof_enabled_globally"] ?? "") === "on" ? true : false
        ]);

        echo __("af_refspoof: Settings saved");
    }

    private function feedEnabled(int $feedId, string $siteUrl = null, bool $extendedInfo = false)
    {
        if ($this->host->get($this, static::ENABLED_GLOBALLY, false)) {
            return $extendedInfo === false ? true : [
                "enabled" => true,
                "reason" => static::ENABLED_GLOBALLY,
                "comment" => "Enabled globally"
            ];
        }

        if ($siteUrl !== null) {
            $enabledDomains = $this->host->get($this, static::ENABLED_DOMAINS, array());
            $siteHost = parse_url($siteUrl, PHP_URL_HOST);

            if ($siteHost !== false) {
                foreach ($enabledDomains as $enabledDomain) {
                    if (PHP_MAJOR_VERSION >= 8) {
                        if (str_ends_with(mb_strtolower($siteHost), mb_strtolower($enabledDomain))) {
                            return $extendedInfo === false ? true : [
                                "enabled" => true,
                                "reason" => static::ENABLED_DOMAINS,
                                "comment" => "Enabled domain: " . $enabledDomain
                            ];
                        }
                    } else {
                        if (mb_strtolower($siteHost) === mb_strtolower($enabledDomain)) {
                            return $extendedInfo === false ? true : [
                                "enabled" => true,
                                "reason" => static::ENABLED_DOMAINS,
                                "comment" => "Enabled domain: " . $enabledDomain
                            ];
                        }
                    }
                }
            }
        }

        $enabledFeeds = $this->host->get($this, static::ENABLED_FEEDS, []);
        if (array_key_exists($feedId, $enabledFeeds)) {
            return $extendedInfo === false ? true : [
                "enabled" => true,
                "reason" => static::ENABLED_FEEDS,
                "comment" => "Enabled feed"
            ];
        }

        return $extendedInfo === false ? false : [
            "enabled" => false,
            "reason" => "No match",
            "comment" => "Disabled"
        ];
    }
}
