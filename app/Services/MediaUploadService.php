<?php

namespace App\Services;

use CodeIgniter\HTTP\Files\UploadedFile;
use RuntimeException;

/**
 * MediaUploadService — single storage strategy for ALL media uploads
 * (voice/audio, image, video, document, status, moments, profile, group avatar, emoji).
 *
 * Behavior:
 *  1. Builds a normalized absolute path (platform-correct separators).
 *  2. Automatically creates the target directory (recursively) when missing.
 *  3. Verifies the directory is writable; attempts a chmod repair when not.
 *  4. Performs an actual probe-write test (is_writable() can be unreliable with ACLs).
 *  5. Falls back to CI4's writable/ directory (guaranteed writable) when the
 *     web-accessible directory cannot be made writable — files stay accessible
 *     via the GET /uploads/... serve route.
 *  6. Logs detailed server-side diagnostics while returning clean client errors.
 *
 * Storage layout:
 *  Primary  : public/assets/<subdir>/<random-name>           → URL /assets/<subdir>/<name>
 *  Fallback : writable/uploads/<subdir>/<random-name>        → URL /uploads/<subdir>/<name>
 *
 * One-time server permission setup (root, Linux production):
 *   chown -R www-data:www-data public/assets
 *   chmod -R u+rwX,go+rX public/assets
 *   (Windows/XAMPP: grant the PHP/Apache user Modify rights on public\assets)
 */
class MediaUploadService
{
    /** @var string|null Last web path stored, used by resolvePath() */
    private ?string $lastWebPath = null;

    /** @var bool Whether the last store() used the writable/ fallback */
    private bool $usedFallback = false;

    /**
     * Store an uploaded file and return the public web path.
     *
     * @param UploadedFile $file   Valid CI4 uploaded file.
     * @param string       $subdir Relative web subdir, e.g. 'files/uploads', 'files/status', 'img/uploads/profile'.
     * @param string|null  $name   Optional custom file name (default: random name).
     *
     * @return array{web_path:string, absolute_path:string, name:string}
     *
     * @throws RuntimeException When the file cannot be stored anywhere.
     */
    public function store(UploadedFile $file, string $subdir, ?string $name = null): array
    {
        $this->lastWebPath = null;
        $this->usedFallback = false;

        if (!$file->isValid()) {
            throw new RuntimeException('The uploaded file is invalid.');
        }

        $safeSubdir = $this->sanitizeSubdir($subdir);
        $fileName = $name !== null && $name !== '' ? basename($name) : $file->getRandomName();
        $fileName = $this->sanitizeFileName($fileName);

        // 1) Preferred location: web-accessible docroot
        $webDir = $this->webDir($safeSubdir);
        try {
            $this->ensureDirectory($webDir);
            $absolute = $this->moveFile($file, $webDir, $fileName);
            $this->lastWebPath = '/assets/' . $safeSubdir . '/' . $fileName;
            return $this->result($absolute, $this->lastWebPath, $fileName);
        } catch (RuntimeException $e) {
            log_message('critical', sprintf(
                '[MediaUploadService] Primary upload dir %s unusable: %s',
                $webDir, $e->getMessage()
            ));
        }

        // 2) Fallback: CI4 writable/ directory, served via GET /uploads/...
        $writableDir = $this->writableDir($safeSubdir);
        try {
            $this->ensureDirectory($writableDir);
            $absolute = $this->moveFile($file, $writableDir, $fileName);
            $this->lastWebPath = '/uploads/' . $safeSubdir . '/' . $fileName;
            $this->usedFallback = true;
            log_message('alert', sprintf(
                '[MediaUploadService] Upload stored in fallback writable/ dir: %s (web path %s). Check permissions of %s',
                $absolute, $this->lastWebPath, $this->webDir($safeSubdir)
            ));
            return $this->result($absolute, $this->lastWebPath, $fileName);
        } catch (RuntimeException $e) {
            log_message('critical', sprintf(
                '[MediaUploadService] Fallback upload dir %s unusable too: %s',
                $writableDir, $e->getMessage()
            ));
        }

        $this->logDiagnostics($this->webDir($safeSubdir), 'both upload locations unusable');
        throw new RuntimeException('Failed to save media. Please try again.');
    }

