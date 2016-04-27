<?php

namespace Releaser;

use Releaser\Models\Repository;
use Releaser\Models\GithubAPIClient;

require __DIR__ . '/../../vendor/autoload.php';

/**
 * Class Releaser\Releaser
 *
 * @package Releaser
 */
class Releaser
{
    CONST MODE_INTERACTIVE    = 'interactve';
    CONST MODE_SANDBOX        = 'sandbox';
    CONST MODE_NONINTERACTIVE = 'noninteractive';

    /**
     * @var GithubAPIClient
     */
    private $githubApiCLient;

    /**
     * @var string - name of the github repo owner that is being released
     */
    private $owner;

    /**
     * @var string - type of release (major, minor, patch)
     */
    private $type;

    /**
     * @var string - source tag, branch, or release to base the new release of
     */
    private $sourceRef;

    /**
     * @var string - all dependencies without this in their name will be ignored
     */
    private $whitelistDepCommonNames;

    /**
     * @var All dependencies with these strings in their name will be ignored
     */
    private $blacklistDepCommonNames;

    /**
     * @var string - repository being released
     */
    private $mainRepoName;

    /**
     * @var array - holds info about all repos and their dependencies
     */
    private $repos;

    /**
     * @var array - repositories requiring a release for main repo to be released
     */
    private $toBeReleased = [];

    /**
     * @var array - holds (composer.json etc) files for each repo
     */
    private $fileHolder;

    /**
     * @var
     */
    private $mode;

    /**
     * Releaser constructor.
     *
     * @param string $token Github API token
     * @param string $owner Github owner name of repository to release
     */
    public function __construct($token, $owner)
    {
        $this->owner = $owner;

        $this->githubApiCLient = new GithubAPIClient($token, $owner);
    }

    /**
     * release repository and its dependencies
     *
     * @param string $repository                     Repository to release
     * @param string $whitelistDepCommonNames        All dependencies with these strings somewhere in their name will be released, can be same as repo name
     * @param string $blacklistDepCommonNames        All dependencies with these strings in their name will be ignored
     * @param string $type                           major, minor, patch. See below
     * @param string $sourceRef                      Branch name OR version to release (f.e. master or 1.2.0)
     *                                               types: major: master branch -> create 1.0.x branch -> do 1.0.0 release
     *                                               minor: master branch -> create 1.1.x branch -> do 1.1.0 release
     *                                               patch:         create or reuse 1.1.x branch -> do 1.1.1 release
     */
    public function release(
        $repository,
        $whitelistDepCommonNames,
        $blacklistDepCommonNames,
        $type = 'minor',
        $sourceRef = 'master',
        $mode = 'interactive'
    ) {
        $this->mainRepoName            = $repository;
        $this->whitelistDepCommonNames = $whitelistDepCommonNames;
        $this->blacklistDepCommonNames = $blacklistDepCommonNames;
        $this->type                    = $type;
        $this->sourceRef               = $sourceRef;
        $this->mode                    = $mode;

        $this->repos[$this->mainRepoName] = new Repository($this->githubApiCLient, $this->mainRepoName);
        $this->repos[$this->mainRepoName]->addRequiredVersion($sourceRef);

        $this->scanAllDependencies();
        $this->verifyWhatNeedsARelease();
        $this->sortReleasablesInReleaseOrder();

        $this->releaseAllRequiredRepos();

        return $this->msg("\nAll done. Have a nice day! :)");
    }

    /**
     *
     */
    private function scanAllDependencies()
    {
        do {
            $newDeps = 0;
            foreach ($this->repos as $repository) {
                $newDeps += $this->getDependenciesForCurrentRepos($repository);
            }
        } while ($newDeps !== 0);

        return;
    }

