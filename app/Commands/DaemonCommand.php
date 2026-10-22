<?php

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use App\Daemon\ScheduledMessageDaemon;

class DaemonCommand extends BaseCommand
{
    protected $group       = 'Daemon';
    protected $name        = 'daemon:run';
    protected $description = 'Runs the scheduled message daemon.';

    public function run(array $params)
    {
        $daemon = new ScheduledMessageDaemon();
        $daemon->run();
    }
}
