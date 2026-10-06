<?php

namespace App\Support;

use OpenSpout\Reader\XLSX\Manager\SharedStringsCaching\CachingStrategyFactory;
use OpenSpout\Reader\XLSX\Manager\SharedStringsCaching\CachingStrategyFactoryInterface;
use OpenSpout\Reader\XLSX\Manager\SharedStringsCaching\CachingStrategyInterface;
use OpenSpout\Reader\XLSX\Manager\SharedStringsCaching\FileBasedStrategy;
use OpenSpout\Reader\XLSX\Manager\SharedStringsCaching\InMemoryStrategy;
use OpenSpout\Reader\XLSX\Reader;

/**
 * Keeps a workbook's text table ("shared strings") in memory while it is read.
 *
 * OpenSpout budgets 12 KB for each distinct text and otherwise moves the table to temporary
 * files of 10,000 entries; every cell that points into another file then reloads a file. A
 * customer list — every name and account number a different text — is read like that at a few
 * hundred rows a second: 143,000 rows took over ten minutes. In memory the same table costs some
 * tens of megabytes and the file is read in under a minute.
 */
final class InMemorySharedStrings implements CachingStrategyFactoryInterface
{
    /** A table larger than this (never seen in practice) goes to temporary files after all. */
    public const CEILING = 3_000_000;

    public function createBestCachingStrategy(?int $sharedStringsUniqueCount, string $tempFolder): CachingStrategyInterface
    {
        return $sharedStringsUniqueCount !== null && $sharedStringsUniqueCount <= self::CEILING
            ? new InMemoryStrategy($sharedStringsUniqueCount)
            : new FileBasedStrategy($tempFolder, CachingStrategyFactory::MAX_NUM_STRINGS_PER_TEMP_FILE);
    }

    /** An .xlsx reader for import files of any size. */
    public static function reader(): Reader
    {
        return new Reader(null, new self);
    }
}
