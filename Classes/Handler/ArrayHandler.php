<?php

declare(strict_types=1);

namespace Cobweb\ExternalImport\Handler;

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

use Cobweb\ExternalImport\DataHandlerInterface;
use Cobweb\ExternalImport\Importer;
use Symfony\Component\ExpressionLanguage\ExpressionLanguage;
use TYPO3\CMS\Core\Messaging\AbstractMessage;
use TYPO3\CMS\Core\Utility\ArrayUtility;
use TYPO3\CMS\Core\Utility\Exception\MissingArrayPathException;

/**
 * Remaps data from a "raw" PHP array to an array mapped to TCA columns.
 *
 * @package Cobweb\ExternalImport\Handler
 */
class ArrayHandler implements DataHandlerInterface
{
    /**
     * @var ExpressionLanguage
     */
    protected $expressionLanguage;

    public function __construct()
    {
        $this->expressionLanguage = new ExpressionLanguage();
    }

    public function __toString()
    {
        return self::class;
    }

    /**
     * Maps the incoming data to an associative array with TCA column names as keys.
     *
     * @param mixed $rawData Data to handle. Could be of any type, as suited for the data handler.
     * @param Importer $importer Back-reference to the current Importer instance
     * @return array
     */
    public function handleData($rawData, Importer $importer): array
    {
        $data = [];

        if (is_array($rawData) && count($rawData) > 0) {
            $counter = 0;
            $generalConfiguration = $importer->getExternalConfiguration()->getGeneralConfiguration();
            $columnConfiguration = $importer->getExternalConfiguration()->getColumnConfiguration();

            // Extract targeted sub-array if arrayPath is defined
            if (array_key_exists('arrayPath', $generalConfiguration) && !empty($generalConfiguration['arrayPath'])) {
                // Extract parts of the path
                $segments = str_getcsv(
                    (string)$generalConfiguration['arrayPath'],
                    array_key_exists('arrayPathSeparator', $columnConfiguration) ? (string)$columnConfiguration['arrayPathSeparator'] : '/'
                );

                $rawData = $this->getArrayPathStructure(
                    $rawData,
                    $segments
                );
                // If a problem occurred, report it and return an empty array
                if ($rawData === null) {
                    $importer->addMessage(
                        sprintf(
                            'Using arrayPath property (value %s) returned an empty set',
                            $generalConfiguration['arrayPath']
                        ),
                        AbstractMessage::WARNING
                    );
                    return [];
                }
                // If the resulting structure is not an array, return an empty array as a result
                if (!is_array($rawData) || count($rawData) === 0) {
                    return [];
                }
            }

            // Loop on all entries
            foreach ($rawData as $theRecord) {
                // Skip to the next entry if the record is not an array as expected
                if (!is_array($theRecord)) {
                    continue;
                }

                $referenceCounter = $counter;
                $data[$referenceCounter] = [];

                // Loop on the database columns and get the corresponding value from the import data
                $rows = [];
                foreach ($columnConfiguration as $columnName => $columnData) {
                    try {
                        $theValue = $this->getValue($theRecord, $columnData);
                        if (isset($columnData['substructureFields'])) {
                            $rows[$columnName] = $this->getSubstructureValues(
                                $theValue,
                                $columnData['substructureFields']
                            );
                            // Prepare for the case where no substructure was found
                            // If one was found, it is added later
                            $data[$referenceCounter][$columnName] = null;
                        } else {
                            $data[$referenceCounter][$columnName] = $theValue;
                        }
                    } catch (\Exception $e) {
                        // Nothing to do, we ignore values that were not found
                    }
                }

                // If values were found in substructures, denormalize the data
                if (count($rows) > 0) {
                    // First find the longest substructure result
                    $maxItems = 0;
                    foreach ($rows as $column => $items) {
                        $maxItems = max($maxItems, count($items));
                    }
                    // If no additional row needs to be created, increase the counter to move on to the next record
                    if ($maxItems === 0) {
                        $counter++;
                    } else {
                        // Add as many records to the import data as the highest count, while filling in with the values found in each substructure
                        // NOTE: this is not equivalent to a full denormalization, but is enough for the needs of External Import
                        for ($i = 0; $i < $maxItems; $i++) {
                            // Base data is the first entry of the $theData array
                            // NOTE: the first pass is a neutral operation
                            $data[$counter] = $data[$referenceCounter];
                            // Add a value from each structure field to each row, if it exists
                            foreach ($rows as $column => $items) {
                                if (isset($items[$i])) {
                                    foreach ($items[$i] as $key => $item) {
                                        $data[$counter][$key] = $item;
                                    }
                                }
                            }
                            $counter++;
                        }
                    }
                    // No substructure data, increase the counter to move on to the next record
                } else {
                    $counter++;
                }
            }
        }
        // Filter out empty entries (may happen if no value could be found)
        foreach ($data as $index => $item) {
            if (count($item) === 0) {
                unset($data[$index]);
            }
        }
        // Compact array before returning it
        return array_values($data);
    }

