# Jev Article Automation

Background processor that applies labels, ranks articles, and optionally marks them read with TypeSafe Jev.

## Setup

1. Enable `jev_auto_tag` in **Preferences > Plugins**.
2. Open **Preferences > Feeds > Jev Article Automation**.
3. Enter the TypeSafe API key and model, then use **Test API Key** to verify them.
4. Configure the article text limit and label threshold.
5. If using labels, add rules with an existing tt-rss label and a focused yes/no question.
6. Expand each feed you want Jev to process and enable labeling, ranking, or automatic read state.
7. Remove any old Jev actions from tt-rss filters; the plugin no longer registers that action.

Example rules:

| Label name | Yes/no question |
| --- | --- |
| technology | Is the article primarily about software, hardware, or cybersecurity? |
| business | Is the article primarily about companies, markets, or commercial activity? |
| science | Is the article primarily about scientific research or discoveries? |

Both fields are required. Rules store the label ID, so renaming a label requires no rule changes. If a label is deleted, its rule must be assigned a replacement before settings can be saved. The selected label is applied when the Noul yes-probability for its question meets the configured threshold. Use the X button to remove a rule.

The article body is converted to plain text before it is truncated. The title and first 400 body characters are sent as separate fields in a structured state.

## Per-feed decisions

Each feed starts collapsed. Enable any combination of these decisions:

- **Labeling:** Enable labeling for this feed to apply the shared label questions and threshold above.
- **Article ranking:** Enter a Score question and 2–10 ordered rubric levels, one per line from lowest to highest. Jev's probability-weighted result is normalized to a native tt-rss score from 0 to 1000.
- **Automatic read state:** Enter one yes/no Noul question and a threshold from 0 to 1. The article is marked read when the returned yes-probability meets the threshold.

When a feed is enabled, the plugin queues new or updated articles automatically. Its background worker sends all enabled questions in one `/v1/systemone` request and updates the stored article. Disabled decisions add no questions. No tt-rss filter action is needed.

Auto-read works on its own: leave label rules empty, leave labeling and ranking disabled, and enable only automatic read state for the feed. Saving settings does not evaluate the existing backlog; unchanged articles are skipped by the tt-rss updater.

The Docker image runs the Jev worker through Supervisor. Rebuild and deploy the image to install this version; saving preferences alone does not install the worker. To inspect decisions, use `docker logs <container> 2>&1 | grep 'Jev_Auto_Tag:'`. Successful evaluations log `auto_read`, the probability, the threshold, and `marked_read` (the number of unread articles actually changed).

## Retries and cached results

Successful evaluations are cached by article GUID and user in `ttrss_jev_label_attempts`. Changes to the title, body, content hash, model, or rules never trigger another evaluation after success.

If both the title and HTML-stripped body are empty or contain only whitespace, the plugin makes no API call and records no attempt. A later import with meaningful text can trigger the first evaluation. A title alone or body alone is sufficient.

Failed evaluations remain queued and retry after 1, 2, 4, 8, 16, and 32 minutes, then hourly until they succeed or the feed decisions are disabled. Each failure and scheduled retry is logged. An interrupted processing attempt can be reclaimed after 10 minutes. Article changes and the success record commit together, so retrying a failed transaction does not apply its score twice.

An API timeout can occur after the service has processed a request, so a retry may repeat the remote evaluation. The local database changes are still applied only on success.

To deliberately allow an article to be evaluated again, delete its row from `ttrss_jev_label_attempts` first.
