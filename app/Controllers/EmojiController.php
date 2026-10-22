<?php

namespace App\Controllers;

use App\Models\EmojiModel;
use CodeIgniter\RESTful\ResourceController;

class EmojiController extends ResourceController
{
   public function addEmoji()
   {
      if (!check_jwt_authentication()) {
         return $this->response->setJSON([
            'status' => false,
            'message' => 'Authorization Failed',
         ]);
      }

      $eCategory = $this->request->getVar('e_category');
      $emojis = $this->request->getFileMultiple('emojis') ?? [];

      try {

         $emojiModel = new EmojiModel();

         $isExist = $emojiModel->where('e_category', $eCategory)->first();

            if ($isExist) {

            $allEmojis = json_decode($isExist['e_emojis']) ?? [];

            try {
               $uploadService = new \App\Services\MediaUploadService();

               foreach ($emojis as $emoji) {
                  $stored = $uploadService->store($emoji, 'emojis');
                  $allEmojis[] = [
                     'https://myhummingbird.net' . $stored['web_path'],
                  ];
               }
            } catch (\Throwable $e) {
               log_message('error', 'addEmoji upload failed: ' . $e->getMessage());
               return $this->response->setJSON([
                  'status' => false,
                  'message' => 'Emoji upload failed. Please try again.',
               ]);
            }

            $data = [
               'e_emojis' => json_encode($allEmojis),
            ];

            if ($emojiModel->update($isExist['e_id'], $data)) {

               return $this->response->setJSON([
                  'status' => true,
                  'message' => 'Emojis successfully saved.'
               ]);
            } else {

               return $this->response->setJSON([
                  'status' => false,
                  'message' => 'Failed to save emojis in database.'
               ]);
            }
         } else {

            $allEmojis = [];

            try {
               $uploadService = new \App\Services\MediaUploadService();

               foreach ($emojis as $emoji) {

                  $stored = $uploadService->store($emoji, 'emojis');
                  $allEmojis[] = [
                     'https://myhummingbird.net' . $stored['web_path'],
                  ];
               }
            } catch (\Throwable $e) {
               log_message('error', 'addEmoji upload failed: ' . $e->getMessage());
               return $this->response->setJSON([
                  'status' => false,
                  'message' => 'Emoji upload failed. Please try again.',
               ]);
            }

            $data = [
               'e_category' => $eCategory,
               'e_emojis' => json_encode($allEmojis),
            ];

            if ($emojiModel->save($data)) {

               return $this->response->setJSON([
                  'status' => true,
                  'message' => 'Emojis successfully saved.',
               ]);
            } else {
               return $this->response->setJSON([
                  'status' => false,
                  'message' => 'Failed to save emojis in database.'
               ]);
            }
         }
      } catch (\Throwable $e) {

         log_message('error', 'addEmoji failed: ' . $e->getMessage());

         return $this->response->setJSON([
            'status' => false,
            'message' => 'Failed to save emojis. Please try again.',
         ]);
      }
   }
}
