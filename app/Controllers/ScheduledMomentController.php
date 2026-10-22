<?php

namespace App\Controllers;

use App\Controllers\BaseController;
use App\Models\ScheduledMomentModel;
use App\Models\StatusUpdateModel;
use App\Models\UserModel;
use App\Models\UserAvailabilityModel;

class ScheduledMomentController extends BaseController
{
    private ?ScheduledMomentModel $momentModel = null;
    private ?UserModel $userModel = null;
    private ?UserAvailabilityModel $availabilityModel = null;

    private function getModel(): ScheduledMomentModel
    {
        if ($this->momentModel === null) {
            $this->momentModel = new ScheduledMomentModel();
        }
        return $this->momentModel;
    }

    private function getUserModel(): UserModel
    {
        if ($this->userModel === null) {
            $this->userModel = new UserModel();
        }
        return $this->userModel;
    }

    private function getAvailabilityModel(): UserAvailabilityModel
    {
        if ($this->availabilityModel === null) {
            $this->availabilityModel = new UserAvailabilityModel();
        }
        return $this->availabilityModel;
    }

    private function auth(): ?object
    {
        $token = check_jwt_authentication();
        if (!$token) {
            $this->response->setJSON([
                'status' => false, 'message' => 'Authorization Failed'
            ])->send();
            return null;
        }
        return $token;
    }

    private function uploadFile(string $field, string $subdir = 'moments'): string    {
        $file = $this->request->getFile($field);
        if (!$file || !$file->isValid() || $file->hasMoved()) return '';

        try {
            $uploadService = new \App\Services\MediaUploadService();
            $stored = $uploadService->store($file, 'files/' . $subdir);
            return $stored['web_path'];
        } catch (\Throwable $e) {
            log_message('error', 'ScheduledMoment upload failed: ' . $e->getMessage());
            return '';
        }
    }

    private function detectMediaType(string $url): string
    {
        if (empty($url)) return 'text';
        $ext = strtolower(pathinfo($url, PATHINFO_EXTENSION));
        if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'])) return 'image';
        if (in_array($ext, ['mp4', 'mov', 'avi', 'mkv', 'webm'])) return 'video';
        return 'file';
    }

