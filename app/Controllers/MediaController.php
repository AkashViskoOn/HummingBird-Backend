<?php

namespace App\Controllers;

use CodeIgniter\HTTP\ResponseInterface;

class MediaController extends BaseController
{
    public function compressMedia()
    {
        helper('token');
        if (!check_jwt_authentication()) {
            return $this->response->setJSON(['status' => false, 'message' => 'Unauthorized']);
        }
        
        $file = $this->request->getFile('media_file');
        if (!$file || !$file->isValid()) {
            return $this->response->setJSON(['status' => false, 'message' => 'No file uploaded']);
        }
        
        try {
            $quality = $this->request->getPost('quality') ?? 'medium';
            $originalPath = $file->getTempName();
            $originalSize = $file->getSize();
            
            $mime = $file->getMimeType();
            $ext = $file->getExtension();
            $newName = 'compressed_' . $file->getRandomName();
            $outputDir = (new \App\Services\MediaUploadService())->ensureWritableSubdir('compressed');
            $outputPath = $outputDir . DIRECTORY_SEPARATOR . $newName;
            
            if (strpos($mime, 'image') !== false) {
                $this->compressImage($originalPath, $outputPath, $quality);
            } elseif (strpos($mime, 'video') !== false) {
                $this->compressVideo($originalPath, $outputPath, $quality);
            } elseif (strpos($mime, 'audio') !== false) {
                $this->compressAudio($originalPath, $outputPath, $quality);
            } else {
                copy($originalPath, $outputPath);
            }
            
            $compressedSize = file_exists($outputPath) ? filesize($outputPath) : $originalSize;
            
            return $this->response->setJSON([
                'status' => true,
                'message' => 'Media compressed successfully',
                'data' => [
                    'filename' => $newName,
                    'original_size' => $originalSize,
                    'compressed_size' => $compressedSize,
                    'savings_percent' => $originalSize > 0 ? round((1 - $compressedSize / $originalSize) * 100, 1) : 0,
                    'url' => base_url('uploads/compressed/' . $newName)
                ]
            ]);
        } catch (\Exception $e) {
            log_message('error', '[MediaController] compressMedia error: ' . $e->getMessage());
            return $this->response->setJSON(['status' => false, 'message' => 'Compression failed: ' . $e->getMessage()]);
        }
    }
    
    private function compressImage($source, $dest, $quality)
    {
        $qualityMap = ['low' => 30, 'medium' => 60, 'high' => 80];
        $jpgQuality = $qualityMap[$quality] ?? 60;
        
        $info = getimagesize($source);
        if (!$info) return copy($source, $dest);
        
        $mime = $info['mime'];
        switch ($mime) {
            case 'image/jpeg':
                $image = imagecreatefromjpeg($source);
                imagejpeg($image, $dest, $jpgQuality);
                break;
            case 'image/png':
                $image = imagecreatefrompng($source);
                $pngQuality = 9 - floor($jpgQuality / 10);
                imagepng($image, $dest, min(9, max(0, $pngQuality)));
                break;
            case 'image/gif':
                copy($source, $dest);
                break;
            case 'image/webp':
                $image = imagecreatefromwebp($source);
                imagewebp($image, $dest, $jpgQuality);
                break;
            default:
                copy($source, $dest);
        }
        if (isset($image)) imagedestroy($image);
    }
    
    private function compressVideo($source, $dest, $quality)
    {
        $crfMap = ['low' => 35, 'medium' => 28, 'high' => 23];
        $crf = $crfMap[$quality] ?? 28;
        $preset = $quality === 'high' ? 'slow' : 'fast';
        $success = $this->runFfmpeg([
            '-hide_banner',
            '-loglevel',
            'error',
            '-nostdin',
            '-y',
            '-i',
            $source,
            '-vcodec',
            'libx264',
            '-crf',
            (string)$crf,
            '-preset',
            $preset,
            '-acodec',
            'aac',
            '-b:a',
            '96k',
            $dest,
        ]);
        if (!$success) {
            copy($source, $dest);
        }
    }

