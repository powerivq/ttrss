# Jev Auto Label

Synchronous tt-rss filter action that applies native tt-rss labels with TypeSafe Jev.

## Setup

1. Enable `jev_auto_tag` in **Preferences > Plugins**.
2. Open **Preferences > Feeds > Jev Auto Label Settings**.
3. Enter the TypeSafe API key and model, then use **Test API Key** to verify them.
4. Configure the article text limit and Noul threshold.
5. Click **Add Label Rule**, choose an existing tt-rss label, and enter its focused yes/no question.
6. Create a tt-rss filter and add the **Generate Jev Labels** action.

Example rules:

| Label name | Yes/no question |
| --- | --- |
| technology | Is the article primarily about software, hardware, or cybersecurity? |
| business | Is the article primarily about companies, markets, or commercial activity? |
| science | Is the article primarily about scientific research or discoveries? |

Both fields are required. Rules store the label ID, so renaming a label requires no rule changes. If a label is deleted, its rule must be assigned a replacement before settings can be saved. The selected label is applied when the Noul yes-probability for its question meets the configured threshold. Use the X button to remove a rule.

The article body is converted to plain text before it is truncated. The title and first 400 body characters are sent as separate fields in a structured state.

The plugin sends one synchronous `/v1/systemone` request per matching article. All label questions are evaluated independently in that request.

## At-most-once behavior

Before calling TypeSafe, the plugin inserts the article GUID and owner into `ttrss_jev_label_attempts`. The primary key prevents another filter execution or feed refresh from calling the API for that article again. Failed requests are recorded and are not retried automatically.

To deliberately allow an article to be evaluated again, delete its row from `ttrss_jev_label_attempts` first.
