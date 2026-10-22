# `wp-config.php` Constants

| Constant                            | Description                                                                                                                                                                    |
| ----------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| `AI_POST_TRIAGE_ENCRYPTION_KEY`     | Encrypts the OpenRouter API key saved on the settings screen. Defaults to `LOGGED_IN_KEY`. If it changes, save the key again.                                                  |
| `AI_POST_TRIAGE_OPENROUTER_API_KEY` | OpenRouter API key. Takes precedence over the key saved on the AI Post Triage settings screen and the one saved for the OpenRouter connector, and disables the settings field. |
