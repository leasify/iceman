# ICEMAN

Internal tool for Leasify for deployment and pipeline.

** WORK IN PROGRESS **

Commands:
* `env` to check/read your environment settings
* `feature {AP-branch}` creates a new Laravel Forge site in our AWS environment
* `pull {environment}` eg, `iceman pull prod` gets the database and local files from the production environment at Cloudnet.
  * `--fresh` forces a new database dump (otherwise uses cached)
  * `--company=45,67` adds extra company IDs to fetch (default: 1,13,32)
* `pull:sail {environment}` eg, `iceman pull:sail prod` gets the database and local files from the production environment at Cloudnet to your Sail environment.

## Selective Pull - Company Filtering

The `pull` command filters data by `company_id` to reduce database size. By default it fetches companies 1, 13, and 32.

**How filtering works:**
- Tables with `company_id` column: filtered by `WHERE company_id IN (...)`
- Tables with `ifrs_setting_id` or `ifrs_settings_id`: filtered via JOIN to `ifrs_settings`
- Tables without these columns: fetched in full

**Tables always fetched in full (no filtering):**

These are defined in `app/Helpers/SelectiveDatabaseExport.php` in the `$excludedTables` array:

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
];
```

To add more tables that should be fetched in full, add them to this array.

## Other Commands

* `db:update {environment}` eg, `iceman db:update dev3` gets the database from the production environment and updates dev3 database.

## FORGE API
Please ensure that the env key FORGE_API is set, eg:
`export FORGE_API=eyJ0eXAiOiJK`

## Upgrades
* `php iceman app:build iceman` (set version number eg, 1.0.10)
* `git add .`
* `git commit -m 'upgrade'`
* `git tag 1.0.10`
* `git push --tags`
* `composer global update`

## Cloudnet
You need to have your SSH-key installed at Cloudnet servers. Please contact them at support@cloudnet.se to get access for your local environment.

## License
Iceman is an open-source software licensed under the MIT license.
