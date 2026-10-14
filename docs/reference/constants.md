# `wp-config.php` Constants

| Constant                       | Description                                                                                                                                                               |
| ------------------------------ | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `WIRE_DESK_ENCRYPTION_KEY`     | Encrypts the OpenRouter API key saved on the settings screen. Defaults to `LOGGED_IN_KEY`. If it changes, save the key again.                                             |
| `WIRE_DESK_OPENROUTER_API_KEY` | OpenRouter API key. Takes precedence over the key saved on the Wire Desk settings screen and the one saved for the OpenRouter connector, and disables the settings field. |
