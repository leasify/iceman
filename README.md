# ICEMAN

Internal tool for Leasify for deployment and pipeline.

## Commands

| Command | Description |
|---------|-------------|
| `iceman env` | Check/read your environment settings |
| `iceman pull {env}` | Pull database from remote environment to local |
| `iceman pull:sail {env}` | Pull database to Laravel Sail environment |
| `iceman feature {branch}` | Create a new Laravel Forge site in AWS |
| `iceman db:update {env}` | Update a dev environment database from prod |

## Pull Command

The `pull` command fetches a filtered subset of the production database to your local environment.

### Usage

```bash
# Basic usage (uses cached files if available)
iceman pull prod

# Force fresh database dump
iceman pull prod --fresh

# Include extra companies (adds to default 1,13,32)
iceman pull prod --fresh --company=45,67
```

### How It Works

```mermaid
flowchart TD
    A[iceman pull prod --fresh] --> B{Cached files exist?}
    B -->|No or --fresh| C[Connect via SSH]
    B -->|Yes| H[Use cached .gz files]

    C --> D[Analyze table structure]
    D --> E[Dump schema]
    E --> F[Export data in batches]
    F --> G[Compress to .gz]

    G --> H
    H --> I[Wipe local database]
    I --> J[Import schema]
    J --> K[Import data]
    K --> L{Is production?}
    L -->|Yes| M[Append .cc to emails]
    L -->|No| N[Clear cache]
    M --> N
    N --> O[Done]
```

### Selective Export Process

The export filters data by company to reduce database size. Default companies: **1, 13, 32**.

```mermaid
flowchart TD
    subgraph Analysis["1. Analyze Tables"]
        A1[Fetch all tables] --> A2[Categorize by columns]
        A2 --> A3[company_id?]
        A2 --> A4[user_id?]
        A2 --> A5[ifrs_setting_id?]
        A2 --> A6[ifrs_settings_id?]
    end

    subgraph Ordering["2. Sort by Dependencies"]
        B1[Tier 0: companies, users]
        B2[Tier 1: units, currencies, ifrs_settings...]
        B3[Tier 2: contracts, documents...]
        B4[Tier 3: contract_versions, comments...]
        B5[Tier 4: sign_emails...]
        B6[Tier 5: pivot tables]
        B7[Tier 6: polymorphic tables]
        B1 --> B2 --> B3 --> B4 --> B5 --> B6 --> B7
    end

    subgraph Export["3. Batched Export"]
        C1[Group 25 tables per batch]
        C2[Single SSH connection per batch]
        C3[Stream data to local file]
        C4[Show progress + ETA]
    end

    Analysis --> Ordering --> Export
```

### Filtering Logic

```mermaid
flowchart TD
    T[Table] --> S{Skipped?}
    S -->|Yes| SKIP[Skip entirely]
    S -->|No| E{Excluded?}

    E -->|Yes| FULL[SELECT * - fetch all]
    E -->|No| C{Has company_id?}

    C -->|Yes, is 'companies'| Q1["WHERE id IN (1,13,32)"]
    C -->|Yes, other table| Q2["WHERE company_id IN (1,13,32)"]
    C -->|No| U{Has user_id?}

    U -->|Yes| Q3["JOIN users WHERE company_id IN (...)"]
    U -->|No| I{Has ifrs_setting_id?}

    I -->|Yes| Q4["JOIN ifrs_settings WHERE company_id IN (...)"]
    I -->|No| I2{Has ifrs_settings_id?}

    I2 -->|Yes| Q5["JOIN ifrs_settings WHERE company_id IN (...)"]
    I2 -->|No| FULL
```

### Table Categories

#### Skipped Tables (not exported - too large/unnecessary)

```php
$skippedTables = [
    'activity_log',        // ~2.8M rows
    'action_events',
    'notifications',
    'contract_version_report',  // ~1.2M rows
    'contract_balances',
];
```

#### Excluded Tables (exported in full, no filtering)

```php
$excludedTables = [
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
    'help_texts',
    'guides',
    'languages',
];
```

Tables starting with `telescope_` are also excluded automatically.

### Configuration

To modify table handling, edit `app/Helpers/SelectiveDatabaseExport.php`:

- Add to `$skippedTables` to completely skip a table
- Add to `$excludedTables` to fetch all rows without filtering

## Other Commands

* `db:update {environment}` eg, `iceman db:update dev3` gets the database from the production environment and updates dev3 database.

## FORGE API
Please ensure that the env key FORGE_API is set, eg:
`export FORGE_API=eyJ0eXAiOiJK`

## Upgrades

**Important:** Build the phar BEFORE tagging. The version comes from the build, not the git tag.

```bash
# 1. Build with version number
php iceman app:build iceman --build-version=X.Y.Z

# 2. Commit the built phar
git add builds/iceman
git commit -m 'X.Y.Z release'

# 3. Tag and push
git tag X.Y.Z
git push origin main --tags
```

Users update with: `composer global update leasify/iceman`

## Cloudnet
You need to have your SSH-key installed at Cloudnet servers. Please contact them at support@cloudnet.se to get access for your local environment.

## License
Iceman is an open-source software licensed under the MIT license.
