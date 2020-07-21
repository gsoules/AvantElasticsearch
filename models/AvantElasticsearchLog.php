<?php

class AvantElasticsearchLog
{
    // This method provides a logging mechanism for long running indexing operations (export and import).
    // It addresses the fact that while one instance of AvantElasticSearchIndexBuilder is performing indexing
    // operations, a separate instance is reporting progress. It also addresses the fact that both the file system
    // and MySQL cache data before writing it to disk. This logic uses the Omeka Options table to store indexing
    // events as they are logged, then writes the entire log to a file when the indexing operation is complete.
    // The logic uses one row in the table for log events and another row for progress events. It uses separate rows
    // to get around the problem whereby data written by one instance of AvantElasticSearchIndexBuilder may be cached
    // before being written to the disk such that it won't be able to be read by the other instance. By having
    // each instance write/read its own row, the caching problem is minimized or eliminated.

    const OPTION_ES_LOG = 'avantelasticsearch_es_log';
    const OPTION_ES_PROGRESS = 'avantelasticsearch_es_progress';

    protected $logFileName;

    public function __construct($logFileName)
    {
        $this->logFileName = $logFileName;
    }

    public function getLogFileName()
    {
        return $this->logFileName;
    }

    public function logError($errorMessage)
    {
        $this->logEvent("<span class='indexing-error'>$errorMessage</span>");
    }

    public function logEvent($text)
    {
        // Get all the content logged so far, add the text to it, and write all of it back to the log.
        $contents = get_option(self::OPTION_ES_LOG);
        set_option(self::OPTION_ES_LOG, $contents . PHP_EOL . $text);
    }

    public function logProgress($eventMessage)
    {
        // This method overwrites the last line of the log file. Use it when reporting progress on a repeated action.
        set_option(self::OPTION_ES_PROGRESS, $eventMessage);
    }

    public static function readLog()
    {
        // See comments for readProgress().
        return get_option(self::OPTION_ES_LOG);
    }

    public static function readProgress()
    {
        // When this method is called in response to an Ajax request for progress, the caller is running
        // in a different instance of AvantElasticsearchIndexBuilder than the instance which is performing
        // the indexing. As such, the caller's $this->log variable's is not the same as the one in the
        // instance of AvantElasticsearchIndexBuilder that is performing the indexing. To keep things simple,
        // this method is static so that the caller can retrieve the log content directly from the database.
        return get_option(self::OPTION_ES_PROGRESS);
    }

    public function startNewLog()
    {
        // Create a new log and write a timestamp to it.
        set_option(self::OPTION_ES_LOG, date("Y-m-d H:i:s"));
    }

    public function writeLogToFile()
    {
        // Write only the log data to the file. Progress messages are only to inform the admin of progress.
        // There can be a lot of them and they add no useful information after the fact and so can be omitted.
        $contents = get_option(self::OPTION_ES_LOG);
        file_put_contents($this->logFileName, $contents);

        // Erase the log info in the database to prevent a mistimed progress reporting event from reading ghost data.
        set_option(self::OPTION_ES_LOG, '');
        set_option(self::OPTION_ES_PROGRESS, '');
    }
}