# ICEMAN

Internal tool for Leasify for deployment and pipeline.

** WORK IN PROGRESS **

Commands:
* `env` to check/read your environment settings
* `feature {AP-branch}` creates a new Laravel Forge site in our AWS environment
* `pull {environment}` eg, `iceman pull prod` gets the database and local files from the production environment at Cloudnet.
* `pull:sail {environment}` eg, `iceman pull:sail prod` gets the database and local files from the production environment at Cloudnet to your Sail environment.
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
