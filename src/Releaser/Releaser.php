<?php

namespace Releaser;

/**
 * Class Releaser\Releaser
 *
 * @package Releaser
 */
class Releaser
{
    /**
     * Github API root
     */
    const GITHUB_ROOT = 'https://api.github.com/';

    /**
     * Github file encoding
     */
    const GITHUB_FILE_ENCODING = 'base64';

    /**
     * @var string - Github API token
     */
    private $githubApiToken;

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
    private $commonDepName;


    /**
     * @var string - repository being released
     */
    private $mainRepo;

    /**
     * @var array - holds info about all repos and their dependencies
     */
    private $repos;

    /**
     * @var array - repositories requiring a release for main repo to be released
     */
    private $toBeReleased;

    /**
     * @var string - lazy global state of current repo
     */
    private $currentRepo;

    /**
     * @var array - holds (composer.json etc) files for each repo
     */
    private $fileHolder;

    /**
     * @var array - Composer to Github repository  names
     */
    private $repoNamesComposerToGH;

    /**
     * @var array - Github to Composer repository  names
     */
    private $repoNamesGHToComposer;

    /**
     * @var array - Composer to Github dep version names
     */
    private $repoVersionsComposerToGH;

    /**
     * @var array - Github to Composer dep verison names
     */
    private $repoVersionsGHToComposer;

    /**
     * Releaser lib constructor
     *
     * @param string $token         Github API token
     * @param string $owner         Github owner name of repository to release
     * @param string $repository    Repository to release
     * @param string $commonDepName All dependencies with this somewhere in their name will be released, can be same as repo name
     * @param string $type          major, minor, patch. See below
     * @param string $sourceRef     Branch name OR version to release (f.e. master or 1.2.0)
     *
     * types: major: master branch -> create 1.0.x branch -> do 1.0.0 release
     *        minor: master branch -> create 1.1.x branch -> do 1.1.0 release
     *        patch:         create or reuse 1.1.x branch -> do 1.1.1 release
     *
     */
    public function release($token, $owner, $repository, $commonDepName, $type = 'minor', $sourceRef = 'master')
    {
        $this->githubApiToken = $token;
        $this->owner          = $owner;
        $this->mainRepo       = $repository;
        $this->type           = $type;
        $this->sourceRef      = $sourceRef;
        $this->commonDepName  = $commonDepName;

        $this->repos[$this->mainRepo]['source_refs'][] = $sourceRef;
        $this->scanAllDependencies();
        $this->scanForMultipleDependencyVersions();
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
            $newDeps = $this->getDependenciesForCurrentRepos();
        } while ($newDeps !== 0);

