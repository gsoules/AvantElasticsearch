<?php

class AvantElasticsearchLog
{
    protected $logFileName;

    public function __construct($logFileName)
    {
        $this->logFileName = $logFileName;
    }

    protected function appendToLog($text)
    {
        $logText = get_option(ElasticsearchConfig::OPTION_ES_LOG);
        $this->replaceLogContents($logText . $text);
    }

    public function getLogFileName()
    {
        return $this->logFileName;
    }

    public function logError($errorMessage)
    {
        $this->logEvent("<span class='indexing-error'>$errorMessage</span>");
    }

    public function logEvent($eventMessage)
    {
        $event =  PHP_EOL . $eventMessage;
        $this->appendToLog($event);
    }

    public function readLog()
    {
        return get_option(ElasticsearchConfig::OPTION_ES_LOG);
    }

    public static function readLogProgress()
    {
        // When this method is called in response to an Ajax request for progress, the caller is running
        // in a different instance of AvantElasticsearchIndexBuilder than the instance which is performing
        // the indexing. As such, the caller's $this->log variable's is not the same as the one in the
        // instance of AvantElasticsearchIndexBuilder that is performing the indexing. To keep things simple,
        // this method is static so that the caller can retrieve the log content directly from the database.
        return get_option(ElasticsearchConfig::OPTION_ES_LOG);
    }

    public function replaceLastLineInLog($eventMessage)
    {
        // This method overwrites the last line of the log file. Use it when reporting progress on a repeated action.
        $event =  $eventMessage;
        $contents = $this->readLog();
        $lines = explode("\r\n", $contents);
        $lines[count($lines) - 1] = $event;
        $contents = implode("\r\n", $lines);
        $this->replaceLogContents($contents);
    }

    protected function replaceLogContents($text)
    {
        set_option(ElasticsearchConfig::OPTION_ES_LOG, $text);
    }

    public function startNewLog()
    {
        // Create a new log (overwrite an existing log) and write a timestamp.
        $this->replaceLogContents(date("Y-m-d H:i:s"));
    }

    public function writeLogToFile()
    {
        $contents = get_option(ElasticsearchConfig::OPTION_ES_LOG);
        file_put_contents($this->logFileName, $contents);

        // Erase the log in the database to prevent a mistimed progress reporting event from reading a ghost log.
        $this->replaceLogContents('');
    }
}