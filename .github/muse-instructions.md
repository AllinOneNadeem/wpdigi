# Copilot Instructions — wpdigi

## Project
WordPress plugin: "Gemini AI Site & Store Manager" (v3.0.0) — a single-file plugin
in `gemini-ai-site-manager.php`. It adds Gemini AI features for WooCommerce
(product generator, live preview), an SEO auditor, RankMath grid modules,
1-click restore and a GitHub updater.

## Coding rules
- Follow WordPress coding standards and the plugin's existing style.
- Always guard with the ABSPATH check pattern already used in the file.
- Escape output (esc_html / esc_attr / wp_kses) and sanitize input; use nonces
  and capability checks for every admin action or AJAX handler.
- Keep changes small and focused on the assigned issue; this is a production
  plugin, so avoid restructuring the main class unless the issue requires it.
- Never hardcode API keys or secrets in the code.

## Testing
- Run PHP syntax check after every change: `php -l gemini-ai-site-manager.php`
