# Email Notifications (Twilio SendGrid)

The MassINC Hiring Portal sends transactional emails through **Twilio SendGrid's v3 Mail Send API** (`https://api.sendgrid.com/v3/mail/send`).

By default, two email triggers fire automatically whenever a new application is created (`massinc_application_submitted`):

1. **`application_submitted_applicant`** — Confirmation email sent to the applicant.
2. **`application_submitted_staff`** — Notification email sent to MassINC hiring staff.

---

## 1. Setup & Environment Variables

API keys and sender settings are loaded strictly from environment variables (or a local `.env` file during development) and are **never stored in source code**.

Copy `.env.example` to `.env` in the plugin root (or configure your hosting environment / container variables):

```dotenv
# Required: Twilio SendGrid API key with "Mail Send" permission
SENDGRID_API_KEY=SG.xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx

# Optional: Verified SendGrid Sender Identity (falls back to WordPress admin_email)
SENDGRID_FROM_EMAIL=no-reply@massinc.org
SENDGRID_FROM_NAME="MassINC Hiring Portal"

# Optional: Comma-separated staff notification recipients (falls back to WP Admin setting or admin_email)
MASSINC_STAFF_NOTIFICATION_EMAIL=hiring@massinc.org,hr@massinc.org
```

### SendGrid Account Checklist
1. Log in to [SendGrid](https://app.sendgrid.com/).
2. Verify your sender domain or single sender address matching `SENDGRID_FROM_EMAIL` under **Settings → Sender Authentication**.
3. Create a restricted API key with **Mail Send → Full Access** under **Settings → API Keys** and assign it to `SENDGRID_API_KEY`.

---

## 2. Editing Email Templates Without Code Changes

Email templates can be edited at any time without modifying PHP code in two ways:

### Option A: WordPress Admin Template Editor
1. Log in to WordPress Admin with a user that has the `manage_minc_hiring` capability (Administrator or Hiring Manager).
2. Navigate to **Hiring → Email Notifications**.
3. For each trigger, you can:
   - Enable or disable the notification.
   - Edit the **Subject Line** and **Email Body (HTML)** stored in `wp_options` (`minc_email_templates`).
   - Use any of the supported merge placeholders:
     - `{{applicant_name}}` — Full name of the candidate
     - `{{first_name}}` / `{{last_name}}` — Candidate first or last name
     - `{{candidate_email}}` / `{{candidate_phone}}` — Candidate contact details
     - `{{job_title}}` / `{{job_location}}` / `{{employment_type}}` — Job details
     - `{{application_id}}` / `{{submitted_at}}` / `{{status}}` — Application metadata
     - `{{admin_applications_url}}` — Direct link to the WP Admin Applications page

### Option B: SendGrid Dynamic Templates
If your team prefers designing emails inside SendGrid's visual editor:
1. Create a Dynamic Template in the SendGrid dashboard and copy its Template ID (e.g., `d-xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx`).
2. Paste the Template ID into the **SendGrid Dynamic Template ID** field under **Hiring → Email Notifications**.
3. When set, the plugin passes `template_id` and all merge variables via `dynamic_template_data` to SendGrid.

---

## 3. Delivery Logging & Retry Handling

Every email delivery attempt is recorded in the `wp_massinc_notifications` table and logged to PHP `error_log` on failure:

- **Status lifecycle:** `pending` → `sent` (on HTTP `2xx`) or `retrying` → `failed`.
- **Automatic retries:** When a SendGrid request fails (network timeout, `5xx`, or rate limit), the plugin logs the error and schedules a WP-Cron single event (`massinc_retry_failed_email`) with exponential backoff:
  - Attempt 1 (initial send) fails → Retry #2 scheduled in **1 minute** (`60s`)
  - Attempt 2 fails → Retry #3 scheduled in **3 minutes** (`180s`)
  - Attempt 3 fails → Marked as **`failed`**
- **Manual retry:** Staff can inspect recent deliveries, view the exact error message, and click **Retry Now** under **Hiring → Email Notifications → Delivery Log & Retry Queue**.
- **Customizing retry behavior:**
  - Filter `massinc_email_max_retries` (default `3`)
  - Filter `massinc_email_retry_delay_seconds` (default `60 * 3^($attempt - 1)`)

---

## 4. How to Add New Email Triggers

Adding a new automated email trigger takes two steps:

### Step 1: Register the Trigger
Register your trigger using `\MassINC\Notifications::register_trigger()` (or the `massinc_email_triggers` filter). Once registered, its template automatically appears in **Hiring → Email Notifications** so staff can edit its subject, HTML body, or SendGrid Dynamic Template ID without future code changes.

```php
add_action('init', function () {
    \MassINC\Notifications::register_trigger('application_status_interview', [
        'label'          => 'Applicant Interview Invitation',
        'description'    => 'Sent to the applicant when their application status moves to interview.',
        'recipient_type' => 'applicant',
        'enabled'        => true,
        'subject'        => 'Interview Invitation: {{job_title}} — MassINC',
        'body'           => '<p>Hi {{first_name}},</p><p>We would like to invite you to interview for <strong>{{job_title}}</strong>.</p>',
    ]);
});
```

### Step 2: Dispatch the Trigger from a WordPress Action Hook
When the domain event occurs (for example, when an application's status is updated), build the variable context and call `\MassINC\Notifications::dispatch_trigger()`:

```php
add_action('massinc_application_status_changed', function ($application_id, $new_status) {
    if ($new_status !== 'interview') {
        return;
    }

    $context = \MassINC\Notifications::build_application_context($application_id);
    if (!$context) {
        return;
    }

    \MassINC\Notifications::dispatch_trigger(
        'application_status_interview',
        $context['candidate_email'],
        $context,
        (int) $application_id,
        $context['applicant_name']
    );
}, 10, 2);
```

---

## 5. Testing Without `wp-env`

You can run the standalone test suite in [`tests/test-notifications-standalone.php`](../tests/test-notifications-standalone.php) without starting `wp-env` or MySQL:

### Automated Mock Suite (No SendGrid API Key Required)
```bash
php tests/test-notifications-standalone.php
# Or via Docker:
docker run --rm -v "${PWD}:/app" -w /app php:8.2-cli php tests/test-notifications-standalone.php
```

### Live SendGrid Delivery Test
Set `SENDGRID_API_KEY` and `SENDGRID_FROM_EMAIL` in `.env`, then pass `--live` and `--to`:
```bash
php tests/test-notifications-standalone.php --live --to=your-email@example.com
```

