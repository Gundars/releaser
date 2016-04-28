<?php

namespace Releaser\Models;

use Composer\Semver\VersionParser;
use Composer\Semver\Semver;
use UnexpectedValueException;

/**
 * Class Repository
 * Responsible for all Github repository data and logic required for a release
 *
 * @package Releaser\M`odels
 */
class Repository
{
    /**
     * @var
     */
    private $githubApiClient;

    /**
     * @var VersionParser
     */
    private $versionParser;

    /**
     * @var Semver
     */
    private $semver;

    /**
     * @var string Github repo name
     */
    private $name = '';

    /**
     * @var string Composer full name of the repo
     */
    private $composerName;

    /**
     * @var array [this repo versions required by others;
     * composer.json version => [repos requiring this version]]
     */
    private $requiredVersions = [];

    /**
     * @var array Dependencies of this repository names, github name
     *            false = not checked
     *            [] = checked and no required deps found
     */
    private $dependencies = false;

    /**
     * @var bool Does the repo contain new commits ahead of the latest required release
     */
    private $needsRelease;

    /**
     * @var
     */
    private $tags;

    /**
     * @var
     */
    private $branches;

    /**
     * @var
     */
    private $releases;

    /**
     * @var array of all tags,branches and releases ordered by date desc
     */
    private $tagsReleasesBranches;

    /**
     * @var array
     */
    public $latestVersions = [];

    /**
     * @var
     */
    private $nextVersion;

    /**
     * @var array
     */
    public $stats = [
        'ahead'           => 0,
        'files'           => [],
        'commit_messages' => []
    ];

    public function __construct($githubApiClient, $name)
    {
        $this->githubApiClient = $githubApiClient;
        $this->versionParser   = new VersionParser();
        $this->semver          = new Semver();

        $this->setName($name);
        $this->collectRefs();
        $this->getLatestVersions();
    }

    /**
     * @return mixed
     */
    public function getName()
    {
        return $this->name;
    }

    /**
     * @param $name string
     * @return $this
     */
    public function setName($name)
    {
        $this->name = $name;

        return $this;
    }

    /**
     * @return mixed
     */
    public function getComposerName()
    {
        return $this->composerName;
    }

    /**
     * @param $composerName string
     * @return $this
     */
    public function setComposerName($composerName)
    {
        $this->composerName = $composerName;

        return $this;
    }

    /**
     * Version of THIS repository other repos require
     *
     * @param $requiredVersion string
     * @param $requiree        string Repo needing THIS repo
     * @return $this
     */
    public function addRequiredVersion($requiredVersion, $requiree = null)
    {
        if (!isset($this->requiredVersions[$requiredVersion])) {
            $this->requiredVersions[$requiredVersion] = [];
        }
        $this->requiredVersions[$requiredVersion][] = $requiree;

        return $this;
    }

    /**
     * @return array versions of THIS repo others require ["dev-master", "1.1.8"]
     */
    public function getRequiredVersions()
    {
        return array_keys($this->requiredVersions);
    }

    /**
     * @return array other repositories requiring this repo ["root1", "root2"]
     */
    public function getVersionRequirees()
    {
        $return = [];
        foreach ($this->requiredVersions as $requirees) {
            $return += $requirees;
        }

        return $return;
    }

    /**
     * @return mixed
     */
    public function getDependencies()
    {
        return $this->dependencies;
    }

    /**
     * @param $dependencies
     * @return mixed
     */
    public function setDependencies($dependencies)
    {
        return $this->dependencies = $dependencies;
    }

    /**
     * @param $composerName string
     * @return $this
     */
    public function addDependency($dependencyName)
    {
        if (![$this->dependencies]) {
            $this->dependencies = [];
        }
        $this->dependencies[] = $dependencyName;

        return $this;
    }

