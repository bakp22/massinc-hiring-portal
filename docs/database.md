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
- name
- email
- phone
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
