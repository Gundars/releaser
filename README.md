```bash

        .---.        .-----------
       /     \  __  /    ------
      / /     \(  )/    -----     '||'''|,        '||`
     //////   ' \/ `   ---         ||   ||         ||
    //// / // :    : ---           ||...|' .|''|,  ||  .|''|,  '''|.  ('''' .|''|, '||''|
   // /   /  /`    '--             || \\   ||..||  ||  ||..|| .|''||   `'') ||..||  ||
  //          //..\\              .||  \\. `|...  .||. `|...  `|..||. `...' `|...  .||.
=============UU====UU====
             '//||\\`
               ''``
```

Releaser is an automated semantic release version manager for PHP applications

It is intended to save time on individuals and companies  - release all your repositories in a minute!

Provide Releaser with your repository name, and it will release it alongside all of its modified dependencies.

###Basic Releaser flow:
- gets data for main repo you want to release
- finds all of its dependencies
- finds all dependency dependencies until all accounted for
- figure out which repos changed since last release
- release all modified dependencies in logical order
- release main repo


###Installation

Add a dependency to your composer, execute
```php
composer require gundars/releaser 0.*
```

###Releasing a repository via PHP
```php
$releaser = new \Releaser\Releaser('$token', '$owner');
$releaser->release($repository, $whitelistDepCommonNames, $blacklistDepCommonNames $type, $sourceRef, $mode);

#for example, this repo is released using:
$releaser = new \Releaser\Releaser('55b48e382257a...', 'gundars');
$releaser->release('releaser', 'gundars', [], 'minor', 'dev-master', 'sandbox');

```

####Arguments:

| First Header     | Sample            | Description                                                 |
|       :---:      |        :---:      | :---                                                        |
| `$token`         |'a0bc9q42g3f4asd'  | Github API token                                            |
| `$owner`         |'github-account'   | Name of the github repo owner that is being released        |
| `$repository`    | 'reponame'        | Name of the github repository that is being released       |
| `$whitelistDepCommonNames` | ['goodrepoprefix']          | All dependencies with this in their name wil lbe released, can be same as `$owner` or empty  |
| `$blacklistDepCommonNames` | ['badrepoprefix']        | All dependencies with these strings in their name will be ignored, [] by default |
| `$type`          | 'major'           | Type of release (major 1.0.0, minor 1.1.0 (default), patch 1.1.1)                       |
| `$sourceRef`     | 'master'          | Source repository release base - tag, branch, or release. Default - master   |
| `$mode`          | 'interactive'     | 'interactive' - ask for input, then release (default), 'sandbox' - show what would be released, 'noninteractive' - release without prompting user confirmation |


###Releasing a repository via CLI or web
To run via bash, execute `./cli/run.sh`, define params there
To run via browser, execute `php ./cli/release.php` using cli args or $_GET or $_POST parameters


###Currently known issues, unimplemented features, garbage
* option to force release on unchanged repos for stability
* option to release require-dev dependencies
* replace err and msg with proper 7 tier logger interface
* replace internal errors with exceptions
* finish grouping OOP for Gods sake
* DI needs fixing ASAP