    /**
     * Creates (or refreshes) the linked status_updates row for a published
     * moment so it appears in friends' status feeds. Returns the status row.
     */
    private function syncPublishedStatus(array $moment): ?array
    {
        try {
            $statusModel = new StatusUpdateModel();
            $mediaUrl = '';
            $mediaType = 'text';
            if (!empty($moment['sm_image'])) {
                $mediaUrl = $moment['sm_image'];
                $mediaType = 'image';
            } elseif (!empty($moment['sm_video'])) {
                $mediaUrl = $moment['sm_video'];
                $mediaType = 'video';
            }

            $publishedAt = $moment['sm_published_datetime'] ?: date('Y-m-d H:i:s');
            $expiryHours = max(1, min(24, (int)($moment['sm_expiry_hours'] ?: 24)));
            $expiresAt = date('Y-m-d H:i:s', strtotime("+{$expiryHours} hours", strtotime($publishedAt)));

            // Map moment visibilities onto status privacy values. Status
            // privacy only knows everyone/contacts/contacts_except/only_share/
            // only_me — unmapped values (family/close_friends) would fall back
            // to "everyone" in StatusController and leak the moment.
            $statusPrivacy = 'everyone';
            if ($moment['sm_visibility'] === 'only_me') {
                $statusPrivacy = 'only_me';
            } elseif (in_array($moment['sm_visibility'], ['contacts', 'family', 'close_friends'], true)) {
                $statusPrivacy = 'contacts';
            }

            $data = [
                'su_user_id' => $moment['sm_user_id'],
                'su_content' => $moment['sm_caption'] ?: '',
                'su_media_url' => $mediaUrl,
                'su_media_type' => $mediaType,
                'su_schedule_id' => 'sm_' . $moment['sm_id'],
                'su_created_at' => $publishedAt,
                'su_expires_at' => $expiresAt,
                'su_is_archived' => '0',
                'su_privacy' => $statusPrivacy,
                'su_caption' => $moment['sm_caption'] ?: null,
            ];

            $existing = $statusModel
                ->where('su_schedule_id', 'sm_' . $moment['sm_id'])
                ->first();

            if ($existing) {
                $statusModel->update($existing['su_id'], $data);
                return $statusModel->find($existing['su_id']);
            }

            $statusModel->save($data);
            return $statusModel->find($statusModel->getInsertID());
        } catch (\Throwable $e) {
            log_message('error', 'Moment→status sync failed: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Deletes the status_updates row linked to a moment (hard delete, so the
     * moment disappears from feeds immediately).
     */
    private function deleteLinkedStatus(array $moment): void
    {
        try {
            $statusModel = new StatusUpdateModel();
            $existing = $statusModel
                ->where('su_schedule_id', 'sm_' . $moment['sm_id'])
                ->first();
            if ($existing) {
                $statusModel->delete($existing['su_id']);
            }
        } catch (\Throwable $e) {
            log_message('error', 'Moment→status delete failed: ' . $e->getMessage());
        }
    }

    // POST /moments/schedule
    public function create()
    {
        $token = $this->auth();
        if (!$token) return;

        try {
            $userId = $token->user_id;

            $content = $this->request->getVar('content') ?? '';
            $mediaType = $this->request->getVar('media_type') ?: 'text';
            $caption = $this->request->getVar('caption') ?? '';
            // The app sends the user's typed text as `content` and an optional
            // separate `caption`; sm_caption is the only persisted text column,
            // so fall back to content when no explicit caption was given —
            // otherwise text-only moments would publish empty statuses.
            if (trim((string)$caption) === '' && trim((string)$content) !== '') {
                $caption = $content;
            }
            $visibility = $this->request->getVar('visibility') ?: 'everyone';
            $expiryHours = max(1, min(24, (int)($this->request->getVar('expiry_hours') ?: 24)));
            $scheduledAt = $this->request->getVar('scheduled_at');
            $publishType = $scheduledAt ? 'schedule' : 'now';

            $validVisibilities = ['everyone', 'contacts', 'family', 'close_friends', 'only_me'];
            if (!in_array($visibility, $validVisibilities)) {
                return $this->response->setJSON([
                    'status' => false, 'message' => 'Invalid visibility'
                ]);
            }

            if (mb_strlen($caption) > 500) {
                return $this->response->setJSON([
                    'status' => false, 'message' => 'Caption too long (max 500 characters)'
                ]);
            }

            $imageUrl = '';
            $videoUrl = '';
            $thumbUrl = '';

            $mediaFile = $this->request->getFile('media_file');
            if ($mediaFile && $mediaFile->isValid() && !$mediaFile->hasMoved()) {
                $ext = strtolower($mediaFile->getExtension());
                $isImage = in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp']);
                $isVideo = in_array($ext, ['mp4', 'mov', 'avi', 'mkv', 'webm']);

                if ($isImage) {
                    $imageUrl = $this->uploadFile('media_file');
                    $mediaType = 'image';
                } elseif ($isVideo) {
                    $videoUrl = $this->uploadFile('media_file');
                    $mediaType = 'video';
                }
            }

            $scheduledDatetime = null;
            if ($scheduledAt) {
                if (is_numeric($scheduledAt)) {
                    $scheduledDatetime = date('Y-m-d H:i:s', (int)$scheduledAt);
                } else {
                    $scheduledDatetime = $scheduledAt;
                }
            }

            $now = date('Y-m-d H:i:s');
            $status = $publishType === 'now' ? 'published' : 'scheduled';
            $publishedDatetime = $publishType === 'now' ? $now : null;

            $data = [
                'sm_user_id' => $userId,
                'sm_story_type' => 'moment',
                'sm_image' => $imageUrl,
                'sm_video' => $videoUrl,
                'sm_thumbnail' => $thumbUrl,
                'sm_caption' => $caption,
                'sm_visibility' => $visibility,
                'sm_publish_type' => $publishType,
                'sm_scheduled_datetime' => $scheduledDatetime,
                'sm_published_datetime' => $publishedDatetime,
                'sm_expiry_hours' => $expiryHours,
                'sm_status' => $status,
                'sm_draft' => 0,
                'sm_view_count' => 0,
                'sm_reply_count' => 0,
                'sm_share_count' => 0,
                'sm_created_at' => $now,
            ];

            if ($this->getModel()->save($data)) {
                $insertId = $this->getModel()->getInsertID();
                $saved = $this->getModel()->find($insertId);

                if ($publishType === 'now') {
                    $this->syncPublishedStatus($saved);
                }

                $this->broadcastMomentEvent('moment-created', $saved, [(int)($saved['sm_user_id'] ?? 0)]);

                return $this->response->setJSON([
                    'status' => true,
                    'message' => $publishType === 'now' ? 'Moment published' : 'Moment scheduled',
                    'data' => $saved,
                ]);
            }

            return $this->response->setJSON([
                'status' => false, 'message' => 'Failed to create moment'
            ]);
        } catch (\Throwable $e) {
            return $this->response->setJSON([
                'status' => false, 'message' => $e->getMessage()
            ]);
        }
    }

    // GET /moments/list
    public function list()
    {
        $token = $this->auth();
        if (!$token) return;

        try {
            $userId = $token->user_id;
            $page = (int)($this->request->getVar('page') ?: 1);
            $perPage = (int)($this->request->getVar('per_page') ?: 20);
            $offset = ($page - 1) * $perPage;

            $moments = $this->getModel()
                ->where('sm_user_id', $userId)
                ->where('sm_draft', 0)
                ->where('sm_deleted_at', null)
                ->where('sm_status !=', 'cancelled')
                ->orderBy('sm_created_at', 'DESC')
                ->findAll($perPage, $offset);

            $total = $this->getModel()
                ->where('sm_user_id', $userId)
                ->where('sm_draft', 0)
                ->where('sm_deleted_at', null)
                ->where('sm_status !=', 'cancelled')
                ->countAllResults();

            return $this->response->setJSON([
                'status' => true,
                'message' => 'Moments fetched',
                'data' => [
                    'moments' => $moments,
                    'total' => $total,
                    'page' => $page,
                    'per_page' => $perPage,
                ],
            ]);
        } catch (\Throwable $e) {
            return $this->response->setJSON([
                'status' => false, 'message' => $e->getMessage()
            ]);
        }
    }

    // GET /moments/published
    public function published()
    {
        $token = $this->auth();
        if (!$token) return;

        try {
            $userId = $token->user_id;
            $page = (int)($this->request->getVar('page') ?: 1);
            $perPage = (int)($this->request->getVar('per_page') ?: 20);
            $offset = ($page - 1) * $perPage;

            $moments = $this->getModel()
                ->where('sm_user_id', $userId)
                ->where('sm_status', 'published')
                ->where('sm_deleted_at', null)
                ->orderBy('sm_published_datetime', 'DESC')
                ->findAll($perPage, $offset);

            $total = $this->getModel()
                ->where('sm_user_id', $userId)
                ->where('sm_status', 'published')
                ->where('sm_deleted_at', null)
                ->countAllResults();

            return $this->response->setJSON([
                'status' => true,
                'message' => 'Published moments fetched',
                'data' => [
                    'moments' => $moments,
                    'total' => $total,
                    'page' => $page,
                    'per_page' => $perPage,
                ],
            ]);
        } catch (\Throwable $e) {
            return $this->response->setJSON([
                'status' => false, 'message' => $e->getMessage()
            ]);
        }
    }

    // GET /moments/details/{id}
    public function details($id)
    {
        $token = $this->auth();
        if (!$token) return;

        try {
            $moment = $this->getModel()->find($id);
            if (!$moment || $moment['sm_deleted_at'] !== null) {
                return $this->response->setJSON([
                    'status' => false, 'message' => 'Moment not found'
                ]);
            }

            // Only the owner may view drafts/scheduled/cancelled moments;
            // everyone else may only view published ones.
            $userId = $token->user_id;
            if ((int)$moment['sm_user_id'] !== (int)$userId && $moment['sm_status'] !== 'published') {
                return $this->response->setJSON([
                    'status' => false, 'message' => 'Not allowed to view this moment'
                ]);
            }

            $author = $this->getUserModel()->find($moment['sm_user_id']);

            return $this->response->setJSON([
                'status' => true,
                'message' => 'Moment details fetched',
                'data' => [
                    'moment' => $moment,
                    'author' => $author,
                ],
            ]);
        } catch (\Throwable $e) {
            return $this->response->setJSON([
                'status' => false, 'message' => $e->getMessage()
            ]);
        }
    }

    // POST /moments/edit
    public function edit()
    {
        $token = $this->auth();
        if (!$token) return;

        try {
            $userId = $token->user_id;
            $momentId = $this->request->getVar('moment_id');

            $moment = $this->getModel()->find($momentId);
            if (!$moment || $moment['sm_deleted_at'] !== null) {
                return $this->response->setJSON([
                    'status' => false, 'message' => 'Moment not found'
                ]);
            }
            if ($moment['sm_user_id'] != $userId) {
                return $this->response->setJSON([
                    'status' => false, 'message' => 'Unauthorized'
                ]);
            }

            $updateData = [];
            $caption = $this->request->getVar('caption');
            if ($caption === null) {
                // The app may send the edited text as `content` — treat it as
                // the caption so text edits are not silently dropped.
                $contentAlias = $this->request->getVar('content');
                if ($contentAlias !== null) {
                    $caption = $contentAlias;
                }
            }
            if ($caption !== null) {
                if (mb_strlen($caption) > 500) {
                    return $this->response->setJSON([
                        'status' => false, 'message' => 'Caption too long (max 500)'
                    ]);
                }
                $updateData['sm_caption'] = $caption;
            }

            $visibility = $this->request->getVar('visibility');
            if ($visibility !== null) {
                $valid = ['everyone', 'contacts', 'family', 'close_friends', 'only_me'];
                if (!in_array($visibility, $valid)) {
                    return $this->response->setJSON([
                        'status' => false, 'message' => 'Invalid visibility'
                    ]);
                }
                $updateData['sm_visibility'] = $visibility;
            }

            $scheduledAt = $this->request->getVar('scheduled_at');
            if ($scheduledAt !== null) {
                $updateData['sm_scheduled_datetime'] = is_numeric($scheduledAt)
                    ? date('Y-m-d H:i:s', (int)$scheduledAt)
                    : $scheduledAt;
            }

            $expiryHours = $this->request->getVar('expiry_hours');
            if ($expiryHours !== null) {
                $updateData['sm_expiry_hours'] = max(1, min(24, (int)$expiryHours));
            }

            $mediaFile = $this->request->getFile('media_file');
            if ($mediaFile && $mediaFile->isValid() && !$mediaFile->hasMoved()) {
                $ext = strtolower($mediaFile->getExtension());
                if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'])) {
                    $updateData['sm_image'] = $this->uploadFile('media_file');
                } elseif (in_array($ext, ['mp4', 'mov', 'avi', 'mkv', 'webm'])) {
                    $updateData['sm_video'] = $this->uploadFile('media_file');
                }
            }

            if (empty($updateData)) {
                return $this->response->setJSON([
                    'status' => false, 'message' => 'Nothing to update'
                ]);
            }

            $updateData['sm_updated_at'] = date('Y-m-d H:i:s');
            $this->getModel()->update($momentId, $updateData);
            $saved = $this->getModel()->find($momentId);

            $this->broadcastMomentEvent('moment-updated', $saved, [(int)($saved['sm_user_id'] ?? 0)]);

            return $this->response->setJSON([
                'status' => true,
                'message' => 'Moment updated',
                'data' => $saved,
            ]);
        } catch (\Throwable $e) {
            return $this->response->setJSON([
                'status' => false, 'message' => $e->getMessage()
            ]);
        }
    }

    // POST /moments/duplicate
    public function duplicate()
    {
        $token = $this->auth();
        if (!$token) return;

        try {
            $userId = $token->user_id;
            $momentId = $this->request->getVar('moment_id');

            $original = $this->getModel()->find($momentId);
            if (!$original || $original['sm_deleted_at'] !== null) {
                return $this->response->setJSON([
                    'status' => false, 'message' => 'Moment not found'
                ]);
            }
            if ($original['sm_user_id'] != $userId) {
                return $this->response->setJSON([
                    'status' => false, 'message' => 'Unauthorized'
                ]);
            }

            $now = date('Y-m-d H:i:s');
            $data = [
                'sm_user_id' => $userId,
                'sm_story_type' => $original['sm_story_type'],
                'sm_image' => $original['sm_image'],
                'sm_video' => $original['sm_video'],
                'sm_thumbnail' => $original['sm_thumbnail'],
                'sm_caption' => $original['sm_caption'],
                'sm_visibility' => $original['sm_visibility'],
                'sm_publish_type' => 'draft',
                'sm_scheduled_datetime' => null,
                'sm_published_datetime' => null,
                'sm_expiry_hours' => $original['sm_expiry_hours'],
                'sm_status' => 'draft',
                'sm_draft' => 1,
                'sm_view_count' => 0,
                'sm_reply_count' => 0,
                'sm_share_count' => 0,
                'sm_created_at' => $now,
            ];

            if ($this->getModel()->save($data)) {
                $insertId = $this->getModel()->getInsertID();
                $saved = $this->getModel()->find($insertId);
                return $this->response->setJSON([
                    'status' => true,
                    'message' => 'Moment duplicated as draft',
                    'data' => $saved,
                ]);
            }

            return $this->response->setJSON([
                'status' => false, 'message' => 'Failed to duplicate'
            ]);
        } catch (\Throwable $e) {
            return $this->response->setJSON([
                'status' => false, 'message' => $e->getMessage()
            ]);
        }
    }

    // DELETE /moments/delete/{id}
    public function delete($id)
    {
        $token = $this->auth();
        if (!$token) return;

        try {
            $userId = $token->user_id;
            $moment = $this->getModel()->find($id);

            if (!$moment || $moment['sm_deleted_at'] !== null) {
                return $this->response->setJSON([
                    'status' => false, 'message' => 'Moment not found'
                ]);
            }
            if ($moment['sm_user_id'] != $userId) {
                return $this->response->setJSON([
                    'status' => false, 'message' => 'Unauthorized'
                ]);
            }

            $this->getModel()->update($id, [
                'sm_deleted_at' => date('Y-m-d H:i:s'),
                'sm_status' => 'deleted',
            ]);

            $this->deleteLinkedStatus($moment);
            $this->broadcastMomentEvent(
                'moment-deleted',
                ['sm_id' => $id, 'sm_user_id' => $moment['sm_user_id']],
                [(int)$moment['sm_user_id']]
            );

            return $this->response->setJSON([
                'status' => true,
                'message' => 'Moment deleted',
            ]);
        } catch (\Throwable $e) {
            return $this->response->setJSON([
                'status' => false, 'message' => $e->getMessage()
            ]);
        }
    }

    // GET /moments/drafts
    public function drafts()
    {
        $token = $this->auth();
        if (!$token) return;

        try {
            $userId = $token->user_id;
            $page = (int)($this->request->getVar('page') ?: 1);
            $perPage = (int)($this->request->getVar('per_page') ?: 20);
            $offset = ($page - 1) * $perPage;

            $moments = $this->getModel()
                ->where('sm_user_id', $userId)
                ->where('sm_draft', 1)
                ->where('sm_deleted_at', null)
                ->orderBy('sm_created_at', 'DESC')
                ->findAll($perPage, $offset);

            $total = $this->getModel()
                ->where('sm_user_id', $userId)
                ->where('sm_draft', 1)
                ->where('sm_deleted_at', null)
                ->countAllResults();

            return $this->response->setJSON([
                'status' => true,
                'message' => 'Drafts fetched',
                'data' => [
                    'moments' => $moments,
                    'total' => $total,
                    'page' => $page,
                    'per_page' => $perPage,
                ],
            ]);
        } catch (\Throwable $e) {
            return $this->response->setJSON([
                'status' => false, 'message' => $e->getMessage()
            ]);
        }
    }

    // POST /moments/save-draft
    public function saveDraft()
    {
        $token = $this->auth();
        if (!$token) return;

        try {
            $userId = $token->user_id;
            $content = $this->request->getVar('content') ?? '';
            $caption = $this->request->getVar('caption') ?? '';
            // Same content→caption fallback as create(): drafts must keep the
            // user's typed text, not just the optional caption field.
            if (trim((string)$caption) === '' && trim((string)$content) !== '') {
                $caption = $content;
            }
            $mediaType = $this->request->getVar('media_type') ?: 'text';
            $visibility = $this->request->getVar('visibility') ?: 'everyone';
            $expiryHours = max(1, min(24, (int)($this->request->getVar('expiry_hours') ?: 24)));
            $scheduledAt = $this->request->getVar('scheduled_at');

            $validVisibilities = ['everyone', 'contacts', 'family', 'close_friends', 'only_me'];
            if (!in_array($visibility, $validVisibilities)) {
                return $this->response->setJSON([
                    'status' => false, 'message' => 'Invalid visibility'
                ]);
            }

            $imageUrl = '';
            $videoUrl = '';

            $mediaFile = $this->request->getFile('media_file');
            if ($mediaFile && $mediaFile->isValid() && !$mediaFile->hasMoved()) {
                $ext = strtolower($mediaFile->getExtension());
                if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'])) {
                    $imageUrl = $this->uploadFile('media_file');
                } elseif (in_array($ext, ['mp4', 'mov', 'avi', 'mkv', 'webm'])) {
                    $videoUrl = $this->uploadFile('media_file');
                }
            }

            $scheduledDatetime = null;
            if ($scheduledAt) {
                $scheduledDatetime = is_numeric($scheduledAt)
                    ? date('Y-m-d H:i:s', (int)$scheduledAt)
                    : $scheduledAt;
            }

            $data = [
                'sm_user_id' => $userId,
                'sm_story_type' => 'moment',
                'sm_image' => $imageUrl,
                'sm_video' => $videoUrl,
                'sm_caption' => $caption,
                'sm_visibility' => $visibility,
                'sm_publish_type' => 'draft',
                'sm_scheduled_datetime' => $scheduledDatetime,
                'sm_published_datetime' => null,
                'sm_expiry_hours' => $expiryHours,
                'sm_status' => 'draft',
                'sm_draft' => 1,
                'sm_view_count' => 0,
                'sm_reply_count' => 0,
                'sm_share_count' => 0,
                'sm_created_at' => date('Y-m-d H:i:s'),
            ];

            if ($this->getModel()->save($data)) {
                $insertId = $this->getModel()->getInsertID();
                $saved = $this->getModel()->find($insertId);
                return $this->response->setJSON([
                    'status' => true,
                    'message' => 'Draft saved',
                    'data' => $saved,
                ]);
            }

            return $this->response->setJSON([
                'status' => false, 'message' => 'Failed to save draft'
            ]);
        } catch (\Throwable $e) {
            return $this->response->setJSON([
                'status' => false, 'message' => $e->getMessage()
            ]);
        }
    }

