# Inquira — handwritten student inquiry workspace

Laravel 13 / PHP 8.3+, MySQL or SQLite, Blade, a responsive custom interface, database queues, and server-side Gemini multimodal requests. No fabricated OCR results or example student records are seeded.

## Quick start

```sh
composer install
cp .env.example .env
php artisan key:generate
# Configure the database and Gemini key in .env before continuing.
php artisan migrate
php artisan inquira:user admin@example.com "Admissions Administrator"
# The command prompts for a password; no default credentials are installed.
npm ci
npm run build
php artisan serve
```

In another terminal:

On Windows/Laragon you can instead run `powershell -File scripts/start-worker.ps1`. This starts a hidden local queue listener, prevents duplicate listeners started by that script, and runs processing jobs in the background. After changing `.env`, run `powershell -File scripts/start-worker.ps1 -Restart` because child processes inherit the listener environment. Keep the API key in `.env`; uploads and retries now report missing configuration immediately. Pending statuses refresh automatically, and processing failures display their message in the queue.

```sh
php artisan queue:work --timeout=3600 --tries=2
```

Open http://127.0.0.1:8000. The public shell contains no student data; sign in to upload and review. Accounts are isolated by document owner. Administrators can create users, configure model and retention, and add template crop anchors through the UI.

On this Laragon machine, PHP is at `F:\laragon\laragon\bin\php\php-8.4.4-Win32-vs17-x64\php.exe` and Composer is at `F:\laragon\laragon\bin\composer\composer.phar`. Add the PHP directory to PATH for these commands.

## Database

The local installation uses an initialized SQLite database so the UI runs immediately. For MySQL set:

```dotenv
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=chit_reader
DB_USERNAME=your_user
DB_PASSWORD=your_password
QUEUE_CONNECTION=database
DB_QUEUE_RETRY_AFTER=3660
```

Create the database, run migrations, then create the administrator in that database. Migrations use Laravel's database abstractions; the automated suite runs against SQLite, not a live MySQL server.

## Gemini configuration

Create a server API key in Google AI Studio, enable billing/quotas as needed, and set:

```dotenv
GEMINI_API_KEY=your_private_key
GEMINI_MODEL=gemini-2.5-flash
```

Choose a supported image-capable model accessible to your project. Model availability depends on the account and can change. The administrator can override the model in Settings. Run `php artisan config:clear` and `php artisan queue:restart` after environment changes. Never place keys in frontend assets or commit `.env`.

Integration uses the documented [Gemini generateContent REST endpoint](https://ai.google.dev/api/generate-content) with inline image parts and [structured output](https://ai.google.dev/gemini-api/docs/generate-content/structured-output). It performs image quality assessment, a complete-form pass, an independent verification pass, and field crop passes when bounding boxes or template anchors are available. Crops preserve context and original images remain unchanged. Disagreements require human resolution. Model readings are never represented as calibrated accuracy scores.

Optional secondary providers:

```dotenv
# Empty disables secondary OCR; alternatives: vision or document_ai
OCR_SECONDARY_PROVIDER=vision
GOOGLE_VISION_API_KEY=your_restricted_cloud_key
# For document_ai:
GOOGLE_CLOUD_PROJECT_ID=your_project
GOOGLE_CLOUD_LOCATION=us
DOCUMENT_AI_PROCESSOR_ID=your_processor
GOOGLE_CLOUD_ACCESS_TOKEN=your_oauth_access_token
```

Cloud APIs require separate enablement, permissions, and billing. Document AI access tokens expire and must be refreshed by deployment credential automation. See [Vision handwriting OCR](https://docs.cloud.google.com/vision/docs/handwriting) and [Document AI processing](https://docs.cloud.google.com/document-ai/docs/send-request). Secondary text comparison is deliberately conservative; absence of matching text flags conflict and does not select either provider's value as correct.

## Review and storage

Upload JPG, PNG, or WEBP images (up to 20 per batch, 15 MB each). Original files are stored in `storage/app/private/documents`. Generated orientation-corrected copies and crops use `storage/app/private/processed`. Image access requires authentication and document ownership. Do not expose the private storage directory or make a public symlink to it.

All 11 inquiry fields have independent database columns. Raw readings, independent readings, crop evidence, secondary responses, quality findings, and review decisions are retained. A reviewer must resolve unreadable fields before approval. Draft records are excluded from CSV and native XLSX exports. Excel cells are strings; CSV formula prefixes are escaped. Review and administrative actions create audit entries.

## Validation

```sh
php artisan test
php vendor/bin/pint --test
node scripts/check-ui.mjs
```

The browser check uses the locally installed Chromium path; change `executablePath` on another machine or install Playwright Chromium and omit that option. UI screenshots are saved in `artifacts/`.

The backend tests cover access controls, queued/private upload, malformed provider output, conflicting phone digits, independent WhatsApp blanks, approval persistence, and blocking unresolved approval. Provider responses in tests are mocked. Live provider credentials and reference photographs were not supplied, so extraction accuracy is not yet measured against real handwritten forms.

Evaluate human-confirmed data as field-keyed JSON:

```sh
php artisan inquira:evaluate ground-truth.json predictions.json
```

This reports field exact match, character error rate and word error rate. It does not assert field assignment accuracy, false acceptance, or measured provider cost. A representative labeled form dataset is needed before claiming operational accuracy. Preprocessing corrects EXIF rotation and makes lossless PNG copies; it does not implement automatic perspective rectification or deskew. Poor images are rejected for recapture instead of aggressively altering handwriting.

## Production deployment

Point the web server document root to `public/`. Require HTTPS, set `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL` correctly, `SESSION_SECURE_COOKIE=true`, and keep private storage and database backups access-controlled. Enable PHP GD, EXIF, fileinfo, mbstring, PDO MySQL, and ZIP. Set `upload_max_filesize=16M`, `post_max_size=320M`, `max_file_uploads=20`, and a suitable memory limit; configure matching web-server limits.

Run `php artisan migrate --force`, `php artisan config:cache`, and `php artisan view:cache`. Supervise queue workers with a timeout of 3600 seconds and retry_after longer than 3600 seconds. Add `* * * * * php /path/to/artisan schedule:run` to cron. Retention is disabled by default; setting days in Settings enables scheduled permanent removal of expired documents and linked records. Arrange consent, retention, backups, and provider processing terms for your institution before handling real student data.

The delivered app includes the core processing/review workflow, administrator controls, template anchors, search, and CSV/XLSX exports. Advanced analytics, calibrated accuracy reporting, arbitrary custom database field types, automatic perspective correction, and a fully managed Google credential refresh service are not implemented.