    /**
     * Calculate this dep. version used last time main repo repo was released
     * Can be a branch / tag / release / whatever composer supports when you read this
     */
    public function calculateLatestRequiredVersion()
    {
        $versions = $this->getRequiredVersions();
        $count    = count($versions);
        if ($count <= 0) {//previous calculation must have had a bug
            $this->err("ERROR: miscalc, " . $this->getName() . " 0 versions are required by others. Aborting!");
        } elseif ($count === 1) {//single version required :)
            $latestRequiredVersion = $this->abstractVersionToGitRef($versions[0]);
        } else { // required multiple versions :(
            $latestRequiredVersion = $this->multipleAbstractVersionsToGitRef($versions);
        }

        return $latestRequiredVersion;
    }

    /**
     *
     */
    private function collectRefs()
    {
        $name = ($this->getName()) ? $this->getName() : 'repository';
        $this->msg("Downloading $name data through API");

        $tagsAndReleases = [];

        $tags = $this->githubApiClient->getTags($this->getName());
        foreach ($tags as $tag) {
            if ($this->isNormalisible($tag->name)) {
                $this->tags[$tag->name] = $tag;
                $tagsAndReleases[]      = $tag->name;
            }
        }

        $releases = $this->githubApiClient->getReleases($this->getName());
        foreach ($releases as $release) {
            if ($this->isNormalisible($release->name)) {
                $this->releases[$release->name] = $release;
                $tagsAndReleases[]              = $release->name;
            }
        }

        $branches = $this->githubApiClient->getBranches($this->getName());
        foreach ($branches as $branch) {
            $this->branches[$branch->name] = $branch;
        }

        $this->tagsReleasesBranches = $this->semver->rsort($tagsAndReleases);
        $this->tagsReleasesBranches += array_keys($this->branches);
    }

    private function isNormalisible($refName)
    {
        try {
            $this->versionParser->normalize($refName);

            return true;
        } catch (UnexpectedValueException $e) {
            // this shouldnt happen, means branches are in release list
            return false;
        }
    }

    /**
     * @return bool|void
     */
    public function needsARelease($type)
    {
        return (bool) (is_bool($this->needsRelease)) ? $this->needsRelease : $this->verifyRepositoryNeedsToBeReleased(
            $type
        );
    }

    /**
     * todo:
     * when receiving branch or major version - compare to latest version
     * when receiving patch - version - compare to dotx branch
     *
     * @param string $refRelease
     */
    private function verifyRepositoryNeedsToBeReleased($type)
    {
        return $this->branchNeedsANewRelease($this->getRequiredVersions()[0], $this->currentVersion($type));
    }

    /**
     * @param $releases
     */
    private function getLatestVersions()
    {
        $this->latestVersions = new \stdClass(); //todo

        $latestVersion = $this->tagsReleasesBranches[0];

        if (strpos($latestVersion, '.') === false) {
            $m1 = 0;
            $m2 = 1;
            $m3 = 0;
        } else {
            $vSplit = explode('.', $latestVersion);
            $m1     = $vSplit[0];
            $m2     = isset($vSplit[1]) ? $vSplit[1] : 1;
            $m3     = isset($vSplit[2]) ? $vSplit[2] : 0;
        }

        $p3 = $this->getLatestPatchVersion("$m1.$m2.");

        $this->latestVersions->current_master = "$m1.$m2.0";
        $this->latestVersions->current_major  = "$m1.0.0";
        $this->latestVersions->current_minor  = "$m1.$m2.0";
        $this->latestVersions->current_patch  = ($p3 > 0 ? "$m1.$m2.$p3" : null);
        $this->latestVersions->next_branch    = "$m1." . ($m2 + 1) . ".x";
        $this->latestVersions->next_major     = ($m1 + 1) . ".0.0";
        $this->latestVersions->next_minor     = "$m1." . ($m2 + 1) . ".0";
        $this->latestVersions->next_patch     = "$m1.$m2." . ($p3 + 1);

        $this->msg(
            $this->getName() . " latest master release {$this->latestVersions->current_master}, "
            . ($this->latestVersions->current_patch ? 'patched with '
                                                      . $this->latestVersions->current_patch : 'not patched')
        );
    }

