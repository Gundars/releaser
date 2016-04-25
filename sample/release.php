<?php

require __DIR__ . '/../src/Releaser/Releaser.php';

$releaser = new \Releaser\Releaser('github_api_token', 'github_repo_owner');
$releaser->release('repo_to_release', 'release_repos_by_keyword_in_name', 'minor', 'master', 'interactive');