    /**
     *
     */
    private function sortReleasablesInReleaseOrder()
    {
        $toRelease    = $this->toBeReleased;
        $releaseOrder = [];

        $level = 1;
        do {
            foreach ($toRelease as $repoName) {
                $repoDependencies = $this->repos[$repoName]->getDependencies();
                if (isset($repoDependencies) && !empty($repoDependencies)) {
                    // only account for deps that are required for release
                    foreach ($repoDependencies as $dep) {
                        if (!in_array($dep, $toRelease)) {
                            $repoDependencies = $this->removeValueFromArray($dep, $repoDependencies);
                        }
                    }
                } else {
                    $repoDependencies = [];
                }

                // #1 the ones who have no dependencies
                if (empty($repoDependencies)) {
                    $releaseOrder[] = $repoName;
                    $toRelease      = $this->removeValueFromArray($repoName, $toRelease);
                    continue;
                } else {
                    $allDependenciesOrdered = true;
                    foreach ($repoDependencies as $dep) {
                        // if a dep is not ordered yet - break out
                        if (!in_array($dep, $releaseOrder)) {
                            $allDependenciesOrdered = false;
                            break;
                        }
                    }
                    if ($allDependenciesOrdered) {
                        $releaseOrder[] = $repoName;
                        $toRelease      = $this->removeValueFromArray($repoName, $toRelease);
                    }
                }
            }
            if ($level >= 15) {
                $this->msg();
                var_dump('SOLVED: ', $releaseOrder);
                var_dump('UNSOLVED:', $toRelease);
                $this->err("\nTurning on the other brain failed, nothing is released, Releaser is shutting down :/");
                die;
            }
            $level++;
        } while (!empty($toRelease));

        $this->toBeReleased = $releaseOrder;
    }

    /**
     * @param Repository $repository
     * @return mixed
     */
    private function getDependenciesForCurrentRepos(Repository $repository)
    {
        $newDependencies = 0;

        if ($repository->getDependencies() !== false) {
            return $newDependencies;
        }
        $parentName     = $repository->getName();
        $githubVersion  = $repository->calculateLatestRequiredVersion();
        $versionRefName = $repository->cToGVersion($githubVersion);

        $composerJson = $this->getFileFromGithub($parentName, $versionRefName, 'composer.json');
        $composerInfo = json_decode(base64_decode($composerJson), true);

        if (!isset($composerInfo['require']) || empty($composerInfo['require'])) {
            $this->repos[$parentName]->setDependencies([]);
            $this->msg("$parentName has no dependencies");

            return $newDependencies;
        }

        foreach ($composerInfo['require'] as $depCompName => $depCompVersion) {
            // each dep that matches naming pattern

            if ($this->strposInArray($depCompName, $this->whitelistDepCommonNames)
                && !$this->strposInArray($depCompName, $this->blacklistDepCommonNames)) {
                $depName = $this->composerToGithubRepoName($depCompName);
                $repository->addDependency($depName);

                if (isset($this->repos[$depName])) {
                    $this->repos[$depName]->addRequiredVersion($depCompVersion, $parentName);
                } else {
                    $this->repos[$depName] = new Repository($this->githubApiCLient, $depName);
                    $this->repos[$depName]->setComposerName($depCompName)
                                          ->addRequiredVersion($depCompVersion, $repository->getName());
                }

                if ($this->repos[$depName]->getDependencies() === false) {
                    $newDependencies++;
                }
            }
        }

        return $newDependencies;
    }

    /**
     * @param string $needle
     * @param array  $haystack
     */
    private function strposInArray($needle, array $haystack)
    {
        foreach ($haystack as $item) {
            if (strpos($needle, $item) !== false) {
               return true;
            }
        }

        return false;
    }

    /**
     *
     */
    private function verifyWhatNeedsARelease()
    {
        foreach ($this->repos as $repo) {
            $repo->calculateLatestRequiredVersion();

            if ($repo->needsARelease($this->type)) {
                $this->toBeReleased[] = $repo->getName();
            }
        }

        $this->accountForAllDependenciesToBeReleased();
    }