    private function currentVersion($type)
    {
        switch ($type) {
            case 'major':
                $version = $this->latestVersions->current_major;
                break;
            case 'minor':
                $version = $this->latestVersions->current_minor;
                break;
            case 'patch':
                $version = ($this->latestVersions->current_patch) ? $this->latestVersions->current_patch : $this->latestVersions->current_minor;
                break;
            default:
                $version = '0.1.0';
        }

        $this->nextVersion = $version;

        return $version;
    }

    /**
     * @param $type string - minor, major, patch
     */
    public function nextVersion($type)
    {
        switch ($type) {
            case 'major':
                $version = $this->latestVersions->next_major;
                break;
            case 'minor':
                $version = $this->latestVersions->next_minor;
                break;
            case 'patch':
                $version = $this->latestVersions->next_patch;
                break;
            default:
                $version = '0.1.0';
        }

        $this->nextVersion = $version;

        return $version;
    }

    /**
     * @return string
     */
    public function nextDotXBranch()
    {
        $vSplit = explode('.', $this->nextVersion);

        return "{$vSplit[0]}.{$vSplit[1]}.x";
    }

    /**
     * @param $releaseBeginning
     * @return int
     */
    private function getLatestPatchVersion($releaseBeginning)
    {
        $patchVersions = [];
        foreach ($this->tagsReleasesBranches as $releaseName) {
            if ($releaseName !== $releaseBeginning . '0'
                && substr($releaseName, 0, strlen($releaseBeginning)) === $releaseBeginning
            ) {
                $patchVersions[] = str_replace($releaseBeginning, '', $releaseName);
            }
        }

        return (!empty($patchVersions)) ? (int) max($patchVersions) : 0;
    }

    /**
     * @param $branch
     * @param $releaseVersion
     */
    private function branchNeedsANewRelease($branch, $releaseVersion)
    {
        $comparison = $this->githubApiClient->curlRefAndReleaseComparison(
            $this->getName(),
            $releaseVersion,
            $this->cToGVersion($branch)
        );
        $this->msg(
            $this->getName()
            . " $branch branch VS $releaseVersion release - {$comparison->status}, ahead by {$comparison->ahead_by}, behind by {$comparison->behind_by} commits"
        );

        // Save stats
        $this->stats['ahead'] = (int) $comparison->ahead_by;
        if (!empty($comparison->files)) {
            foreach ($comparison->files as $file) {
                $this->stats['files'][] = "$file->status $file->filename -$file->deletions +$file->additions";
            }
        }
        if (!empty($comparison->commits)) {
            foreach ($comparison->commits as $commit) {
                $this->stats['commit_messages'][] = $commit->commit->message;
            }
        }

        // Does it need a release based on committed changes?
        if (($comparison->ahead_by > 0)) {
            return true;
            $this->msg($this->getName() . " needs new release ");
        }

        return false;
    }

    /**
     * @param $version
     * @return bool
     */
    public function cToGVersion($version)
    {
        if (!$this->versionExistsInGit($version)) {
            return $this->abstractVersionToGitRef($version);
        }

        return $version;
    }

    /**
     * @param $version
     * @return bool
     */
    private function versionExistsInGit($version)
    {
        return in_array($version, $this->tagsReleasesBranches);
    }