    /**
     * Store a file directly into writable/uploads/<subdir> (non-web storage,
     * served via GET /uploads/...). Used by call recordings.
     *
     * @return array{web_path:string, absolute_path:string, name:string}
     * @throws RuntimeException
     */
    public function storeToWritable(UploadedFile $file, string $subdir, ?string $name = null): array
    {
        if (!$file->isValid()) {
            throw new RuntimeException('The uploaded file is invalid.');
        }
        $safeSubdir = $this->sanitizeSubdir($subdir);
        $fileName = $name !== null && $name !== '' ? basename($name) : $file->getRandomName();
        $fileName = $this->sanitizeFileName($fileName);

        $dir = $this->writableDir($safeSubdir);
        try {
            $this->ensureDirectory($dir);
            $absolute = $this->moveFile($file, $dir, $fileName);
            $webPath = '/uploads/' . $safeSubdir . '/' . $fileName;
            return $this->result($absolute, $webPath, $fileName);
        } catch (RuntimeException $e) {
            $this->logDiagnostics($dir, 'writable store failed');
            log_message('critical', '[MediaUploadService] storeToWritable failed: ' . $e->getMessage());
            throw new RuntimeException('Failed to save media. Please try again.');
        }
    }

    /**
     * Move an uploaded file into $dir using move_uploaded_file semantics.
     * Verifies the file actually landed on disk. Details are logged server-side;
     * the caller always receives a clean message.
     */
    private function moveFile(UploadedFile $file, string $dir, string $fileName): string
    {
        $destination = rtrim($dir, '/\\') . DIRECTORY_SEPARATOR . $fileName;

        try {
            $file->move($dir, $fileName);
        } catch (\Throwable $e) {
            log_message('error', sprintf(
                '[MediaUploadService] move failed: %s (dest %s)',
                $e->getMessage(), $destination
            ));
            throw new RuntimeException('Failed to save media. Please try again.');
        }

        if (!$file->hasMoved() || !is_file($destination)) {
            log_message('error', sprintf(
                '[MediaUploadService] move_uploaded_file returned false for %s', $destination
            ));
            throw new RuntimeException('Failed to save media. Please try again.');
        }

        @chmod($destination, 0664);
        return $destination;
    }

    /**
     * Ensure a directory exists (recursively) and is writable.
     * Repairs permissions when possible; performs a real probe-write test.
     *
     * @throws RuntimeException when the directory cannot be made writable.
     */
    private function ensureDirectory(string $dir): void
    {
        $dir = rtrim($dir, '/\\');

        if (!is_dir($dir)) {
            if (!@mkdir($dir, 0775, true) && !is_dir($dir)) {
                throw new RuntimeException('Failed to create directory: ' . $dir . ' (' . $this->lastPhpError() . ')');
            }
            log_message('info', '[MediaUploadService] Created directory ' . $dir);
        }

        if (!$this->isDirWritable($dir)) {
            @chmod($dir, 0777);
            if (!$this->isDirWritable($dir)) {
                // Walk up and repair parent chain too (common root-owned deploy issue)
                $parent = dirname($dir);
                while ($parent && $parent !== dirname($parent) && !$this->isDirWritable($parent)) {
                    @chmod($parent, 0755);
                    $parent = dirname($parent);
                }
                if (!$this->isDirWritable($dir)) {
                    $this->logDiagnostics($dir, 'directory not writable after chmod repair');
                    throw new RuntimeException('Directory not writable: ' . $dir);
                }
            }
            log_message('warning', '[MediaUploadService] Repaired permissions on ' . $dir);
        }
    }

    /**
     * is_writable() + real probe-write test (catches ACL issues is_writable misses).
     */
    private function isDirWritable(string $dir): bool
    {
        if (!is_dir($dir) || !is_writable($dir)) {
            return false;
        }
        $probe = $dir . DIRECTORY_SEPARATOR . '.hb_write_probe_' . uniqid('', true);
        $h = @fopen($probe, 'x');
        if ($h === false) {
            return false;
        }
        fclose($h);
        @unlink($probe);
        return true;
    }

