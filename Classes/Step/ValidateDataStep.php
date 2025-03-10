<?php

declare(strict_types=1);

namespace Cobweb\ExternalImport\Step;

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

use Cobweb\ExternalImport\Exception\CriticalFailureException;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Utility\LocalizationUtility;

/**
 * Validates the data after it has been mapped to TCA columns.
 *
 * This step does not transform data. It may only interrupt the process, if data does not validate.
 *
 * @package Cobweb\ExternalImport\Step
 */
class ValidateDataStep extends AbstractStep
{
    /**
     * Performs the data validation.
     *
     * @return void
     */
    public function run(): void
    {
        $generalConfiguration = $this->importer->getExternalConfiguration()->getGeneralConfiguration();
        $records = $this->getData()->getRecords();

        // Check if number of records is larger than or equal to the minimum required number of records
        // Note that if the minimum is not defined, this test is skipped
        if (!empty($generalConfiguration['minimumRecords'])) {
            $countRecords = count($records);
            if ($countRecords < $generalConfiguration['minimumRecords']) {
                $this->abortFlag = true;
                $this->importer->addMessage(
                    LocalizationUtility::translate(
                        'LLL:EXT:external_import/Resources/Private/Language/ExternalImport.xlf:notEnoughRecords',
                        'external_import',
                        array(
                            $countRecords,
                            $generalConfiguration['minimumRecords']
                        )
                    )
                );
            }
        }

        // Call hooks to perform additional checks,
        // but only if previous check was passed
        // Using a hook is deprecated
        // TODO: remove in the next major version
        $hooks = $GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['external_import']['validateRawRecordset'] ?? null;
        if (!$this->isAbortFlag() && is_array($hooks)) {
            trigger_error('Hook "validateRawRecordset" is deprecated. Use a custom step instead.', E_USER_DEPRECATED);
            foreach ($GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['external_import']['validateRawRecordset'] as $className) {
                try {
                    $validator = GeneralUtility::makeInstance($className);
                    $continueImport = $validator->validateRawRecordset($records, $this->importer);
                    // If a single check fails, don't call further hooks
                    if (!$continueImport) {
                        $this->abortFlag = true;
                        break;
                    }
                } catch (CriticalFailureException $e) {
                    $this->abortFlag = true;
                    break;
                } catch (\Exception $e) {
                    $this->importer->debug(
                        sprintf(
                            'Could not instantiate class %s for hook %s',
                            $className,
                            'preprocessRawRecordset'
                        ),
                        1
                    );
                }
            }
        }
    }
}