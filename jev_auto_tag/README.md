# Jev Article Automation

Synchronous tt-rss filter action that applies labels, ranks articles, and optionally marks them read with TypeSafe Jev.

## Setup

1. Enable `jev_auto_tag` in **Preferences > Plugins**.
2. Open **Preferences > Feeds > Jev Article Automation**.
3. Enter the TypeSafe API key and model, then use **Test API Key** to verify them.
4. Configure the article text limit and Noul threshold.
5. Click **Add Label Rule**, choose an existing tt-rss label, and enter its focused yes/no question.
6. Optionally configure ranking and automatic read-state decisions for individual feeds.
7. Create a tt-rss filter and add the **Apply Jev Article Decisions** action.

Example rules:

| Label name | Yes/no question |
| --- | --- |
| technology | Is the article primarily about software, hardware, or cybersecurity? |
| business | Is the article primarily about companies, markets, or commercial activity? |
| science | Is the article primarily about scientific research or discoveries? |

Both fields are required. Rules store the label ID, so renaming a label requires no rule changes. If a label is deleted, its rule must be assigned a replacement before settings can be saved. The selected label is applied when the Noul yes-probability for its question meets the configured threshold. Use the X button to remove a rule.

The article body is converted to plain text before it is truncated. The title and first 400 body characters are sent as separate fields in a structured state.

## Per-feed decisions

Each feed starts collapsed and can independently enable either or both decisions below. Expanding a feed shows only the enabled decision fields; checking a disabled decision reveals its fields immediately:

- **Article ranking:** Enter a Score question and 2–10 ordered rubric levels, one per line from lowest to highest. Jev's probability-weighted result is normalized to a native tt-rss score from 0 to 1000.
- **Automatic read state:** Enter one yes/no Noul question and a threshold from 0 to 1. The article is marked read when the returned yes-probability meets the threshold.

These settings only run on articles that match a tt-rss filter using the **Apply Jev Article Decisions** action. Disabled decisions add no questions. Label, ranking, and auto-read questions are combined into one synchronous `/v1/systemone` request per matching article.

## At-most-once behavior

Before calling TypeSafe, the plugin inserts the article GUID and owner into `ttrss_jev_label_attempts`. The primary key prevents another filter execution or feed refresh from calling the API for that article again. Failed requests are recorded and are not retried automatically.

To deliberately allow an article to be evaluated again, delete its row from `ttrss_jev_label_attempts` first.
