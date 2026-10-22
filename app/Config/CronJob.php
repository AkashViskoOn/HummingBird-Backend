<?php

namespace Config;

use Daycry\CronJob\Scheduler;
use Daycry\CronJob\Loggers\Database as DatabaseLogger;
use Daycry\CronJob\Loggers\File as FileLogger;

class CronJob extends \Daycry\CronJob\Config\CronJob
{
    /**
     * Set true if you want save logs
     */
    public bool $logPerformance = true;

    /*
    |--------------------------------------------------------------------------
    | Log Saving Method
    |--------------------------------------------------------------------------
    |
    | Set to specify the REST API requires to be logged in
    |
    | 'file'   Save in files
    | 'database'  Save in database
    |
    */
    public string $logSavingMethod = 'file';

    public array $logSavingMethodClassMap = [
        'file' => FileLogger::class,
        'database' => DatabaseLogger::class
    ];

    /**
     * Directory
     */
    public string $filePath = WRITEPATH . 'cronJob/';

    /**
     * File Name in folder jobs structure
     */
    public string $fileName = 'jobs';

    /**
     * --------------------------------------------------------------------------
     * Maximum performance logs
     * --------------------------------------------------------------------------
     *
     * The maximum number of logs that should be saved per Job.
     * Lower numbers reduced the amount of database required to
     * store the logs.
     *
     * If you write 0 it is unlimited
     */
    public int $maxLogsPerJob = 3;

    /*
    |--------------------------------------------------------------------------
    | Database Group
    |--------------------------------------------------------------------------
    |
    | Connect to a database group for logging, etc.
    |
    */
    public ?string $databaseGroup = null;

    /*
    |--------------------------------------------------------------------------
    | Cronjob Table Name
    |--------------------------------------------------------------------------
    |
    | The table name in your database that stores cronjobs
    |
    */
    public string $tableName = 'cronjob';

    /*
    |--------------------------------------------------------------------------
    | Cronjob Notification
    |--------------------------------------------------------------------------
    |
    | Notification of each task
    |
    */
    public bool $notification = false;
    public string $from = 'your@example.com';
    public string $fromName = 'CronJob';
    public string $to = 'your@example.com';
    public string $toName = 'User';

    /*
    |--------------------------------------------------------------------------
    | Views
    |--------------------------------------------------------------------------
    |
    | Notification of each task
    |
    */
    public array $views = [
        'login'                       => '\Daycry\CronJob\Views\login',
        'dashboard'                   => '\Daycry\CronJob\Views\dashboard',
        'layout'                      => '\Daycry\CronJob\Views\layout',
        'logs'                        => '\Daycry\CronJob\Views\logs'
    ];

    /*
    |--------------------------------------------------------------------------
    | Dashboard login
    |--------------------------------------------------------------------------
    */
    public bool $enableDashboard = false;
    public string $username = 'admin';
    public string $password = 'admin';

    /*
    |--------------------------------------------------------------------------
    | Cronjobs
    |--------------------------------------------------------------------------
    |
    | Register any tasks within this method for the application.
    | Called by the TaskRunner.
    |
    | @param Scheduler $schedule
    */
    public function init(Scheduler $schedule)
    {
        // For Every Minute
        // $schedule->url(base_url('send-scheduled-messages'))->named('scheduledCronJob')->everyMinute();

        // For Every Second
        // $schedule->call(function() {
        //     $controller = service('App\Controllers\MessageController()');
        //     $controller->sendScheduledMessage();
        //     sleep(1);
        // });

        // Run this command php run_every_second.php &

        //Permissions: Ensure that the script has the correct permissions to be executed.
        // You might need to set the execution permission using the 
        // chmod command (e.g., chmod +x run_every_second.php).
    }
}
