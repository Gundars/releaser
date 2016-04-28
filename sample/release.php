
<?php

require __DIR__ . '/../src/Releaser/Releaser.php';

$releaser = new \Releaser\Releaser('github_api_token', 'github_repo_owner');
$releaser->release('repo_to_release', ['release_repos_with_these_keywords_in_name'], ['skip_repos_with_these_keywords_in_name'], 'minor', 'master', 'interactive');
