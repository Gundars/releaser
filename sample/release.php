<?php

require __DIR__ . '/../src/Releaser/Releaser.php';

$releaser = new \Releaser\Releaser();
$releaser->release(
    'token',
    'github_repo_owner',
    'repo_to_release',
    'for_most_same_as_owner',
    'minor',
    'master'
);
