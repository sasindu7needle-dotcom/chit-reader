# Inquira

A Laravel workspace for digitizing handwritten student inquiry forms with server-side Gemini recognition and human verification.

See [INSTALL.md](INSTALL.md) for setup, API configuration, deployment, tests, and implementation limits.

Run locally: `php artisan serve`, then open http://127.0.0.1:8000. Create the first administrator at `/login` in local development, configure `GEMINI_API_KEY`, and run `php artisan queue:work --timeout=3600`.
