=== Wire Desk ===
Contributors:      jdeepd
Tags:              news, wire, newsroom, editorial, ai
License:           GPLv3 or later
License URI:       https://www.gnu.org/licenses/gpl-3.0.html
Requires PHP:      8.2
Requires at least: 7.1
Tested up to:      7.1
Stable tag:        0.1.0

Flags high-priority wire-ingested posts with a decision model and gives editors a review queue to decide what gets published.

== Description ==

Newsrooms that ingest wire copy get far more posts than editors can read. Wire Desk asks a decision model a few yes/no questions about each incoming wire post, such as "Is this breaking news?" or "Is this a routine listing?", turns the answers into a score, and sorts posts into High, Medium and Low. Editors work through a review queue and decide what gets published.

= Features =

* **Review queue** with views for posts that need review, are waiting, failed, published or dismissed, a priority filter, and a menu badge counting High posts. Publish, dismiss, restore or re-score from each row or in bulk.
* **Your own questions.** Up to 20 yes/no questions, each with an expected answer and a weight. A question can be required, so a post that misses it is always Low.
* **Editor panel** in the block editor showing the score, each question and how sure the model was, with a Re-score button.
* **Wire content by post type or term**, from any taxonomy, including custom ones. Existing posts can be added from the post list.
* **Background scoring** with Action Scheduler, retries with backoff, a pause after repeated failures, a daily budget, and a cap on jobs at once. Posts that haven't changed are never sent to the model twice.
* **REST API and WP-CLI** commands for scoring, re-scoring, backfilling, stats, pruning and health checks.
* **Built for scale.** Wire Desk keeps its data in its own indexed table, not post meta, and has been checked against a million wire posts.

= Disclaimer =

Wire Desk sends the parts of each post you choose (the headline by default) to a third-party decision model through OpenRouter, using your own API key. You are responsible for making sure your wire agency agreements and data policies allow this. Wire Desk only suggests priorities; editors always decide what gets published.

== Installation ==

1. Install and activate Wire Desk.
2. Go to **Wire Desk > Settings**.
3. Save your OpenRouter API key, or define `WIRE_DESK_OPENROUTER_API_KEY` in `wp-config.php`.
4. Choose the post types or terms that mark wire content, and review the questions.
5. New wire posts are scored when they are saved. To add posts that already exist, use **Add to review queue** on the post list, or run `wp wire-desk backfill`.

== Frequently Asked Questions ==

= What does Wire Desk send to OpenRouter? =

The parts of each post you choose under **Model reads**: the headline only by default, or the headline with the excerpt or the body. The newsroom context, if you set one, is sent too. Nothing else about your site is sent.

= What does it cost? =

Each score is one paid request to OpenRouter, billed to your account. Today's spend is shown on the settings screen, and scoring stops for the day once your daily budget is reached. After you change weights, thresholds or which questions are required, re-scoring posts sends no new requests, because the saved answers are reused.

= Does Wire Desk publish posts on its own? =

No. It only suggests a priority. Posts are published when an editor chooses to.

= Who can use the review queue? =

Users who can edit others' posts, which by default means Editors and Administrators. Only Administrators can change the settings. Both can be changed with the `wire_desk/capability_map` filter.

= Is Wire Desk translated? =

Wire Desk includes Spanish and French translations, and its strings are in `languages/wire-desk.pot` for other languages. Translations from translate.wordpress.org are used automatically and take precedence.

== Screenshots ==

1. The review queue: wire posts waiting for an editor, with their priority, score, status and model. Publish, dismiss or re-score from each row or in bulk.
2. The Wire Desk panel in the block editor: each question, whether the model's answer met it, and how sure it was.
3. Adding existing posts to the review queue from the Posts screen, one at a time or in bulk.
4. Settings: choosing wire content by post type, or by terms from any taxonomy.
5. Settings: the yes/no questions, their weights, and the High and Medium thresholds.
6. Settings: the encrypted OpenRouter API key, the decision model, Try a headline, the daily budget and today's spend.

== Changelog ==

For the full changelog see <a href="https://github.com/JDeepD/wire-desk/blob/main/CHANGELOG.md" target="_blank">GitHub</a>.

== Upgrade Notice ==