    private function compressAudio($source, $dest, $quality)
    {
        $bitrateMap = ['low' => '64k', 'medium' => '96k', 'high' => '128k'];
        $bitrate = $bitrateMap[$quality] ?? '96k';
        $success = $this->runFfmpeg([
            '-hide_banner',
            '-loglevel',
            'error',
            '-nostdin',
            '-y',
            '-i',
            $source,
            '-codec:a',
            'libmp3lame',
            '-b:a',
            $bitrate,
            $dest,
        ]);
        if (!$success) {
            copy($source, $dest);
        }
    }
    
    public function shareMedia()
    {
        helper('token');
        if (!check_jwt_authentication()) {
            return $this->response->setJSON(['status' => false, 'message' => 'Unauthorized']);
        }
        
        $messageId = $this->request->getPost('message_id');
        $targetRoomId = $this->request->getPost('target_room_id');
        
        if (!$messageId || !$targetRoomId) {
            return $this->response->setJSON(['status' => false, 'message' => 'Missing required fields']);
        }
        
        try {
            $messageModel = new \App\Models\MessageModel();
            $message = $messageModel->find($messageId);
            
            if (!$message) {
                return $this->response->setJSON(['status' => false, 'message' => 'Message not found']);
            }
            
            $newMessage = [
                'm_sender_id' => $message['m_sender_id'],
                'm_receiver_id' => $targetRoomId,
                'm_message' => $message['m_message'],
                'm_message_type' => $message['m_message_type'],
                'm_media' => $message['m_media'],
                'm_is_forwarded' => '1',
                'm_created_at' => date('Y-m-d H:i:s'),
                'm_status' => 'pending'
            ];
            
            $messageModel->insert($newMessage);
            $newId = $messageModel->insertID();
            
            $db = \Config\Database::connect();
            if ($db->tableExists('socket_messages')) {
                $db->table('socket_messages')->insert([
                    'sender_id' => $message['m_sender_id'],
                    'receiver_id' => $targetRoomId,
                    'message' => $message['m_message'],
                    'message_type' => $message['m_message_type'],
                    'media' => $message['m_media'],
                    'm_id' => $newId,
                    'is_forwarded' => '1',
                    'created_at' => date('Y-m-d H:i:s')
                ]);
            }
            
            return $this->response->setJSON([
                'status' => true,
                'message' => 'Media shared successfully',
                'data' => ['new_message_id' => (string)$newId]
            ]);
        } catch (\Exception $e) {
            log_message('error', '[MediaController] shareMedia error: ' . $e->getMessage());
            return $this->response->setJSON(['status' => false, 'message' => 'Share failed: ' . $e->getMessage()]);
        }
    }
    
    public function getMediaInfo()
    {
        helper('token');
        if (!check_jwt_authentication()) {
            return $this->response->setJSON(['status' => false, 'message' => 'Unauthorized']);
        }
        
        $messageId = $this->request->getGet('message_id');
        if (!$messageId) {
            return $this->response->setJSON(['status' => false, 'message' => 'Missing message_id']);
        }
        
        try {
            $messageModel = new \App\Models\MessageModel();
            $message = $messageModel->find($messageId);
            
            if (!$message) {
                return $this->response->setJSON(['status' => false, 'message' => 'Message not found']);
            }
            
            $mediaUrl = $message['m_media'] ?? '';
            $mediaPath = (new \App\Services\MediaUploadService())->resolvePath($mediaUrl);
            
            $info = [
                'm_id' => (string)$message['m_id'],
                'm_message_type' => $message['m_message_type'],
                'm_message' => $message['m_message'],
                'm_media' => $message['m_media'],
                'm_created_at' => $message['m_created_at'],
                'm_sender_id' => (string)$message['m_sender_id'],
                'm_receiver_id' => (string)$message['m_receiver_id'],
            ];
            
            if ($mediaPath && file_exists($mediaPath)) {
                $info['file_size'] = filesize($mediaPath);
                $info['mime_type'] = mime_content_type($mediaPath);
            }
            
            return $this->response->setJSON([
                'status' => true,
                'data' => $info
            ]);
        } catch (\Exception $e) {
            log_message('error', '[MediaController] getMediaInfo error: ' . $e->getMessage());
            return $this->response->setJSON(['status' => false, 'message' => $e->getMessage()]);
        }
    }
    