    /**
     * Searches for a value inside the record using the given configuration.
     *
     * @param array $record Data record
     * @param array $columnConfiguration External Import configuration for a single column
     * @return mixed
     */
    public function getValue(array $record, array $columnConfiguration)
    {
        if (isset($columnConfiguration['arrayPath']) && !empty($columnConfiguration['arrayPath'])) {
            // Extract parts of the path
            $segments = str_getcsv(
                (string)$columnConfiguration['arrayPath'],
                array_key_exists('arrayPathSeparator', $columnConfiguration) ? (string)$columnConfiguration['arrayPathSeparator'] : '/'
            );

            $value = $this->getArrayPathStructure(
                $record,
                $segments
            );
            if ($value === null) {
                throw new \InvalidArgumentException(
                    'No value found',
                    1534149806
                );
            }
        } elseif (isset($columnConfiguration['field'], $record[$columnConfiguration['field']])) {
            $value = $record[$columnConfiguration['field']];
        } else {
            throw new \InvalidArgumentException(
                'No value found',
                1534149806
            );
        }
        return $value;
    }

    /**
     * Extracts data from a substructure, i.e. when a value is not just a simple type but contains
     * related data.
     *
     * @param array $structure Data structure
     * @param array $columnConfiguration External Import configuration for a single column
     * @return array
     */
    public function getSubstructureValues(array $structure, array $columnConfiguration): array
    {
        $rows = [];
        foreach ($structure as $item) {
            $row = [];
            foreach ($columnConfiguration as $key => $configuration) {
                try {
                    $value = $this->getValue($item, $configuration);
                    $row[$key] = $value;
                } catch (\Exception $e) {
                    // Nothing to do, we ignore values that were not found
                }
            }
            $rows[] = $row;
        }
        return $rows;
    }

    /**
     * Extracts part of a PHP array, using an array path (e.g. "foo/bar") and conditions.
     *
     * @param array $array
     * @param array $segments
     * @return mixed
     */
    public function getArrayPathStructure(array $array, array $segments)
    {
        // Loop through each part and extract its value
        $value = $array;
        if (count($segments) > 0) {
            do {
                $segment = array_shift($segments);
                $key = $segment;
                $condition = '';
                // If the segment contains a condition, extract it
                if (strpos($segment, '{') !== false) {
                    $result = preg_match('/(.*){(.*)}/', $segment, $matches);
                    if ($result) {
                        $key = $matches[1];
                        $condition = $matches[2];
                    }
                }
                // Consider all children of the current value
                // NOTE: this makes sense only if the current value is an array, if it is not, it is left unchanged
                if (is_array($value)) {
                    if ($key === '*') {
                        $newValue = [];
                        foreach ($value as $itemValue) {
                            // Apply condition on each item, if defined
                            $result = $this->applyCondition($condition, $itemValue);
                            if ($result) {
                                // Apply leftover segments on each item
                                $resultingItems = $this->getArrayPathStructure(
                                    $itemValue,
                                    $segments
                                );
                                if (is_array($resultingItems)) {
                                    foreach ($resultingItems as $resultingItem) {
                                        $newValue[] = $resultingItem;
                                    }
                                } else {
                                    $newValue[] = $resultingItems;
                                }
                            }
                        }
                        // Set result depending on number of matches
                        if (count($newValue) === 0) {
                            $value = null;
                        } elseif (count($newValue) === 1) {
                            $value = array_shift($newValue);
                        } else {
                            $value = $newValue;
                        }

                        // Leftover segments have been used on child item, they must not be used on the resulting value anymore
                        $segments = [];

                    // Consider the next value along the path
                    } elseif (array_key_exists($key, $value)) {
                        // If an item was found and a condition is defined, try to match it
                        if ($condition !== '') {
                            $result = $this->applyCondition(
                                $condition,
                                $value[$key]
                            );
                            if ($result) {
                                // Replace current value with child
                                $value = $value[$key];
                            } else {
                                $value = null;
                            }
                        } else {
                            // Simply replace current value with child
                            $value = $value[$key];
                        }
                    } else {
                        $value = null;
                    }
                } else {
                    $value = null;
                }
            } while (count($segments) > 0);
        }
        return $value;
    }

    /**
     * Applies a condition (expressed as Symfony Expression Language) and returns the result as a boolean value.
     *
     * @param string $condition
     * @param mixed $value
     * @return bool
     */
    protected function applyCondition(string $condition, $value): bool
    {
        if (is_array($value)) {
            $testValue = $value;
        } else {
            $testValue = [
                'value' => $value
            ];
        }
        return (bool)$this->expressionLanguage->evaluate(
            $condition,
            $testValue
        );
    }
}