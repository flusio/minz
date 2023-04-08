<?php

namespace Minz\Tests;

/**
 * Ease tests based on time by providing a freeze method and make sure to
 * unfreeze it at the beginning of each tests.
 *
 * @see \Minz\Time
 *
 * @author Marien Fressinaud <dev@marienfressinaud.fr>
 * @license http://www.gnu.org/licenses/agpl-3.0.en.html AGPL
 */
trait TimeHelper
{
    /**
     * @before
     */
    public function unfreeze(): void
    {
        \Minz\Time::unfreeze();
    }

    /**
     * @param \DateTime|integer $timestamp
     */
    public function freeze(mixed $timestamp): void
    {
        \Minz\Time::freeze($timestamp);
    }
}