    public function getGalleryMedia()
    {
        helper('token');
        if (!check_jwt_authentication()) {
            return $this->response->setJSON(['status' => false, 'message' => 'Unauthorized']);
        }
        
        $user = user();
        $userId = $user['user_id'];
        $type = $this->request->getGet('type') ?? 'all';
        
        try {
            $messageModel = new \App\Models\MessageModel();
            $db = \Config\Database::connect();
            
            $query = "SELECT m_id, m_message, m_message_type, m_media, m_created_at, m_sender_id, m_receiver_id 
                      FROM messages 
                      WHERE (m_sender_id = ? OR m_receiver_id = ?) 
                      AND m_message_type IN (1,2,3,4,5)";
            
            $params = [$userId, $userId];
            
            if ($type !== 'all') {
                $typeMap = ['image' => 1, 'video' => 4, 'audio' => 3, 'document' => '2,5'];
                if (isset($typeMap[$type])) {
                    $query .= " AND m_message_type IN ({$typeMap[$type]})";
                }
            }
            
            $query .= " ORDER BY m_created_at DESC LIMIT 100";
            
            $results = $db->query($query, $params)->getResultArray();
            
            $grouped = [];
            foreach ($results as &$row) {
                $date = date('Y-m-d', strtotime($row['m_created_at']));
                $grouped[$date][] = $row;
            }
            
            return $this->response->setJSON([
                'status' => true,
                'data' => $grouped
            ]);
        } catch (\Exception $e) {
            log_message('error', '[MediaController] getGalleryMedia error: ' . $e->getMessage());
            return $this->response->setJSON(['status' => false, 'message' => $e->getMessage()]);
        }
    }
    
    public function trimVideo()
    {
        helper('token');
        if (!check_jwt_authentication()) {
            return $this->response->setJSON(['status' => false, 'message' => 'Unauthorized']);
        }

        $file = $this->request->getFile('video_file');
        $rawStartTime = $this->request->getPost('start_time');
        $rawEndTime = $this->request->getPost('end_time');
        $startTime = $this->parseTimestamp($rawStartTime === null || $rawStartTime === '' ? '0' : $rawStartTime);
        $endTime = $rawEndTime === null || $rawEndTime === ''
            ? null
            : $this->parseTimestamp($rawEndTime);

        if (!$file || !$file->isValid()) {
            return $this->response->setJSON(['status' => false, 'message' => 'No video file uploaded']);
        }
        if ($startTime === null || ($rawEndTime !== null && $rawEndTime !== '' && $endTime === null)
            || ($endTime !== null && $endTime <= $startTime)
        ) {
            return $this->response->setStatusCode(422)->setJSON([
                'status' => false,
                'message' => 'Invalid video trim range',
            ]);
        }

        try {
            $newName = 'trimmed_' . $file->getRandomName() . '.mp4';
            $outputDir = (new \App\Services\MediaUploadService())->ensureWritableSubdir('trimmed');
            $outputPath = $outputDir . DIRECTORY_SEPARATOR . $newName;
            $arguments = [
                '-hide_banner',
                '-loglevel',
                'error',
                '-nostdin',
                '-y',
                '-i',
                $file->getTempName(),
                '-ss',
                (string)$startTime,
            ];
            if ($endTime !== null) {
                $arguments[] = '-to';
                $arguments[] = (string)$endTime;
            }
            $arguments[] = '-c';
            $arguments[] = 'copy';
            $arguments[] = $outputPath;

            if (!$this->runFfmpeg($arguments) || !is_file($outputPath) || filesize($outputPath) === 0) {
                @unlink($outputPath);
                return $this->response->setStatusCode(500)->setJSON([
                    'status' => false,
                    'message' => 'Video trimming failed',
                ]);
            }

            return $this->response->setJSON([
                'status' => true,
                'message' => 'Video trimmed successfully',
                'data' => [
                    'url' => base_url('uploads/trimmed/' . $newName),
                    'filename' => $newName,
                ],
            ]);
        } catch (\Throwable $e) {
            log_message('error', '[MediaController] trimVideo error: ' . $e->getMessage());
            return $this->response->setJSON([
                'status' => false,
                'message' => 'Video trimming failed',
            ]);
        }
    }
    