    /**
     * While there are repos with unaccounted dependencies, loop through and add them
     * Verify no more deps need to be released for about to be released repositories
     * Even if no code change is need, repos that have dependency change needs new release
     */
    private function accountForAllDependenciesToBeReleased()
    {
        $allAccountedFor = false;
        do {
            $allAccountedFor = true;
            // each repo requiring a release
            foreach ($this->toBeReleased as $releasableRepoName) {
                // all repositories not added to release yet
                foreach ($this->repos as $repoName => $repo) {
                    if (empty($repo->getDependencies()) || in_array($repoName, $this->toBeReleased)) {
                        continue;
                    }

                    if (in_array($releasableRepoName, $repo->getDependencies())
                        && !in_array($repoName, $this->toBeReleased)
                    ) {
                        $allAccountedFor      = false;
                        $this->toBeReleased[] = $repoName;
                        if ($repoName === $this->mainRepoName) {
                            $this->msg("$repoName needs a new release because it is the main repository");
                        } else {
                            $this->msg("$repoName needs a new release because $releasableRepoName is released");
                        }
                    }
                }
            }
        } while ($allAccountedFor = false);
    }

    /**
     *
     */
    private function releaseAllRequiredRepos()
    {
        $count = count($this->toBeReleased);
        $this->msg("\n");
        if ($count === 0) {
            $this->msg("No repositories require a release! :)");
            die;
        }

        $this->msg(
            "New $this->mainRepoName {$this->repos[$this->mainRepoName]->nextVersion($this->type)} to be released, depending on "
            . ($count - 1)
            . " new:"
        );
        foreach ($this->toBeReleased as $repo) {
            if ($repo !== $this->mainRepoName) {
                $this->msg('- ' . $this->repos[$repo]->nextVersion($this->type) . ' ' . $repo);
            }
        }

        if ($this->mode === static::MODE_NONINTERACTIVE) {
            $this->msg("Auto releasing packages in {$this->mode} mode");
        } elseif ($this->mode === static::MODE_SANDBOX) {
            $this->msg("Aborting due to {$this->mode} mode");
            return false;
        } else {
            $this->promptUserWhetherToProceed();
        }

        foreach ($this->toBeReleased as $repoName) {
            $repo = $this->repos[$repoName];
            $this->createDotXBranch($repo);
            $this->addNewDepsToDotXComposerFile($repo);
            $this->pushDotXComposerFile($repo);
            $this->releaseDotXBranch($repo);
        }
    }