    // DELETE /moments/delete-draft/{id}
    public function deleteDraft($id)
    {
        $token = $this->auth();
        if (!$token) return;

        try {
            $userId = $token->user_id;
            $moment = $this->getModel()->find($id);

            if (!$moment || $moment['sm_deleted_at'] !== null) {
                return $this->response->setJSON([
                    'status' => false, 'message' => 'Draft not found'
                ]);
            }
            if ($moment['sm_user_id'] != $userId) {
                return $this->response->setJSON([
                    'status' => false, 'message' => 'Unauthorized'
                ]);
            }

            $this->getModel()->update($id, [
                'sm_deleted_at' => date('Y-m-d H:i:s'),
                'sm_status' => 'deleted',
            ]);

            return $this->response->setJSON([
                'status' => true,
                'message' => 'Draft deleted',
            ]);
        } catch (\Throwable $e) {
            return $this->response->setJSON([
                'status' => false, 'message' => $e->getMessage()
            ]);
        }
    }

    // GET /moments/calendar
    public function calendar()
    {
        $token = $this->auth();
        if (!$token) return;

        try {
            $userId = $token->user_id;
            $year = (int)($this->request->getVar('year') ?: date('Y'));
            $month = (int)($this->request->getVar('month') ?: date('m'));

            $startDate = "{$year}-{$month}-01 00:00:00";
            $endDate = date('Y-m-t 23:59:59', strtotime($startDate));

            $moments = $this->getModel()
                ->where('sm_user_id', $userId)
                ->where('sm_deleted_at', null)
                ->where('sm_created_at >=', $startDate)
                ->where('sm_created_at <=', $endDate)
                ->orderBy('sm_created_at', 'ASC')
                ->findAll();

            $calendar = [];
            foreach ($moments as $m) {
                $day = date('Y-m-d', strtotime($m['sm_created_at']));
                if (!isset($calendar[$day])) {
                    $calendar[$day] = [
                        'date' => $day,
                        'total' => 0,
                        'pending' => 0,
                        'published' => 0,
                        'expired' => 0,
                        'draft' => 0,
                        'moments' => [],
                    ];
                }
                $calendar[$day]['total']++;
                $status = $m['sm_status'];
                if (isset($calendar[$day][$status])) {
                    $calendar[$day][$status]++;
                }
                $calendar[$day]['moments'][] = $m;
            }

            return $this->response->setJSON([
                'status' => true,
                'message' => 'Calendar data fetched',
                'data' => array_values($calendar),
            ]);
        } catch (\Throwable $e) {
            return $this->response->setJSON([
                'status' => false, 'message' => $e->getMessage()
            ]);
        }
    }