    /**
     * Works for single version getting, unprecise
     * Might need to return all matching refs
     * and common (multi) or latest (single) outside this func
     *
     * @param $composerVersion
     * @return bool
     */
    private function abstractVersionToGitRef($composerVersion)
    {
        // if a valid branch name - return it
        if (isset($this->branches[$composerVersion])) {
            $gitRef = $composerVersion;
        }

        // if it starts with dev- and branch exists - remove dev
        if (substr($composerVersion, 0, strlen('dev-')) === 'dev-') {
            $gitRef = str_replace('dev-', '', $composerVersion);
        }

        // it ends with -dev - remove -dev
        if (strpos($composerVersion, '-dev') !== false) {
            $gitRef = str_replace('-dev', '', $composerVersion);
        }

        // if it has a . and * in it - find latest version starting with the val
        // if it is just * - use latest release
        // assuming list $tagsReleasesBranches is sorted with latest release desc
        if (strpos($composerVersion, '*') !== false) {
            if ($composerVersion === '*') {
                $gitRef = $this->tagsReleasesBranches[0];
            } elseif (strpos($composerVersion, '.') !== false) {
                foreach ($this->tagsReleasesBranches as $tag) {
                    $versNoStar = str_replace('*', '', $composerVersion);
                    if (substr($tag, 0, strlen($versNoStar)) === $versNoStar) {
                        $gitRef = $tag;
                        break;
                    }
                }
            }
        }

        if (isset($gitRef) && !empty($gitRef)) {
            return $gitRef;
        }

        // could not match anything - see if composer semver can match
        $matchingLatestVersion = $this->matchLatestViaSemver($composerVersion);
        if ($matchingLatestVersion) {
            return $matchingLatestVersion;
        }

        $this->msg('Releaser brain is shutting down:');
        var_dump($this->tagsReleasesBranches);
        $this->bye("Version $composerVersion does not exist in git");
    }

    private function matchLatestViaSemver($composerVersion)
    {
        $gitRef = false;
        foreach ($this->tagsReleasesBranches as $ref) {
            try {
                $matching = $this->semver->satisfies($ref, $composerVersion);
                if ($matching) {
                    $gitRef = $ref;
                    break;
                }
            } catch (UnexpectedValueException $e) {
                continue;
            }
        }

        return $gitRef;
    }

    private function matchAllVersionsViaSemver($composerVersion)
    {
        $matches = [];
        foreach ($this->tagsReleasesBranches as $ref) {
            $matching = $this->semver->satisfies($ref, $composerVersion);
            if ($matching) {
                $matches[] = $ref;
                break;
            }
        }

        return (!empty($matches)) ? $matches : false;
    }

    /**
     * todo:
     * Find single type of required versions - tags, releases, branch names
     * Get all refs - branches, tags, releases
     * make an array for each of multiple versions ["version" => [], "version2" => []]
     * add fitting refs in version arrays["version" => [tag1, tag2, tag3...]]
     * find latest changed ref that fits all versions
     */
    private function multipleAbstractVersionsToGitRef($versions)
    {
        foreach ($versions as $key => $version) {
            if ($version === '*') {
                unset($versions[$key]);
            }
        }

        if (count($versions) === 1) {
            return array_pop($versions);
        }

        var_dump($this->getVersionRequirees());
        //should  construct array of ALL matching versions
        // and get latest ref in all deps
        var_dump($this->getName());
        var_dump($versions);
        $this->err('MULTIPLE LEVELS NOT IMPLEMENTED!');
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
    private function err($message, $exitCode = 1)
    {
        echo "Error: $message \nABORTING!";
        exit($exitCode);
    }

    private function bye($message)
    {
        echo "Error: $message \nABORTING!";

        $e     = new \Exception();
        $trace = explode("\n", $e->getTraceAsString());
        // reverse array to make steps line up chronologically
        $trace = array_reverse($trace);
        array_shift($trace); // remove {main}
        array_pop($trace); // remove call to this method
        $length = count($trace);
        $result = [];

        for ($i = 0; $i < $length; $i++) {
            $result[] = ($i + 1) . ')' . substr(
                    $trace[$i],
                    strpos($trace[$i], ' ')
                ); // replace '#someNum' with '$i)', set the right ordering
        }

        $this->err("\t" . implode("\n\t", $result));
    }

    /**
     * @param $branch
     * @return bool
     */
    public function hasBranch($branch)
    {
        return (array_key_exists($branch, $this->branches));
    }
}