    private function parseTimestamp($value): ?float
    {
        if (!is_scalar($value)) {
            return null;
        }

        $value = trim((string)$value);
        if ($value === '' || preg_match('/^[0-9]+(?:\\.[0-9]+)?$/', $value) !== 1) {
            return null;
        }

        $seconds = (float)$value;
        return is_finite($seconds) && $seconds >= 0 && $seconds <= 86400
            ? $seconds
            : null;
    }

    private function runFfmpeg(array $arguments): bool
    {
        $binary = getenv('FFMPEG_BINARY') ?: 'ffmpeg';
        if (!is_string($binary) || $binary === '' || strpos($binary, "\0") !== false) {
            return false;
        }

        $logPath = tempnam(sys_get_temp_dir(), 'hummingbird_ffmpeg_');
        $logTarget = $logPath ?: (DIRECTORY_SEPARATOR === '\\' ? 'NUL' : '/dev/null');
        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['file', $logTarget, 'w'],
            2 => ['file', $logTarget, 'a'],
        ];
        $pipes = [];
        $process = @proc_open(
            array_merge([$binary], $arguments),
            $descriptorSpec,
            $pipes
        );

        if (!is_resource($process)) {
            if ($logPath && is_file($logPath)) {
                @unlink($logPath);
            }
            return false;
        }

        if (isset($pipes[0]) && is_resource($pipes[0])) {
            fclose($pipes[0]);
        }
        $exitCode = proc_close($process);
        if ($logPath && is_file($logPath)) {
            @unlink($logPath);
        }

        return $exitCode === 0;
    }

    public function serveCompressed($filename = null)
    {
        if (!$filename) {
            return $this->response->setStatusCode(404);
        }
        
        $path = WRITEPATH . 'uploads/compressed/' . basename($filename);
        if (!file_exists($path)) {
            return $this->response->setStatusCode(404);
        }
        
        return $this->response->download($path, null)->setFileName($filename);
    }
    
    public function serveTrimmed($filename = null)
    {
        if (!$filename) {
            return $this->response->setStatusCode(404);
        }
        
        $path = WRITEPATH . 'uploads/trimmed/' . basename($filename);
        if (!file_exists($path)) {
            return $this->response->setStatusCode(404);
        }
        
        return $this->response->download($path, null)->setFileName($filename);
    }

    public function serveUpload(...$segments)
    {
        if (empty($segments)) {
            return $this->response->setStatusCode(404);
        }

        $path = implode('/', $segments);
        $clean = ltrim(str_replace('..', '', $path), '/\\');
        $absolute = WRITEPATH . str_replace('/', DIRECTORY_SEPARATOR, 'uploads/' . $clean);

        if (!is_file($absolute)) {
            return $this->response->setStatusCode(404);
        }

        $mime = mime_content_type($absolute) ?: 'application/octet-stream';
        return $this->response
            ->setHeader('Content-Type', $mime)
            ->setHeader('Content-Disposition', 'inline; filename="' . basename($absolute) . '"')
            ->setHeader('Cache-Control', 'public, max-age=31536000, immutable')
            ->setBody(file_get_contents($absolute));
    }
}