    // POST /moments/upload
    public function upload()
    {
        $token = $this->auth();
        if (!$token) return;

        try {
            $file = $this->request->getFile('media_file');
            if (!$file || !$file->isValid() || $file->hasMoved()) {
                return $this->response->setJSON([
                    'status' => false, 'message' => 'No valid file uploaded'
                ]);
            }

            $maxSize = 50 * 1024 * 1024;
            if ($file->getSize() > $maxSize) {
                return $this->response->setJSON([
                    'status' => false, 'message' => 'File too large (max 50MB)'
                ]);
            }

            $ext = strtolower($file->getExtension());
            $allowedImage = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
            $allowedVideo = ['mp4', 'mov', 'avi', 'mkv', 'webm'];

            if (!in_array($ext, array_merge($allowedImage, $allowedVideo))) {
                return $this->response->setJSON([
                    'status' => false, 'message' => 'Invalid file type'
                ]);
            }

            $subdir = in_array($ext, $allowedImage) ? 'moment_images' : 'moment_videos';
            $url = $this->uploadFile('media_file', $subdir);

            if (empty($url)) {
                return $this->response->setJSON([
                    'status' => false, 'message' => 'Upload failed'
                ]);
            }

            return $this->response->setJSON([
                'status' => true,
                'message' => 'File uploaded',
                'data' => [
                    'url' => $url,
                    'media_type' => in_array($ext, $allowedImage) ? 'image' : 'video',
                ],
            ]);
        } catch (\Throwable $e) {
            return $this->response->setJSON([
                'status' => false, 'message' => $e->getMessage()
            ]);
        }
    }

