---
paths:
  - '**'
---

# General

## Never read .env files
Never read, open, cat, dump, or otherwise inspect .env or .env.* files. Use php artisan config:show, committed config files, or ask the user for needed values.

## Never run migrations
Never execute php artisan migrate, migrate:fresh, migrate:refresh, migrate:rollback, db:wipe, or equivalent Docker/Compose migrate commands. Creating or editing migration files is allowed when the task requires it; only running them is forbidden.
