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

Releaser is an automated semantic release version manager for CLI and PHP applications

Releaser is intended to save time on release process for individuals and companies that maintain interconnected repositories

###Installation

Add a dependency to your composer, execute
```php
composer require gundars/releaser 0.*
```

###Releasing a repository via PHP
```php
$releaser = new \Releaser\Releaser();
$releaser->release($token, $owner, $repository, $commonDepName, $type, $sourceRef);
```

####Arguments:

| First Header     | Req | Sample            | Description                                                 |
|       :---:      |:---:|        :---:      | :---                                                        |
| `$token`         |  *  |'a0bc9q42g3f4....' | Github API token                                            |
| `$owner`         |  *  |'github-account'   | Name of the github repo owner that is being released        |
| `$repository`    |  *  | 'reponame'        | Name of the g-ithub repo owner that is being released       |
| `$commonDepName` |  *  | 'prefix'          | All dependencies without this in their name will be ignored |
| `$type`          |  *  | 'major'           | Type of release (major, minor, patch)                       |
| `$sourceRef`     |  *  | 'master'          | Source repository release base - tag, branch, or release    |

###Releasing a repository via CLI
```php
soon...
```
###Currently known issues, unimplemented features
