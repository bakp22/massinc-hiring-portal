# Architecture Notes

## Layers

### Plugin bootstrap
`massinc-hiring-portal.php`

Loads the backend modules and registers WordPress hooks.

### Database
`inc/database.php`

Owns creation/versioning of the custom tables.

### Domain models
`inc/Models/`

Follows the pattern used in MassINC-Blocks:

```text
Model
  |
  +-- Job
  +-- Candidate
  +-- Application
```

Models should contain domain-oriented behavior rather than WordPress page rendering.

### Admin
`inc/admin.php`

Internal staff interface. This should eventually become a full application management portal.

### REST API
`inc/api.php`

The API is the boundary between frontend interactions and backend operations.

### Blocks
`src/`

Each block follows the MassINC-Blocks convention:

```text
block/
  block.json
  index.js
  render.php
```

Dynamic blocks use PHP `render.php`, which is useful when the output depends on current database state.

## Planned application lifecycle

```text
submitted
    |
    v
screening
    |
    +--> rejected
    |
    v
interview
    |
    +--> rejected
    |
    v
offer
    |
    v
hired
```

The exact workflow should be confirmed with MassINC before implementation.

## Planned routing

`assigned_to` is a WordPress user ID.

Eventually:

```text
Application
    |
    +--> assigned_to = Staff User
    |
    v
Staff dashboard
```

Staff permissions should determine which applications each person can access.
