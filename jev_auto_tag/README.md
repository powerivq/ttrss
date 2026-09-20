# Jev Auto Tag

Synchronous tt-rss filter action that assigns tags with TypeSafe Jev.

## Setup

1. Enable `jev_auto_tag` in **Preferences > Plugins**.
2. Open **Preferences > Feeds > Jev Auto Tag Settings**.
3. Configure the TypeSafe API key, model, threshold, prompts, and tag rules.
4. Create a tt-rss filter and add the **Generate Jev Tags** action.

Tag rules use one line per tag. A definition is optional:

```text
technology | Software, hardware, cybersecurity, and the technology industry
business | Companies, markets, management, and commercial activity
science | Scientific research and discoveries
```

The plugin sends one synchronous `/v1/systemone` request per matching article. Every tag is an independent Noul question in that request, and tags whose `noul` value meets the configured threshold are added to the article.

## At-most-once behavior

Before calling TypeSafe, the plugin inserts the article GUID and owner into `ttrss_jev_tag_attempts`. The primary key prevents another filter execution or feed refresh from calling the API for that article again. Failed requests are recorded and are not retried automatically.

To deliberately allow an article to be evaluated again, delete its row from `ttrss_jev_tag_attempts` first.