    // POST /moments/publish
    public function publish()
    {
        $token = $this->auth();
        if (!$token) return;

        try {
            $userId = $token->user_id;
            $momentId = $this->request->getVar('moment_id');

            $moment = $this->getModel()->find($momentId);
            if (!$moment || $moment['sm_deleted_at'] !== null) {
                return $this->response->setJSON([
                    'status' => false, 'message' => 'Moment not found'
                ]);
            }
            if ($moment['sm_user_id'] != $userId) {
                return $this->response->setJSON([
                    'status' => false, 'message' => 'Unauthorized'
                ]);
            }
            if ($moment['sm_status'] === 'published') {
                return $this->response->setJSON([
                    'status' => false, 'message' => 'Already published'
                ]);
            }
            if (in_array($moment['sm_status'], ['cancelled', 'expired'], true)) {
                return $this->response->setJSON([
                    'status' => false, 'message' => 'Cannot publish a ' . $moment['sm_status'] . ' moment'
                ]);
            }

            $now = date('Y-m-d H:i:s');
            $this->getModel()->update($momentId, [
                'sm_status' => 'published',
                'sm_draft' => 0,
                'sm_publish_type' => 'now',
                'sm_published_datetime' => $now,
                'sm_updated_at' => $now,
            ]);

            $saved = $this->getModel()->find($momentId);
            $statusRow = $this->syncPublishedStatus($saved);
            // Full moment data only to the owner; friends learn about the new
            // story via the audience-targeted status_created broadcast below.
            $this->broadcastMomentEvent('moment-published', $saved, [(int)$userId]);
            if ($statusRow !== null) {
                (new \App\Controllers\StatusController())
                    ->broadcastStatusRowEvent('status_created', $statusRow);
            }

            $this->sendMomentNotification($userId, $saved);

            return $this->response->setJSON([
                'status' => true,
                'message' => 'Moment published',
                'data' => $saved,
            ]);
        } catch (\Throwable $e) {
            return $this->response->setJSON([
                'status' => false, 'message' => $e->getMessage()
            ]);
        }
    }

