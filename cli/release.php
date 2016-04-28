<?php

require __DIR__ . '/../vendor/autoload.php';

$input = $_GET + $_POST;
parse_str(implode('&', array_slice($argv, 1)), $input);

$owner          = getCliArgOrInputParam('owner');
$githubApiToken = getCliArgOrInputParam('github_api_token', getenv('github_token_env_var'));
$releasableRepo = getCliArgOrInputParam('releasable_repo');
$whitelistDeps  = getCliArgOrInputParam('whitelist_deps', []);
$blacklistDeps  = getCliArgOrInputParam('blacklist_deps', []);
$type           = getCliArgOrInputParam('type', 'minor');
$baseRef        = getCliArgOrInputParam('base_ref', 'master');
$mode           = getCliArgOrInputParam('mode', 'sandbox');
$updateComposer = getCliArgOrInputParam('composer_update', 'true');

if ($updateComposer == 'true') {
    `composer update -v && composer dumpautoload -o`;
}

$releaser = new \Releaser\Releaser($githubApiToken, $owner);
$releaser->release($releasableRepo, $whitelistDeps, $blacklistDeps, $type, $baseRef, $mode);

/**
 * @param string $parameter
 */
function getCliArgOrInputParam($parameter, $default = null)
{
    global $input;
    if (!isset($input[$parameter]) && $default) {
        return useDefaultParam($parameter, $default);
    }

    return $input[$parameter];
}

/**
 * @param string $message
 */
function message($message = '')
{
    echo filter_var("$message\n", FILTER_SANITIZE_STRING, FILTER_FLAG_STRIP_HIGH);
}

/**
 * @param $default
 * @return mixed
 */
function useDefaultParam($parameter, $default)
{
    if ($default) {
        var_dump($parameter);

        // message("INFO: Using default parameter $parameter value " . (!is_string($default)) ? $default :  json_encode($default));

        return $default;
    }
    message("FATAL ERROR: Required parameter $parameter is not set in CLI args or \$_GET/\$_POST");
    die;
}
