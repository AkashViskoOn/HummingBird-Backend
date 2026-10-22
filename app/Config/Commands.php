<?php

namespace Config;

use CodeIgniter\Config\BaseConfig;

class Commands extends BaseConfig
{
    public $commands = [
        'daemon:run'      => \App\Commands\DaemonCommand::class,
        'connect:worker'  => \App\Commands\ConnectWorkerCommand::class,
        'connect:webhook-worker' => \App\Commands\ConnectWebhookWorkerCommand::class,
        'connect:webhook-cleanup' => \App\Commands\ConnectWebhookCleanupCommand::class,
    ];
}
// d_task_helper\ScheduledTaskDaemon::class,
//     ];
// }