        return;
    }

    /**
     *
     */
    private function scanForMultipleDependencyVersions()
    {
        $this->msg();
        $this->msg("$this->mainRepo $this->sourceRef release depends on:");

        $issues = [];
        foreach ($this->repos as $repoName => $repoData) {
            $versionArray = $repoData['source_refs'];
            $versions     = implode(', ', $versionArray);
            $this->msg("$repoName ($versions)");
            if (count($versionArray) > 1) {
                $issues[] = "$repoName ($versions)";
            }
        }

        $this->msg();
        if (!empty($issues)) {
            $this->msg('Releaser cannot proceed due to multiple dependency versions');
            $this->err("Aborting because of " . implode(' and ', $issues));
        }
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
            foreach ($toRelease as $repo) {
                if (isset($this->repos[$repo]['dependencies'])
                    && !empty($this->repos[$repo]['dependencies'])
                ) {
                    // only account for deps that are required for release
                    $reposReleasableDependencies = array_keys($this->repos[$repo]['dependencies']);
                    foreach ($reposReleasableDependencies as $dep) {
                        if (!in_array($dep, $toRelease)) {
                            $reposReleasableDependencies = $this->removeValueFromArray($dep, $reposReleasableDependencies);
                        }
                    }
                } else {
                    $reposReleasableDependencies = [];
                }

                // #1 the ones who have no dependencies
                if (empty($reposReleasableDependencies)) {
                    $releaseOrder[] = $repo;
                    $toRelease      = $this->removeValueFromArray($repo, $toRelease);
                    continue;
                } else {
                    $allDependenciesOrdered = true;
                    foreach ($reposReleasableDependencies as $dep) {
                        // if a dep is not ordered yet - break out
                        if (!in_array($dep, $releaseOrder)) {
                            $allDependenciesOrdered = false;
                            break;
                        }
                    }
                    if ($allDependenciesOrdered) {
                        $releaseOrder[] = $repo;
                        $toRelease      = $this->removeValueFromArray($repo, $toRelease);
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
     * @return int
     */
    private function getDependenciesForCurrentRepos()
    {
        $newDependencies = 0;
        // Get deps of all current repos
        foreach ($this->repos as $repoName => $repoData) {
            if (isset($repoData['dependencies'])) {
                continue;
            }
            $composerJson = $this->getFileFromGithub($repoName, $repoData['source_refs'][0], 'composer.json');
            $composerInfo = json_decode($composerJson, true);

            if (!isset($composerInfo['require']) || empty($composerInfo['require'])) {
                $this->msg("$repoName has no dependencies");
                $this->repos[$repoName]['dependencies'] = false;
                continue;
            }

            foreach ($composerInfo['require'] as $depCompName => $depCompVersion) {
                // each dep that matches naming pattern
                if (strpos($depCompName, $this->commonDepName) !== false) {
                    // Add to github/composer naming array for later
                    $depName                                         = $this->composerToGithubRepoName($depCompName);
                    $depVersion                                      = $this->composerToGithubRepoVersion($depCompVersion);
                    $this->repoNamesComposerToGH[$depCompName]       = $depName;
                    $this->repoNamesGHToComposer[$depName]           = $depCompName;
                    $this->repoVersionsComposerToGH[$depVersion]     = $depCompVersion;
                    $this->repoVersionsGHToComposer[$depCompVersion] = $depVersion;

                    // Add to main repo dependencies
                    $this->repos[$repoName]['dependencies'][$depName] = $depVersion;

                    // give dependency its own section
                    if (!array_key_exists($depName, $this->repos)) {
                        $this->repos[$depName] = [];
                    }

                    if (!array_key_exists('dependencies', $this->repos[$depName])) {
                        $newDependencies++;
                    }

                    // add dep version to dep section in repos
                    if (!array_key_exists('source_refs', $this->repos[$depName])) {
                        $this->repos[$depName]['source_refs'] = [];
                    }
                    if (!in_array($depVersion, $this->repos[$depName]['source_refs'])) {
                        $this->repos[$depName]['source_refs'][] = $depVersion;
                    }
                }
            }
        }

        return $newDependencies;
    }

    /**
     *
     */
    private function verifyWhatNeedsARelease()
    {
        foreach (array_keys($this->repos) as $repo) {
            $this->verifyIfRepoNeedsARelease($repo);
        }

        $this->msg();
        $this->msg("Additional releases due to dependency change:");
        $this->accountForAllDependenciesToBeReleased();
    }

    /**
     * While there are repos with unaccounted dependencies, loop through and add them
     *
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
                foreach ($this->repos as $repoName => $repoData) {
                    if (!isset($repoData['dependencies']) || in_array($repoName, $this->toBeReleased)) {
                        continue;
                    }

                    if (in_array($releasableRepoName, array_keys($repoData['dependencies']))
                        && !in_array($repoName, $this->toBeReleased)
                    ) {
                        $allAccountedFor      = false;
                        $this->toBeReleased[] = $repoName;
                        if ($repoName === $this->mainRepo) {
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
     * @param $repo
     */
    private function verifyIfRepoNeedsARelease($repo)
    {
        $this->currentRepo = $repo;
        $releases          = $this->curlGetReleases();
        $this->getLatestVersions($releases);
        // todo find out if custom branch / tag needs a release! not just hardcode to current_master
        $this->branchNeedsANewRelease($this->repos[$repo]['source_refs'][0], $this->repos[$repo]['current_master']);
    }

    /**
     *
     */
    private function releaseAllRequiredRepos()
    {
        $count = count($this->toBeReleased);
        $this->msg("\n");
        if ($count === 0) {
            $this->err("No repositories require a release! :)");
        }

        $this->msg("New $this->mainRepo {$this->repos[$this->mainRepo]['next_master']} to be released, depending on " . ($count - 1) . " new:");
        foreach ($this->toBeReleased as $repo) {
            //todo: this is hardcoded to master
            if ($repo !== $this->mainRepo) {
                $this->msg('- ' . $this->repos[$repo]['next_master'] . ' ' . $repo);
            }
        }

        $this->promptUserWhetherToProceed();

        foreach ($this->toBeReleased as $repo) {
            $this->currentRepo = $repo;
            $this->createDotXBranch();
            $this->addNewDepsToDotXComposerFile();
            $this->pushDotXComposerFile();
            $this->releaseDotXBranch();
        }
    }

    /**
     * @param string $filename
     * @return bool
     */
    private function addNewDepsToDotXComposerFile($filename = 'composer.json')
    {
        if (!isset($this->fileHolder[$this->currentRepo][$filename]['content'])) {
            $this->msg("Warning, $this->currentRepo does not seem to contain a $filename file");

            return false;
        }

        $fileData    = $this->fileHolder[$this->currentRepo][$filename];
        $fileContent = json_decode(base64_decode($fileData['content']), true);
        if (isset($fileContent['require']) && !empty($fileContent['require'])) {
            foreach ($fileContent['require'] as $depName => $depVersion) {
                if (!array_key_exists($depName, $this->repoNamesComposerToGH)) {
                    continue;
                }
                $depNameGH = $this->repoNamesComposerToGH[$depName];

                if (in_array($depNameGH, $this->toBeReleased)) {
                    //todo: currently hardcoded to master
                    $changeDepVerTo                   = $this->repos[$depNameGH]['next_master'];
                    $fileContent['require'][$depName] = $changeDepVerTo;
                    $this->msg("$this->currentRepo $filename changed dep $depName to $changeDepVerTo");
                }
            }
            $newContent = base64_encode(json_encode($fileContent, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            $this->fileHolder[$this->currentRepo][$filename]['content_copy'] = $fileData['content'];
            $this->fileHolder[$this->currentRepo][$filename]['content']      = $newContent;
        }
    }

    /**
     * @param string $filename
     */
    private function pushDotXComposerFile($filename = 'composer.json')
    {
        $path = "repos/$this->owner/$this->currentRepo/contents/$filename";

        $releaseData = [
            'message' => 'Releaser changed composer.json dependencies',
            'content' => $this->fileHolder[$this->currentRepo][$filename]['content'],
            'sha'     => $this->fileHolder[$this->currentRepo][$filename]['sha'],
            'branch'  => $this->repos[$this->currentRepo]['next_branch']
        ];

        $result = $this->executeCurlRequest($path, 'PUT', $releaseData);

        if (isset($result->content, $result->commit)) {
            return true;
        }

        var_dump($result);
        $this->err("Failed to create dot X branch. Aborting");
    }

    /**
     *
     */
    private function createDotXBranch()
    {
        $ref = $this->getSourceRefHead();
        if (isset($ref->object->sha)) {
            $sha = $ref->object->sha;
        } else {
            var_dump($ref);
            $this->err("Failed to obtain stable branch last commit sha hash");
        }

        $this->createDotXRef($sha);
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
     * @param $releases
     */
    private function getLatestVersions($releases)
    {
        $tagsThreeLevels = [];
        foreach ($releases as $release) {
            $explodedTag = explode('.', $release->tag_name);

            $first  = (int)$explodedTag[0];
            $second = (int)$explodedTag[1];
            $third  = (int)$explodedTag[2];
            if (!array_key_exists($first, $tagsThreeLevels)) {
                $tagsThreeLevels[$first] = [];
            }
            if (!array_key_exists($second, $tagsThreeLevels[$first])) {
                $tagsThreeLevels[$first][$second] = [];
            }
            if (!array_key_exists($third, $tagsThreeLevels[$first][$second])) {
                $tagsThreeLevels[$first][$second][] = $third;
            }
        }
        $masterVMaxLevel1 = max(array_keys($tagsThreeLevels));
        $masterVMaxLevel2 = max(array_keys($tagsThreeLevels[$masterVMaxLevel1]));
        $masterVMaxLevel3 = max($tagsThreeLevels[$masterVMaxLevel1][$masterVMaxLevel2]);

        $patchVMaxLevel3 = min($tagsThreeLevels[$masterVMaxLevel1][$masterVMaxLevel2]);

        $this->repos[$this->currentRepo]['current_master'] = "$masterVMaxLevel1.$masterVMaxLevel2.$masterVMaxLevel3";
        $this->repos[$this->currentRepo]['next_master']    = "$masterVMaxLevel1." . ($masterVMaxLevel2 + 1) . ".0";
        $this->repos[$this->currentRepo]['current_patch']  = ($patchVMaxLevel3 > 0 ? "$masterVMaxLevel1.$masterVMaxLevel2.$patchVMaxLevel3" : null);
        $this->repos[$this->currentRepo]['next_branch']    = "$masterVMaxLevel1." . ($masterVMaxLevel2 + 1) . ".x";

        $this->msg(
            "$this->currentRepo latest master release {$this->repos[$this->currentRepo]['current_master']}, "
            . ($this->repos[$this->currentRepo]['current_patch'] ? 'patched with '
                . $this->repos[$this->currentRepo]['current_patch'] : 'not patched')
        );
    }

    /**
     * @param $branch
     * @param $releaseVersion
     */
    private function branchNeedsANewRelease($branch, $releaseVersion)
    {
        $comparison = $this->curlReleaseAndComparison($branch, $releaseVersion);
        $this->msg(
            "$this->currentRepo $branch branch VS $releaseVersion release - {$comparison->status}, ahead by {$comparison->ahead_by}, behind by {$comparison->behind_by} commits"
        );

        // Save stats
        $this->repos[$this->currentRepo]['stats']['ahead'] = (int)$comparison->ahead_by;
        if (!empty($comparison->files)) {
            foreach ($comparison->files as $file) {
                $this->repos[$this->currentRepo]['stats']['files'][] = "$file->status $file->filename -$file->deletions +$file->additions";
            }
        }
        if (!empty($comparison->commits)) {
            foreach ($comparison->commits as $commit) {
                $this->repos[$this->currentRepo]['stats']['commit_messages'][] = $commit->commit->message;
            }
        }

        // Does it need a release based on committed changes?
        if (($comparison->ahead_by > 0)) {
            $this->toBeReleased[] = $this->currentRepo;
            $this->msg("$this->currentRepo needs new release ");
        }
    }

    /**
     * @param $repository
     * @param $sourceRef
     * @param $filePath
     * @return string
     */
    private function getFileFromGithub($repository, $sourceRef, $filePath)
    {
        $data = ['ref' => $sourceRef];
        $path = "repos/$this->owner/$repository/contents/$filePath";

        $response = $this->executeCurlRequest($path, 'GET', $data);

        if ($response->size <= 0 || $response->type !== 'file' || $response->encoding !== static::GITHUB_FILE_ENCODING) {
            $this->err("File is either empty or a diractory.");
        }

        $this->fileHolder[$repository][$filePath] = [
            'sha'     => $response->sha,
            'content' => $response->content,
            'ref'     => $sourceRef
        ];

        return base64_decode($response->content);
    }

    /**
     * @param $sha
     * @return bool
     */
    private function createDotXRef($sha)
    {
        $path     = 'repos/' . $this->owner . '/' . $this->currentRepo . '/git/refs';
        $newRef   = $this->repos[$this->currentRepo]['next_branch'];
        $postData = [
            'ref' => 'refs/heads/' . $newRef,
            'sha' => $sha
        ];
        $result   = $this->executeCurlRequest($path, 'POST', $postData);

        if ($result === true || (isset($result->ref) && $result->ref === $postData['ref'])) {
            $this->msg("Branch $newRef created for $this->currentRepo");

            return true;
        }

        var_dump($result);
        $this->err("Failed to create new ref. Aborting");
    }

    /**
     * @return bool
     */
    private function releaseDotXBranch()
    {
        $path = 'repos/' . $this->owner . '/' . $this->currentRepo . '/releases';
        // todo: hardcoded to master
        $repoData   = $this->repos[$this->currentRepo];
        $dotXBranch = $repoData['next_branch'];
        $newTag     = $repoData['next_master'];
        $stats      = $repoData['stats'];

        $body = "`$newTag from $dotXBranch branch with {$stats['ahead']} commits`"
            . "\n\n### File changes:"
            . implode('', $this->prependToEach("\n* ", $stats['files']))
            . "\n\n### Commits:"
            . implode('', $this->prependToEach("\n* ", $stats['commit_messages']))
            . "\n\n[by Releaser] (https://packagist.org/packages/gundars/releaser) @ " . date("D M d, Y G:i a");

        $releaseData = [
            'tag_name'         => $newTag,
            'target_commitish' => $this->repos[$this->currentRepo]['next_branch'],
            'name'             => $newTag,
            'body'             => $body,
            'draft'            => false,
            'prerelease'       => false
        ];

        $result = $this->executeCurlRequest($path, 'POST', $releaseData);
        if (isset($result->tag_name) && $result->tag_name === $releaseData['tag_name']) {
            $this->msg("Released $this->currentRepo $newTag");

            return true;
        }

        var_dump($result);
        $this->err("Failed to release $this->currentRepo. Aborting");
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
     * @param $branch
     * @param $releaseVersion
     * @return mixed
     */
    private function curlReleaseAndComparison($branch, $releaseVersion)
    {
        $path = "repos/$this->owner/$this->currentRepo/compare/$releaseVersion...$branch";

        return $this->executeCurlRequest($path);
    }

    /**
     * @return mixed
     */
    private function curlGetReleases()
    {
        $path     = "repos/$this->owner/$this->currentRepo/releases";
        $releases = $this->executeCurlRequest($path);

        return $releases;
    }

    /**
     * @return mixed
     */
    private function getSourceRefHead()
    {
        $path = "repos/$this->owner/$this->currentRepo/git/refs/heads/master";
        $ref  = $this->executeCurlRequest($path);

        return $ref;
    }

    /**
     * @param string $urlPath
     * @param string $requestType
     * @param array  $requestData
     * @return mixed
     */
    private function executeCurlRequest($urlPath, $requestType = 'GET', $requestData = [])
    {
        $getParams = [
            'access_token' => $this->githubApiToken
        ];
        if ($requestType === 'GET' && !empty($requestData)) {
            $getParams = $getParams + $requestData;
        }

        $url     = static::GITHUB_ROOT . $urlPath . '?' . http_build_query($getParams);
        $ch      = curl_init();
        $options = [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => 1,
            CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows; U; Windows NT 5.1; en-US; rv:1.8.1.13) Gecko/20080311 Firefox/2.0.0.13'
        ];
        if (!in_array($requestType, ['GET', 'POST'])) {
            $options[CURLOPT_CUSTOMREQUEST] = $requestType;
        }
        if (in_array($requestType, ['POST', 'PUT']) && !empty($requestData)) {
            $options[CURLOPT_POSTFIELDS] = json_encode($requestData);
        }
        curl_setopt_array($ch, $options);

        $result = @json_decode(curl_exec($ch));

        if (isset($result->message)) {
            return $this->parseGithubApiResultMessage($result->message, $url, $options);
        }

        return $result;
    }

    /**
     * @param $message
     * @param $url
     * @param $curlOptions
     * @return bool
     */
    private function parseGithubApiResultMessage($message, $url, $curlOptions)
    {
        if ($this->githubApiToken == '') {
            $this->err('Constant GITHUB_TOKEN is empty');
        }

        if (strpos($message, 'already exists') !== false) {
            return true;
        } elseif (strpos($message, 'composer.json does not match')) {
            var_dump($curlOptions);
            $this->err("Issues with composer.json sha. Deleting .X branch and retry");
        } else {
            var_dump($curlOptions);
            $this->msg("Failed to retrieve $url");
            $this->err("$message");
        }

        return false;
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
     * @param $version
     * @return mixed
     */
    private function composerToGithubRepoVersion($version)
    {
        if (is_string($version) && strpos($version, 'dev-') !== false) {
            $version = str_replace('dev-', '', $version);
        }

        return $version;
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
}
