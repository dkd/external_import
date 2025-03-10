<?php

declare(strict_types=1);

namespace Cobweb\ExternalImport;

/*
 * This file is part of the TYPO3 CMS project.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 *
 * The TYPO3 project - inspiring people to share!
 */

/**
 * Implements the ImporterAware interface.
 *
 * @package Cobweb\ExternalImport
 */
trait ImporterAwareTrait
{
    /**
     * @var Importer
     */
    protected $importer;

    /**
     * Set the internal Importer instance.
     *
     * @param Importer $importer
     */
    public function setImporter(Importer $importer): void
    {
        $this->importer = $importer;
    }

    /**
     * Returns the internal Importer instance.
     *
     * @return Importer
     */
    public function getImporter(): Importer
    {
        return $this->importer;
    }
}