    // POST /moments/cancel
    public function cancel()
    {
        $token = $this->auth();
        if (!$token) return;

        try {
            $userId = $token->user_id;
            $momentId = $this->request->getVar('moment_id');

            $moment = $this->getModel()->find($momentId);
            if (!$moment || $moment['sm_deleted_at'] !== null) {
                return $this->response->setJSON([
                    'status' => false, 'message' => 'Moment not found'
                ]);
            }
            if ($moment['sm_user_id'] != $userId) {
                return $this->response->setJSON([
                    'status' => false, 'message' => 'Unauthorized'
                ]);
            }

            $this->getModel()->update($momentId, [
                'sm_status' => 'cancelled',
                'sm_updated_at' => date('Y-m-d H:i:s'),
            ]);

            return $this->response->setJSON([
                'status' => true,
                'message' => 'Moment cancelled',
            ]);
        } catch (\Throwable $e) {
            return $this->response->setJSON([
                'status' => false, 'message' => $e->getMessage()
            ]);
        }
    }

    // POST /moments/process-scheduled (called by scheduler - internal only)
    public function processScheduled()
    {
        $internalKey = $this->request->getHeaderLine('X-Internal-Key');
        $expectedKey = getenv('INTERNAL_CRON_KEY');
        if ($expectedKey === false || $expectedKey === '') {
            log_message('error', 'Scheduled moment processing is not configured');
            return $this->response->setStatusCode(503)->setJSON([
                'status' => false,
                'message' => 'Scheduled moment processing is unavailable',
            ]);
        }
        if ($internalKey === '' || !hash_equals($expectedKey, $internalKey)) {
            return $this->response->setStatusCode(401)->setJSON([
                'status' => false,
                'message' => 'Authorization Failed',
            ]);
        }

        $result = $this->runScheduledProcessing();
        return $this->response->setJSON($result);
    }

