# Database Design

## Jobs

`wp_massinc_jobs`

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
- `required_fields`: JSON object with boolean `phone`, `resume` (CV / resume),
  `cover_letter`, `writing_samples`, and `sample_url` (links) requirements.
  Existing jobs with `NULL` retain the original default: resume required,
  all other configurable fields optional. Name and email remain required.
- timestamps

Administrators change requirements in the **Positions** tab of the Hiring
Candidate Dashboard block. Each switch saves through the authenticated
`PATCH /massinc/v1/jobs/{id}/requirements` endpoint. Staff cannot change these
settings. Only open positions appear in the toggle list. Successful changes save
silently; failed saves restore the previous switch value and show an error.

## Candidates

`wp_massinc_candidates`

Stores reusable applicant identity/contact information.

Important fields:

- `id`
- optional `wp_user_id`
- name (`first_name`, `last_name`)
- `email`
- `phone`
- timestamps

A person who applies again (same email or name) keeps one candidate record,
with a new application for each position.

Whether someone is a previous applicant isn't stored. The dashboard works it
out on each load (`previous_applicant` on each row from
`minc_hiring_get_candidates()`): a row is a previous applicant if the same
candidate has an earlier application at Offer or Rejected. Someone applying to
several positions while their applications are still in progress isn't one.

Previous applicants show an asterisk beside their name on the dashboard and
detail page. The detail page explains the marker below Notes.

The table still has an unused `is_past_applicant` column from an earlier
version. Nothing reads or writes it.

## Applications

`wp_massinc_applications`

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

Each application keeps its own files and links. Applying to another position
never changes the files on an earlier application.

WordPress **Hiring → Applications** lists these files and links per application.
File links use the authenticated download route; they do not expose stored paths
or add private candidate documents to the public Media Library.

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
