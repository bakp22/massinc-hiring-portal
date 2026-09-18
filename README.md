# MassINC Hiring Portal — WordPress Plugin 

A starter architecture for building a hiring application portal that integrates with an existing WordPress site.

The structure is intentionally modeled after the MassINC-Blocks example:

- `index.php` / plugin entry point loads functionality.
- `inc/` contains reusable PHP/backend code.
- `inc/Models/` contains domain models, following the `Model` / `Event` pattern from MassINC-Blocks.
- `src/<block>/` contains Gutenberg blocks with `block.json`, `index.js`, and server-side `render.php`.
- Custom database tables store jobs, candidates, and applications.
- A WordPress admin area gives internal staff a starting point for managing jobs/applications.
- A REST API exposes open jobs and accepts applications.

## Current scope

This is an MVP-oriented boilerplate, not a production-ready ATS.

Implemented:

- Custom tables for jobs, candidates, and applications
- Plugin activation/database setup
- Hiring Manager role/capabilities
- WordPress admin menu
- Basic job creation
- Application list
- `GET /wp-json/minc-hiring/v1/jobs`
- `POST /wp-json/minc-hiring/v1/applications`
- Gutenberg Job List block
- Gutenberg Application Form block
- Model pattern: `Model`, `Job`, `Candidate`, `Application`

Intentionally next:

- Resume upload
- Cover-letter upload
- Application detail page
- Application status updates
- Staff assignment/routing
- Applicant email notifications
- Automated internal email pipeline
- Applicant status tracking
- CSRF/nonces for public application submission
- Rate limiting / spam protection
- File type/size/security validation
- Audit logging
- Better admin UI
- Automated tests
- Production deployment documentation

## Architecture

```text
Applicant
   |
   v
WordPress page
   |
   +--> Gutenberg blocks
   |       |
   |       +--> job-list/render.php
   |       |
   |       +--> application-form/render.php
   |
   v
REST API
   |
   v
Hiring Plugin
   |
   +--> Models
   |
   +--> Permissions
   |
   +--> Database layer
   |
   v
MySQL / MariaDB
   |
   +--> wp_minc_jobs
   +--> wp_minc_candidates
   +--> wp_minc_applications
```

## Why custom tables?

WordPress posts/custom post types can work well for public job content. Applications are more transactional and relational:

```text
Job 1
  |
  +--- Application 101 --- Candidate 20
  |
  +--- Application 102 --- Candidate 31
```

For this starter, jobs and applications are stored in custom tables so the hiring domain is clearly separated from ordinary WordPress content.

This decision should be revisited during technical discovery.

## Local development

1. Install WordPress locally.
2. Copy/clone this directory into:

```text
wp-content/plugins/massinc-hiring-portal/
```

3. Activate **MassINC Hiring Portal** in WordPress.
4. Go to **Hiring → Jobs**.
5. Create an open job.
6. Add the **Hiring Job List** block to a page.
7. Add the **Hiring Application Form** block to a page and provide the Job ID.

## Important production considerations

Do not deploy this boilerplate unchanged for real applicant data.

Applicant resumes and cover letters may contain sensitive personal information. Before production, implement:

- strict authorization checks,
- protected file storage/access,
- upload MIME/type/size validation,
- nonces and/or other request verification,
- spam/rate limiting,
- secure error handling,
- audit logging,
- retention/deletion procedures,
- backups,
- least-privilege staff roles,
- HTTPS,
- testing and security review.

## Product mapping

The project requirements can map to the codebase as follows:

| Requirement | Boilerplate location |
|---|---|
| Application portal on existing careers page | `src/application-form/` |
| Upload application materials | Next: application submission/file service |
| Individual access to applications | Next: authenticated application/admin detail routes |
| Application status tracking | `wp_minc_applications.status` + admin workflow |
| Staff routing | `wp_minc_applications.assigned_to` |
| Applicant notifications | Next: notification service |
| Automated email pipeline | Next: notification/service layer |
| Maintainability/handoff | documented `inc/`, Models, API, blocks architecture |

## Suggested implementation order

1. Finish job CRUD.
2. Finish application submission.
3. Add secure resume/cover-letter uploads.
4. Build application detail view.
5. Add status workflow.
6. Add staff assignment/routing.
7. Add applicant/internal notifications.
8. Add tests and security review.
9. Document deployment/handoff.

## Relationship to MassINC-Blocks

The goal is not to copy MassINC-Blocks blindly. The useful architectural pattern is:

```text
MassINC-Blocks:
WordPress
  -> plugin entry point
  -> inc/
  -> Models/
  -> block.json
  -> index.js
  -> render.php

Hiring Portal:
WordPress
  -> plugin entry point
  -> inc/
  -> Models/
  -> database layer
  -> admin
  -> REST API
  -> block.json
  -> index.js
  -> render.php
```

The hiring portal therefore uses the same general WordPress/plugin/block organization while adding a domain-specific backend and database layer.
