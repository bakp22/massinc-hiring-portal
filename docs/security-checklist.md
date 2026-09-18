# Production Security Checklist

Before handling real applications:

- [ ] HTTPS enabled
- [ ] Public submission uses request verification/nonce strategy
- [ ] Rate limiting / anti-spam added
- [ ] Resume MIME/type/size validation
- [ ] Cover-letter MIME/type/size validation
- [ ] Uploaded files are not publicly guessable
- [ ] Download authorization is enforced
- [ ] Recruiter permissions are enforced server-side
- [ ] Applicants can access only their own application data
- [ ] SQL uses `$wpdb->prepare()` for user-controlled values
- [ ] Output is escaped appropriately
- [ ] CSRF protection added to admin actions
- [ ] Audit logging added for status/assignment changes
- [ ] Sensitive data retention policy documented
- [ ] Deletion/export workflow documented
- [ ] Backups tested
- [ ] Error messages do not leak applicant information
- [ ] Dependency versions are reviewed
- [ ] Code review completed
- [ ] Security testing completed
