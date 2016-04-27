#!/bin/bash
#change mode from "sandbox" to "noninteractive" to start the party

php release.php owner="" \
                github_api_token="" \
                releasable_repo="" \
                whitelist_deps[]= \
                blacklist_deps[]= \
                type="" \
                base_ref="" \
                mode="sandbox" \
                composer_update="true" \