    /**
     * @param string $filename
     * @return bool
     */
    private function addNewDepsToDotXComposerFile(Repository $repo, $filename = 'composer.json')
    {
        $repoName = $repo->getName();
        if (!isset($this->fileHolder[$repoName][$filename]['content'])) {
            $this->msg("Warning, $repoName does not seem to contain a $filename file");

            return false;
        }

        //$fileData    = $this->fileHolder[$repoName][$filename];
        $fileData    = $this->getFileFromGithub($repoName, $repo->nextDotXBranch(), 'composer.json');
        $fileContent = json_decode(base64_decode($fileData), true);
        if (isset($fileContent['require']) && !empty($fileContent['require'])) {
            foreach ($fileContent['require'] as $depName => $depVersion) {
                $depGName = $this->composerToGithubRepoName($depName);
                if (!array_key_exists($depGName, $this->repos)) {
                    continue;
                }
                if (in_array($depGName, $this->toBeReleased)) {
                    $changeDepVerTo                   = $this->repos[$depGName]->nextVersion($this->type);
                    $fileContent['require'][$depName] = $changeDepVerTo;
                    $this->msg($repo->getName() . " $filename changed dep $depName to $changeDepVerTo");
                }
            }
            $newContent = base64_encode(json_encode($fileContent, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            $this->fileHolder[$repoName][$filename]['content_copy'] = $fileContent;
            $this->fileHolder[$repoName][$filename]['content']      = $newContent;
        }
    }

    /**
     * @param string $filename
     */
    private function pushDotXComposerFile(Repository $repo, $filename = 'composer.json')
    {
        $repoName    = $repo->getName();
        $releaseData = [
            'message' => 'Releaser changed composer.json dependencies',
            'content' => $this->fileHolder[$repoName][$filename]['content'],
            'sha'     => $this->fileHolder[$repoName][$filename]['sha'],
            'branch'  => $this->repos[$repoName]->nextDotXBranch()
        ];

        $result = $this->githubApiCLient->updateFile($repoName, $filename, $releaseData);

        if (isset($result->content, $result->commit)) {
            return true;
        }

        var_dump($result);
        $this->err("Failed to create dot X branch. Aborting");
    }

    /**
     *
     */
    private function createDotXBranch(Repository $repo)
    {
        if ($repo->hasBranch($repo->nextDotXBranch())) {
            return false;
        }

        $ref = $this->getSourceRefHead($repo->getName());
        if (isset($ref->object->sha)) {
            $sha = $ref->object->sha;
        } else {
            var_dump($ref);
            $this->err("Failed to obtain stable branch last commit sha hash");
        }

        $newRef = $repo->nextDotXBranch();

        return $this->createDotXRef($repo->getName(), $newRef, $sha);
    }

    /**
     *  Awaits user STDIN confirmation to proceed
     */
    private function promptUserWhetherToProceed()
    {
        $this->msg("\n");
        $this->msg("Are you sure you want to release these packages?");
        $this->msg("Type YES(y) to continue, NO(n) to abort...");
        $handle  = fopen("php://stdin", "r");
        $line    = fgets($handle);
        $userVal = trim($line);
        if (!in_array($userVal, ['y', 'Y', 'yes', 'YES'])) {
            $this->err("\nAborting!");
        }
        fclose($handle);
        $this->msg("\nContinuing with release, press  Ctrl+C  to abort manually");
    }

    /**
     * @param $repository
     * @param $sourceRef
     * @param $filePath
     * @return string
     */
    private function getFileFromGithub($repoName, $sourceRef, $filePath)
    {
        $response = $this->githubApiCLient->getFile($repoName, $sourceRef, $filePath);

        $this->fileHolder[$repoName][$filePath] = [
            'sha'     => $response->sha,
            'content' => $response->content,
            'ref'     => $sourceRef
        ];

        return $response->content;
    }

    /**
     * @param $sha
     * @return bool
     */
    private function createDotXRef($repoName, $newRef, $sha)
    {
        return $this->githubApiCLient->createRef($repoName, $newRef, $sha);
    }

    /**
     * @return bool
     */
    private function releaseDotXBranch(Repository $repo)
    {
        $dotXBranch = $repo->nextDotXBranch();
        $newTag     = $repo->nextVersion($this->type);
        $stats      = $repo->stats;

        $body = "`$newTag from $dotXBranch branch with {$stats['ahead']} commits`"
                . "\n\n[Releaser] (https://github.com/Gundars/releaser) @ " . date("l, M j Y G:i")
                . "\n\n### File changes:"
                . implode('', $this->prependToEach("\n* ", $stats['files']));

        $releaseData = [
            'tag_name'         => $newTag,
            'target_commitish' => $dotXBranch,
            'name'             => $newTag,
            'body'             => $body,
            'draft'            => false,
            'prerelease'       => false
        ];

        return $this->githubApiCLient->releaseBranch($repo->getName(), $releaseData);
    }

    /**
     * @param string $string
     * @param array  $array
     * @return array
     */
    private function prependToEach($string, array $array)
    {
        foreach ($array as $k => $v) {
            $array[$k] = $string . $v;
        }

        return $array;
    }

    /**
     * @param string $message
     */
    private function msg($message = '')
    {
        echo "$message \n";
    }

    /**
     * @param string $message
     */
    private function err($message)
    {
        echo "Error: $message \nABORTING!";
        exit;
    }

    /**
     * @param string $composerName
     * @return mixed
     */
    private function composerToGithubRepoName($composerName)
    {
        $nameArray = explode('/', $composerName);
        $sliced    = array_slice($nameArray, -1);
        $repoName  = array_pop($sliced);

        return $repoName;
    }

    /**
     * @param string $value
     * @param array  $array
     * @return array
     */
    private function removeValueFromArray($value, array $array)
    {
        if (($key = array_search($value, $array)) !== false) {
            unset($array[$key]);
        }

        return $array;
    }

    private function getSourceRefHead($repoName)
    {
        return $this->githubApiCLient->getSourceRefHead($repoName);
    }
}
