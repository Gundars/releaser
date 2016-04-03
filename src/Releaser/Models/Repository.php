<?php

namespace Releaser\Models;

/**
 * Class Repository
 *
 * Responsible for all Github repository data and logic required for a release
 *
 * @package Releaser\Models
 */
class Repository
{
    /**
     * @var string Github repo name
     */
    private $name;

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
     * @return Repository
     */
    public static function newInstance()
    {
        return new self();
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
            $return = $return + $requirees;
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
     *
     * Can be a branch / tag / release / whatever composer supports when you read this
     */
    public function calculateLatestRequiredVersion()
    {
        $versions = $this->getRequiredVersions();
        $count = count($versions);
        if ($count <= 0) {//previous calculation must have had a bug
            die("ERROR: miscalc, " . $this->getName() . " 0 versions are required by others. Aborting!");
        } elseif ($count === 1) {//single version required :)
            $latestRequiredVersion = $versions[0];
        } else { // required multiple versions :(
            $latestRequiredVersion = $this->findCommonComposerVersionFromMultiple();
        }

        return $latestRequiredVersion;
    }

    /**
     * todo:
     * Scan type of required versions - tags, releases, branch names
     * Get all refs - branches, tags, releases
     * make an array for each of multiple versions ["version" => [], "version2" => []]
     * add fitting refs in version arrays["version" => [tag1, tag2, tag3...]]
     * find latest changed ref that fits all versions
     */
    private function findCommonComposerVersionFromMultiple()
    {












    }


    /**
     * @return bool|void
     */
    public function needsRelease()
    {
        return (is_bool($this->needsRelease)) ? $this->needsRelease : $this->verifyRepositoryNeedsToBeReleased();
    }

    private function verifyRepositoryNeedsToBeReleased()
    {







        $this->getLatestVersions($releases);
        // todo find out if custom branch / tag needs a release! not just hardcode to current_master
        $this->branchNeedsANewRelease($this->repos[$repo]['source_refs'][0], $this->repos[$repo]['current_master']);
    }

    private function getCurentReleases()
    {
       $this->curlGetReleases();




        // save all releases
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
     * @return mixed
     */
    private function curlGetReleases()
    {
        $path     = "repos/$this->owner/$this->currentRepo/releases";
        $releases = $this->executeCurlRequest($path);

        return $releases;
    }
}
