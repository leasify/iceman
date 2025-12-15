# Claude Code Instructions for Iceman

## Project Overview
Iceman is a Laravel Zero CLI tool for Leasify. It handles deployment, database synchronization, and pipeline tasks.

The main application codebase is in `../app` (a separate Laravel project). Iceman is a standalone CLI tool.

## Key Commands

| Command | Description |
|---------|-------------|
| `iceman pull prod --fresh` | Pull production database with company filtering |
| `iceman pull prod --fresh --company=45` | Include extra company ID |
| `iceman pull:sail prod` | Pull for Laravel Sail environment |
| `iceman db:update dev3` | Update dev environment from prod |
| `iceman env` | Check environment settings |

## Key Files

| File | Purpose |
|------|---------|
| `app/Commands/Pull.php` | Main pull command with --company flag |
| `app/Commands/PullSail.php` | Pull command for Sail/Docker |
| `app/Commands/DbUpdate.php` | Update dev databases from prod |
| `app/Commands/Env.php` | Environment matching (hosts, databases, paths) |
| `app/Helpers/SelectiveDatabaseExport.php` | Selective export logic with company filtering |
| `app/Helpers/Environment.php` | .env file loading |

## Selective Database Pull - How It Works

The `pull` command filters data by `company_id` to reduce local database size.

**Default company IDs**: 1, 13, 32

**Filtering logic** (in `SelectiveDatabaseExport.php`):
1. Tables with `company_id` column → `WHERE company_id IN (...)`
2. Tables with `ifrs_setting_id` → JOIN to `ifrs_settings` table for company filtering
3. Tables with `ifrs_settings_id` → JOIN to `ifrs_settings` table for company filtering
4. Tables without these columns → fetched in full
5. Tables in `$excludedTables` array → always fetched in full

## Common Tasks

### Adding Tables to Full Fetch (No Company Filtering)

Edit `app/Helpers/SelectiveDatabaseExport.php`, find the `$excludedTables` array:

```php
protected array $excludedTables = [
    'migrations',
    'password_resets',
    'password_reset_tokens',
    'failed_jobs',
    'sessions',
    'cache',
    'cache_locks',
    'jobs',
    'job_batches',
    'personal_access_tokens',
    // Add new tables here:
    'new_table_name',
];
```

Tables starting with `telescope_` are automatically excluded.

### Releasing a New Version

```bash
php iceman app:build iceman --build-version=X.Y.Z
git add .
git commit -m 'description'
git tag X.Y.Z
git push origin branch-name --tags
```

Then users run `composer global update` to get the new version.

## Environment Configuration

Defined in `app/Commands/Env.php`:

| Environment | Host | Database | Path |
|-------------|------|----------|------|
| prod/production | forge@16.16.179.110 | production | /home/forge/app.leasify.se |
| develop | forge@13.49.49.40 | develop | /home/forge/develop.leasify.se |
| dev1-3 | forge@13.49.49.40 | dev1/dev2/dev3 | /home/forge/devX.leasify.se |

## Technical Notes

- SSH uses `forge` user with key authentication
- PostgreSQL database accessed via `psql`
- Uses `\copy` command (not COPY) to avoid superuser requirement
- Schema dumped with `pg_dump --schema-only`
- Data exported per-table with filtered SELECT queries
- Import uses `COPY FROM stdin` format
- Email safety: prod emails get `.cc` suffix after import

## Testing Without Release

Run source directly:
```bash
cd /path/to/app
php /path/to/iceman/iceman pull prod --fresh
```
