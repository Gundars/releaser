<?php

namespace Releaser;

use Releaser\Models\Repository;
use Releaser\Models\Version;

require __DIR__ . '/../../vendor/autoload.php';

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
     * @var Version
     */
    private $version;

    /**
     * @var Repository
     */
    private $repository;

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
    private $mainRepoName;

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

    public function __construct()
    {
        $this->version    = Version::newInstance(); //todo: DI
        $this->repository = Repository::newInstance();
    }

    /**
     * release repository and its dependencies
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
        $this->mainRepoName   = $repository;
        $this->type           = $type;
        $this->sourceRef      = $sourceRef;
        $this->commonDepName  = $commonDepName;

        $this->repos[$this->mainRepoName] = $this->repository
            ->newInstance()
            ->setName($this->mainRepoName)
            ->addRequiredVersion($sourceRef);

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
     * @param Repository $repository
     * @return mixed
     */
    private function getDependenciesForCurrentRepos(Repository $repository)
    {
        $newDependencies = 0;

        if ($repository->getDependencies() !== false) {
            return $newDependencies;
        }
        $parentName = $repository->getName();
        $composerJson = $this->getFileFromGithub($parentName, $repository->getRequiredVersions()[0], 'composer.json');
        $composerInfo = json_decode($composerJson, true);

        if (!isset($composerInfo['require']) || empty($composerInfo['require'])) {
            $this->repos[$parentName]->setDependencies([]);
            $this->msg("$parentName has no dependencies");
            $this->repos[$parentName]['dependencies'] = false;
            return $newDependencies;
        }

        foreach ($composerInfo['require'] as $depCompName => $depCompVersion) {
            // each dep that matches naming pattern
            if (strpos($depCompName, $this->commonDepName) !== false) {
                $depName = $this->composerToGithubRepoName($depCompName);
                $repository->addDependency($depName);

                if (isset($this->repos[$depName])) {
                    $this->repos[$depName]->addRequiredVersion($depCompVersion, $parentName);
                } else {
                    $this->repos[$depName] = $this->repository
                        ->newInstance()
                        ->setName($depName)
                        ->setComposerName($depCompName)
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
     *
     */
    private function verifyWhatNeedsARelease()
    {
        foreach ($this->repos as $repo) {
            $repo->calculateLatestReleasedVerion();













            $repo->needsRelease();
        }

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
            $this->err("No repositories require a release! :)");
        }

        $this->msg("New $this->mainRepoName {$this->repos[$this->mainRepoName]['next_master']} to be released, depending on " . ($count - 1) . " new:");
        foreach ($this->toBeReleased as $repo) {
            //todo: this is hardcoded to master
            if ($repo !== $this->mainRepoName) {
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
