<?php

namespace Releaser\Models;

use Releaser\Interfaces\ComposerVersionable;
use Releaser\Interfaces\GitVersionable;

/**
 * Class Version
 *
 * Responsible for Composer / Github single version logic
 *
 * @package Releaser\Models
 */
class Version implements ComposerVersionable, GitVersionable
{
    /**
     * f.i. 1.7.0 , dev-master or 1.*
     */
    private $versionCode;

    /**
     * @return Version
     */
    public static function newInstance()
    {
        return new self();
    }

    /**
     * f.i. verify if 1.7.0 is in range 1.* or < 2.0
     *
     * @param $versionRange
     */
    public function isInRange($versionRange)
    {

    }
}