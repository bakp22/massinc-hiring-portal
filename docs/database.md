# Database Design

## Jobs

`wp_minc_jobs`

Stores open/closed roles.

Important fields:

- `id`
- `title`
- `description`
- `location`
- `employment_type`
- `salary_min`
- `salary_max`
- `status`
- timestamps

## Candidates

`wp_minc_candidates`

Stores reusable applicant identity/contact information.

Important fields:

- `id`
- optional `wp_user_id`
- name (`first_name`, `last_name`)
- `email`
- `phone`
- `is_past_applicant` (flag set to `1` when a candidate has applied previously by email or name)
- timestamps

## Applications

`wp_minc_applications`

Connects a candidate to a job.

Important fields:

- `id`
- `job_id`
- `candidate_id`
- resume attachment
- cover letter attachment
- `status`
- `assigned_to`
- notes
- timestamps

## `massinc_application_attachments`

Files and links submitted with an application.

- `application_id`
- `kind`: `resume`, `cover_letter`, `writing_sample` or `link`
- `original_name`, `stored_name`, `mime_type`, `size_bytes` (files only)
- `url` (links only)
- `created_at`

Files are PDF or DOCX, up to 10 MB each, and are checked by content. They are
saved under a random name in `wp-content/uploads/massinc-hiring/`, which is
closed to direct requests. Staff download them through
`GET /massinc/v1/attachments/{id}/download`, which requires the
`view_minc_applications` capability.

## Ratings

`wp_massinc_ratings`

One staff rating (1 to 5) per reviewer per application. Staff share one sign-in, so the reviewer is a name picked on the page, stored as plain text in `reviewer_name`. It is not tied to the staff-name list, so removing a name from that list never changes past ratings.

A reviewer rating the same application again replaces their earlier score (the old row is updated, not kept): `application_id` + `reviewer_name` is unique, and the match ignores case.

Important fields:

- `id`
- `application_id`
- `reviewer_name`
- `score`
- timestamps (`updated_at` is when the score last changed)

## Notes

`wp_massinc_notes`

Free-text staff notes on an application. Append-only for now: there is no edit or delete. The author is a name picked on the page, stored as plain text in `author_name`, for the same reason as ratings.

Important fields:

- `id`
- `application_id`
- `author_name`
- `body`
- `created_at`

## Relationship

```text
jobs
  |
  | 1-to-many
  v
applications
  ^
  | many-to-1
  |
candidates
```

Foreign keys are intentionally represented by IDs rather than MySQL foreign-key constraints in this first version. The application layer is responsible for validating relationships.

## Future additions

Possible tables:

```text
minc_application_events
minc_interviews
minc_notifications
minc_audit_log
```

These should be added only when the product requirements justify them.
