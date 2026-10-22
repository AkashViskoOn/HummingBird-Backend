<?php

namespace App\Jobs;

use App\Models\MessageModel;
use CodeIgniter\Queue\BaseJob;
use CodeIgniter\Queue\Interfaces\JobInterface;
use Exception;

class SendScheduledMessagesJob extends BaseJob implements JobInterface
{
    public function process()
    {
        try {

            $currentTime = time();

            $messageModel = new MessageModel();
        
            $scheduledMessages = $messageModel->where('m_scheduled_at <=', $currentTime)
                                        ->where('m_is_scheduled', 1)
                                        ->where('m_scheduled_at !=', '0')
                                        ->findAll();
        
            foreach ($scheduledMessages as $message) {
        
                $message = $messageModel->find($message['m_id']);
    
                $messageModel->update($message['m_id'], ['m_is_scheduled' => 0]);
            }
            } catch (\Exception $e) {
                throw new Exception($e->getMessage());
            }
    }
}
