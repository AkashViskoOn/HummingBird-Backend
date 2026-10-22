<?php

use CodeIgniter\CLI\CLI;
use App\Controllers\MessageController;

class ScheduledTaskDaemon
{
    public function run()
    {
        while (true) {
            $this->executeScheduledTasks();
            sleep(1);
        }
    }

    private function executeScheduledTasks()
    {
        $controller = service(MessageController::class);
        $controller->sendScheduledMessage();
    }
}

?>