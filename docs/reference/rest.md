# REST API

All routes are under `/wp-json/wire-desk/v1/`. Reading and acting on wire posts needs the `wire_desk_review` capability (granted from `edit_others_posts` by default), and each post also needs `edit_post`.

## TOC

- [TOC](#toc)
- [GET /posts](#get-posts)
- [GET /posts/{id}](#get-postsid)
- [POST /posts/{id}/{action}](#post-postsidaction)
- [Wire post fields](#wire-post-fields)

## `GET /posts`

Lists wire posts, newest first.

### Parameters

- `state`: `queued`, `scored`, `failed`, `dismissed` or `published`. Leave it out for every state.
- `band`: `high`, `medium`, `low` or `not-scored`.
- `per_page`: 1–100, default 20.
- `before`: a post ID. Returns posts older than it.
- `after`: a post ID. Returns posts newer than it.
- `context`: `view` (default) or `edit`. `edit` adds `cost` and `attempts`.

Pages are linked from the first and last posts returned, not by page number. The response has a `Link` header with `rel="next"` (older posts) and `rel="prev"` (newer posts) when there are more; follow those URLs to page.

### Example

```bash
curl -u editor:application-password 'https://example.com/wp-json/wire-desk/v1/posts?state=scored&band=high&per_page=10'
```

## `GET /posts/{id}`

Gets one wire post. Returns 404 when the post isn't in Wire Desk or no longer exists.

## `POST /posts/{id}/{action}`

Runs an action and returns the updated wire post. `{action}` is one of:

- `publish`: publishes the post (needs `publish_post`). A post dated in the future is scheduled.
- `dismiss`: removes the post from the review queue and cancels its waiting scoring job.
- `restore`: puts a dismissed post back in the queue.
- `rescore`: schedules the post to be scored again. An unchanged post isn't sent to the model.

Errors keep their status: 403 (`wire_desk_forbidden`), 404 (`wire_desk_not_found`), 409 (`wire_desk_published`, `wire_desk_not_dismissed`) and 500 (`wire_desk_db_error`).

## Wire post fields

| Field         | Type          | Description                                                                                                                                                           |
| ------------- | ------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `id`          | integer       | Post ID.                                                                                                                                                              |
| `title`       | string        | Post title, rendered.                                                                                                                                                 |
| `post_status` | string\|null  | Post status, or null when the post is gone.                                                                                                                           |
| `edit_link`   | string\|null  | Link to edit the post, when the user can.                                                                                                                             |
| `date`        | string        | Post date, in the site's timezone.                                                                                                                                    |
| `state`       | string        | `queued`, `scored`, `failed`, `dismissed` or `published`.                                                                                                             |
| `band`        | string        | `high`, `medium`, `low` or `not-scored`.                                                                                                                              |
| `score`       | integer\|null | Score from 0 to 100, or null before the post is scored.                                                                                                               |
| `model`       | string        | Model that scored the post.                                                                                                                                           |
| `cost`        | number        | Cost of the last paid score, in US dollars. `edit` context only.                                                                                                      |
| `scored_at`   | string\|null  | When the post was last scored, in GMT.                                                                                                                                |
| `error`       | string        | Last scoring error.                                                                                                                                                   |
| `attempts`    | integer       | Failed scoring attempts in a row. `edit` context only.                                                                                                                |
| `answers`     | array\|null   | Each question with `id`, `question`, `expected`, `weight`, `required`, `yes` (the model's probability of yes, 0–1) and `met`. Null when the answers have been pruned. |
| `actions`     | string[]      | Actions the current user can take on the post.                                                                                                                        |
