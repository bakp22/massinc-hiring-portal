# Project Requirements Mapping

Based on the project materials supplied for this repository.

## Core product

The portal is intended to replace an email-based application intake process with a structured application portal integrated into the existing WordPress site.

## MVP feature areas

### Applicant side

- Application portal integrated into existing careers page
- Open job/internship listings
- Direct application submission
- Resume upload
- Cover letter upload
- Application status visibility where appropriate
- Applicant email notifications

### Internal staff side

- View applicants
- Access submitted materials
- Route/assign applications to appropriate staff
- Manage application status
- Centralize application handling

## Maintainability

The codebase is intentionally divided into:

- bootstrap
- database
- models
- admin
- API
- blocks

This mirrors the separation used by the MassINC-Blocks reference while creating clear ownership boundaries for the hiring domain.

## Scope note

The supplied agreement describes the required product features at a high level. It does not specify the exact database schema, authentication design, status taxonomy, retention policy, file-storage architecture, or final UI. Those items should therefore be treated as implementation decisions requiring discovery/approval rather than assumptions.