    public function runScheduledProcessing(): array
    {
        try {
            $now = date('Y-m-d H:i:s');

            $dueMoments = $this->getModel()
                ->where('sm_status', 'scheduled')
                ->where('sm_draft', 0)
                ->where('sm_deleted_at', null)
                ->where('sm_scheduled_datetime <=', $now)
                ->findAll();

            $published = 0;
            $expired = 0;

            foreach ($dueMoments as $moment) {
                $this->getModel()->update($moment['sm_id'], [
                    'sm_status' => 'published',
                    'sm_published_datetime' => $now,
                    'sm_updated_at' => $now,
                ]);

                $saved = $this->getModel()->find($moment['sm_id']);
                $statusRow = $this->syncPublishedStatus($saved);
                // Owner-only moment event + audience-targeted status_created
                // so friends' feeds refresh in real time (see publish()).
                $this->broadcastMomentEvent('moment-published', $saved, [(int)$moment['sm_user_id']]);
                if ($statusRow !== null) {
                    (new \App\Controllers\StatusController())
                        ->broadcastStatusRowEvent('status_created', $statusRow);
                }
                $this->sendMomentNotification($moment['sm_user_id'], $saved);
                $published++;
            }

            $allPublished = $this->getModel()
                ->where('sm_status', 'published')
                ->where('sm_deleted_at', null)
                ->findAll();

            foreach ($allPublished as $moment) {
                $momentExpiry = min(24, (int)($moment['sm_expiry_hours'] ?: 24));
                $expiresAt = date('Y-m-d H:i:s', strtotime("+{$momentExpiry} hours", strtotime($moment['sm_published_datetime'])));
                if ($now >= $expiresAt) {
                    $this->getModel()->update($moment['sm_id'], [
                        'sm_status' => 'expired',
                        'sm_updated_at' => $now,
                    ]);

                    // Expire the linked status row now; the status cron
                    // (cleanupExpired) will hard-delete it shortly.
                    try {
                        $statusModel = new StatusUpdateModel();
                        $linked = $statusModel
                            ->where('su_schedule_id', 'sm_' . $moment['sm_id'])
                            ->first();
                        if ($linked) {
                            $statusModel->update($linked['su_id'], ['su_expires_at' => $now]);
                        }
                    } catch (\Throwable $e) {
                        log_message('error', 'Moment→status expire failed: ' . $e->getMessage());
                    }

                    $this->broadcastMomentEvent(
                        'moment-expired',
                        ['sm_id' => $moment['sm_id'], 'sm_user_id' => $moment['sm_user_id']],
                        [(int)$moment['sm_user_id']]
                    );
                    $expired++;
                }
            }

            return [
                'status' => true,
                'message' => "Processed: {$published} published, {$expired} expired",
                'data' => ['published' => $published, 'expired' => $expired],
            ];
        } catch (\Throwable $e) {
            return [
                'status' => false, 'message' => $e->getMessage()
            ];
        }
    }

