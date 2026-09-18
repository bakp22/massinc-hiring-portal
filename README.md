# MassINC Hiring Portal — Developer Onboarding Guide

Welcome to the MassINC Hiring Portal plugin repository! This guide contains setup instructions, code architecture overview, engineering task assignments, and our Git workflow.

---

## 1. Environment Setup (`wp-env`)

All database migrations, REST API endpoints, and Gutenberg block assets run inside isolated local Docker containers managed by `@wordpress/env`.

### System Prerequisites
Ensure you have the following installed on your machine before beginning:
* **Node.js:** v18+ and `npm`
* **Docker Engine & Docker Desktop:** Installed and actively running
* **Git**
* **Local WordPress Environment:** Managed automatically via `@wordpress/env` (Docker)

---

### Quickstart Setup

1. **Clone & Checkout Main:**
   ```bash
   git clone git@github.com:bakp22/massinc-hiring-portal.git
   cd massinc-hiring-portal
   git checkout main
   git pull origin main
   ```

2. **Install Dependencies & Build Assets:**
   ```bash
   npm install
   npm run build
   ```

3. **Launch Local WordPress Instance:**
   ```bash
   npx @wordpress/env start
   ```
   * **Site Front-End:** `http://localhost:8888`
   * **WP Admin:** `http://localhost:8888/wp-admin` *(Username: `admin`, Password: `password`)*
   * **Database Access:** Port `3306` via `wp-env`

4. **Useful Environment Commands:**
   | Action | Command |
   | :--- | :--- |
   | **Start Instance** | `npx @wordpress/env start` |
   | **Stop Instance** | `npx @wordpress/env stop` |
   | **Hard Reset DB** | `npx @wordpress/env clean --all` |
   | **Run WP-CLI** | `npx @wordpress/env cli <command>` |
   | **Query MySQL DB** | `npx @wordpress/env cli db query "SHOW TABLES LIKE 'wp_massinc%';" ` |
   | **Watch Block Build** | `npm run start` |

---

## 2. Code Architecture Overview

```text
massinc-hiring-portal/
├── docs/                   # DB schema specs & architecture notes
├── inc/
│   ├── database.php        # Custom table creation & migration hooks (Engineer 1)
│   ├── api.php             # REST API routes & controllers (Engineer 2)
│   └── Models/             # $wpdb database query models (Engineer 1)
└── src/
    ├── application-form/   # Candidate application form block (Engineer 2)
    └── job-list/           # Dynamic job listings block (Engineer 2)
```

---


## 3. Git Workflow & PR Submission

### Step 1: Update Main & Create a Feature Branch
Create a separate feature branch for **each** individual task or feature you work on, branching directly off the latest `main`:

```bash
# Ensure local main is up to date
git checkout main
git pull origin main

# Create and switch to a dedicated branch for your specific feature
git checkout -b feature/your-feature-name
```

### Step 2: Code & Security Standards
1. **SQL Injection Security:** Wrap all raw queries with `$wpdb->prepare()`.
2. **Input Sanitization:** Process request inputs through `sanitize_text_field()`, `sanitize_email()`, or `absint()`.
3. **Output Escaping:** Escape PHP values rendered in templates using `esc_html()`, `esc_attr()`, or `esc_url()`.

### Step 3: Commit & Push
If you modify block assets inside `src/`, compile build files before staging:

```bash
npm run build
git add .
git commit -m "feat: description of implemented feature"
git push -u origin feature/your-feature-name
```

### Step 4: Submit Pull Request to Main
1. Go to the repository on GitHub.
2. Open a Pull Request setting **Base Branch:** `main` $\leftarrow$ **Compare Branch:** `feature/your-feature-name`.
3. Fill out the PR summary detailing completed items and test results, then tag a team member for code review.
4. Once approved, merge the PR directly into `main`.
