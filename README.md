# ICEMAN

Internal tool for Leasify for deployment and pipeline.

** WORK IN PROGRESS **

Commands:
* `env` to check/read your environment settings
* `feature {AP-branch}` creates a new Laravel Forge site in our AWS environment
* `pull {environment}` eg, `iceman pull prod` gets the database and local files from the production environment at Cloudnet.
* `pull:sail {environment}` eg, `iceman pull:sail prod` gets the database and local files from the production environment at Cloudnet to your Sail environment.

## FORGE API
Please ensure that the env key FORGE_API is set, eg:
`export FORGE_API=eyJ0eXAiOiJK`

## Cloudnet
You need to have your SSH-key installed at Cloudnet servers. Please contact them at support@cloudnet.se to get access for your local environment.

## License
Iceman is an open-source software licensed under the MIT license.