    /**
     * Resolve a stored web path to an absolute filesystem path.
     * Handles both /assets/... (docroot) and /uploads/... (writable fallback) paths.
     */
    public function resolvePath(string $webPath): ?string
    {
        $webPath = ltrim(trim($webPath), '/\\');
        if ($webPath === '') {
            return null;
        }
        $webPath = str_replace('/', DIRECTORY_SEPARATOR, $webPath);

        if (strpos($webPath, 'uploads' . DIRECTORY_SEPARATOR) === 0) {
            $absolute = WRITEPATH . $webPath;
        } else {
            $absolute = FCPATH . $webPath;
        }
        return is_file($absolute) ? $absolute : null;
    }

    /** Normalized web-accessible directory under the public docroot. */
    public function webDir(string $subdir): string
    {
        return rtrim(FCPATH, '/\\') . DIRECTORY_SEPARATOR . 'assets'
            . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $this->sanitizeSubdir($subdir));
    }

    /** Normalized directory under writable/ for the fallback storage. */
    public function writableDir(string $subdir): string
    {
        return rtrim(WRITEPATH, '/\\') . DIRECTORY_SEPARATOR . 'uploads'
            . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $this->sanitizeSubdir($subdir));
    }

    /**
     * Ensure writable/uploads/<subdir> exists and is writable; returns the path.
     * @throws RuntimeException
     */
    public function ensureWritableSubdir(string $subdir): string
    {
        $dir = $this->writableDir($subdir);
        $this->ensureDirectory($dir);
        return $dir;
    }

    public function usedFallback(): bool
    {
        return $this->usedFallback;
    }

    public function lastWebPath(): ?string
    {
        return $this->lastWebPath;
    }

    private function result(string $absolute, string $webPath, string $name): array
    {
        return [
            'absolute_path' => $absolute,
            'web_path'      => $webPath,
            'name'          => $name,
        ];
    }

    private function sanitizeSubdir(string $subdir): string
    {
        $subdir = str_replace(['\\', '..'], ['/', ''], trim($subdir, '/\\ '));
        return trim($subdir, '/');
    }

    private function sanitizeFileName(string $name): string
    {
        $name = preg_replace('/[^A-Za-z0-9._-]/', '_', basename($name)) ?? basename($name);
        return $name === '' ? 'file_' . bin2hex(random_bytes(4)) : $name;
    }

    private function lastPhpError(): string
    {
        $err = error_get_last();
        return $err['message'] ?? 'unknown error';
    }

    /**
     * Detailed server-side diagnostics. NEVER echoed to the client.
     */
    private function logDiagnostics(string $dir, string $context): void
    {
        $details = [
            'context'       => $context,
            'dir'           => $dir,
            'realpath'      => realpath($dir) ?: '(unresolvable)',
            'is_dir'        => is_dir($dir) ? 'yes' : 'no',
            'is_writable'   => is_writable($dir) ? 'yes' : 'no',
            'perms'         => is_dir($dir) ? sprintf('%o', fileperms($dir) & 0777) : 'n/a',
            'php_user'      => function_exists('posix_getpwuid') && function_exists('posix_geteuid')
                ? (posix_getpwuid(posix_geteuid())['name'] ?? 'unknown')
                : (getenv('USERNAME') ?: get_current_user()),
            'open_basedir'  => ini_get('open_basedir') ?: '(off)',
            'upload_tmp'    => ini_get('upload_tmp_dir') ?: sys_get_temp_dir(),
            'disk_free'     => @disk_free_space(dirname($dir) ?: $dir) !== false
                ? round(@disk_free_space(dirname($dir) ?: $dir) / 1048576, 1) . ' MB' : 'unknown',
            'os'            => php_uname(),
            'fcpath'        => defined('FCPATH') ? FCPATH : 'undefined',
            'writepath'     => defined('WRITEPATH') ? WRITEPATH : 'undefined',
        ];
        log_message('critical', '[MediaUploadService] upload diagnostics: ' . json_encode($details));
    }
}