    /**
     * Push a moment event through the socket server's /broadcast endpoint.
     * $userIds === null → broadcast to everyone (avoid for owner-scoped data)
     * $userIds = [ids]  → emit only to those users; an empty list skips the
     *                     broadcast entirely so moment data never leaks.
     */
    private function broadcastMomentEvent(string $event, $data, ?array $userIds = null): void
    {
        try {
            $internalKey = getenv('INTERNAL_CRON_KEY');
            if ($internalKey === false || $internalKey === '') {
                log_message('error', 'Socket broadcast is not configured');
                return;
            }
            $payload = [
                'event' => $event,
                'data' => $data,
            ];
            if ($userIds !== null) {
                $userIds = array_values(array_unique(array_map('intval', $userIds)));
                if (empty($userIds)) {
                    return;
                }
                $payload['userIds'] = $userIds;
            }
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => getenv('SOCKET_BROADCAST_URL') ?: 'http://localhost:4000/broadcast',
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => json_encode($payload),
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json',
                    'X-Internal-Key: ' . $internalKey,
                ],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 5,
                CURLOPT_CONNECTTIMEOUT => 3,
            ]);
            curl_exec($ch);
            curl_close($ch);
        } catch (\Throwable $e) {
            log_message('error', 'Broadcast failed: ' . $e->getMessage());
        }
    }

    private function sendMomentNotification(int $userId, array $moment): void
    {
        try {
            $user = $this->getUserModel()->find($userId);
            if (!$user) return;

            $fcmToken = $user['fcm_token'] ?? '';
            if (empty($fcmToken)) return;

            $caption = !empty($moment['sm_caption'])
                ? $moment['sm_caption']
                : (!empty($moment['sm_image']) ? '📷 Photo Moment' : (!empty($moment['sm_video']) ? '🎬 Video Moment' : 'New Moment'));

            // This FCM message goes to the moment's owner themselves —
            // phrase it as their own moment going live, not someone else's.
            $title = 'Your moment is now live';
            $body = $caption;

            $payload = [
                'type' => 'moment_published',
                'moment_id' => $moment['sm_id'],
                'title' => $title,
                'body' => $body,
            ];

            $fcmUrl = 'https://fcm.googleapis.com/fcm/send';
            $serviceAccountPath = FCPATH . 'app/Controllers/pvKey.json';
            $accessToken = $this->getFcmAccessToken($serviceAccountPath);

            if ($accessToken) {
                $headers = [
                    'Authorization: Bearer ' . $accessToken,
                    'Content-Type: application/json',
                ];

                $postData = [
                    'to' => $fcmToken,
                    'notification' => [
                        'title' => $title,
                        'body' => $body,
                        'sound' => 'default',
                    ],
                    'data' => $payload,
                ];

                $ch = curl_init();
                curl_setopt_array($ch, [
                    CURLOPT_URL => $fcmUrl,
                    CURLOPT_POST => true,
                    CURLOPT_HTTPHEADER => $headers,
                    CURLOPT_POSTFIELDS => json_encode($postData),
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT => 10,
                ]);
                curl_exec($ch);
                curl_close($ch);
            }
        } catch (\Throwable $e) {
            log_message('error', 'FCM notification failed: ' . $e->getMessage());
        }
    }

    private function getFcmAccessToken(string $serviceAccountPath): ?string
    {
        try {
            if (!file_exists($serviceAccountPath)) return null;
            $serviceAccount = json_decode(file_get_contents($serviceAccountPath), true);
            if (!$serviceAccount || !isset($serviceAccount['client_email'])) return null;

            $clientEmail = $serviceAccount['client_email'];
            $privateKey = $serviceAccount['private_key'];

            $now = time();
            $header = ['alg' => 'RS256', 'typ' => 'JWT'];
            $payload = [
                'iss' => $clientEmail,
                'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
                'aud' => 'https://oauth2.googleapis.com/token',
                'exp' => $now + 3600,
                'iat' => $now,
            ];

            $base64UrlHeader = rtrim(strtr(base64_encode(json_encode($header)), '+/', '-_'), '=');
            $base64UrlPayload = rtrim(strtr(base64_encode(json_encode($payload)), '+/', '-_'), '=');

            openssl_sign("{$base64UrlHeader}.{$base64UrlPayload}", $signature, $privateKey, 'sha256');
            $base64UrlSignature = rtrim(strtr(base64_encode($signature), '+/', '-_'), '=');
            $jwt = "{$base64UrlHeader}.{$base64UrlPayload}.{$base64UrlSignature}";

            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => 'https://oauth2.googleapis.com/token',
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => http_build_query([
                    'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                    'assertion' => $jwt,
                ]),
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 10,
            ]);
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpCode === 200) {
                $data = json_decode($response, true);
                return $data['access_token'] ?? null;
            }
            return null;
        } catch (\Throwable $e) {
            log_message('error', 'FCM token error: ' . $e->getMessage());
            return null;
        }
    }
}
