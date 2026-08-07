<?php
declare(strict_types=1);

namespace App\Controllers\Web;

use App\Controllers\Controller;
use App\Core\Auth;
use App\Core\Config;
use App\Core\Crypto;
use App\Core\Database;
use App\Core\Exceptions\ValidationException;
use App\Core\Request;
use App\Core\Response;
use App\Services\AuditService;
use App\Services\AuthService;
use App\Services\NotificationService;
use App\Services\SecurityLogService;
use App\Services\SessionService;

final class ProfileController extends Controller
{
    public function show(Request $request): Response
    {
        $userId = $this->requireUserId();

        return $this->view('shared.profile', [
            'pageTitle'    => 'My Profile',
            'profile'      => Auth::user(),
            'loginHistory' => AuthService::loginHistory($userId, 20),
            'sessions'     => Database::instance()->select(
                'SELECT session_id, ip_address, device_label, browser_label, created_at,
                        last_activity_at, idle_expires_at
                   FROM user_sessions
                  WHERE user_id = :id AND terminated_at IS NULL AND idle_expires_at > NOW()
                  ORDER BY last_activity_at DESC',
                ['id' => $userId]
            ),
            'currentSessionId' => Auth::sessionId(),
        ]);
    }

    public function update(Request $request): Response
    {
        $data = $this->validate($request, [
            'full_name' => 'required|string|max:150|alpha_space',
            'email'     => 'required|email',
        ]);

        $userId = $this->requireUserId();

        \App\Services\UserRegistrationService::updateUser($userId, [
            'full_name' => $data['full_name'],
            'email'     => $data['email'],
        ], $userId);

        if ($request->wantsJson()) {
            return $this->json([], 'Profile updated.');
        }

        return $this->redirect('/profile', 'Profile updated.');
    }

    /**
     * Profile photo upload.
     *
     * The file is re-encoded through GD rather than moved as-is: that discards
     * any EXIF payload or appended data and guarantees what lands on disk is a
     * real image, not a polyglot file with a script tail (Part 6).
     */
    public function uploadPhoto(Request $request): Response
    {
        $file = $request->file('photo');

        if ($file === null) {
            return $this->fail('FILE_REQUIRED', 'Choose an image to upload.', 422);
        }

        $path = $this->storeImage($file, 'teachers');

        Database::instance()->update(
            'users',
            ['photo_path' => $path, 'updated_at' => \App\Core\Clock::nowString()],
            ['user_id' => $this->requireUserId()]
        );

        $teacherId = Auth::teacherId();

        if ($teacherId !== null) {
            Database::instance()->update('teachers', ['photo_path' => $path], ['teacher_id' => $teacherId]);
        }

        AuditService::log(
            'PROFILE_PHOTO_UPDATED',
            'profile',
            'user',
            $this->requireUserId(),
            null,
            ['photo_path' => $path],
            'Profile photo updated.'
        );

        return $this->json(['photo_url' => '/uploads/teachers/' . basename($path)], 'Photo updated.');
    }

    /**
     * @param  array<string,mixed> $file
     * @return string stored relative path
     */
    private function storeImage(array $file, string $folder): string
    {
        $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);

        if ($error !== UPLOAD_ERR_OK) {
            throw new ValidationException(['photo' => ['The image could not be uploaded.']]);
        }

        $tmpName = (string) $file['tmp_name'];

        if (!is_uploaded_file($tmpName) && !Config::get('app.debug', false)) {
            throw new ValidationException(['photo' => ['The upload could not be verified.']]);
        }

        $maxBytes = (int) Config::get('app.uploads.max_bytes', 4194304);

        if ((int) ($file['size'] ?? 0) > $maxBytes) {
            throw new ValidationException(['photo' => [sprintf('Images must be under %d MB.', intdiv($maxBytes, 1048576))]]);
        }

        $info = @getimagesize($tmpName);

        if ($info === false || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG], true)) {
            SecurityLogService::log(
                SecurityLogService::MALICIOUS_UPLOAD,
                'high',
                sprintf('Rejected profile image upload "%s": not a valid JPEG or PNG.', $file['name'] ?? '?')
            );

            throw new ValidationException(['photo' => ['Upload a JPEG or PNG image.']]);
        }

        if ($info[0] > 6000 || $info[1] > 6000) {
            throw new ValidationException(['photo' => ['Image dimensions may not exceed 6000×6000.']]);
        }

        // Uploads are re-encoded through GD rather than stored as received —
        // that is what stops a file which merely looks like an image from
        // being anything else. Without the extension there is no safe way to
        // accept the upload, so it is refused rather than trusted.
        if (!extension_loaded('gd')) {
            throw new ValidationException(['photo' => [
                'Photo upload needs the PHP "gd" extension, which is not enabled. '
                . 'Enable extension=gd in php.ini and restart the web server.',
            ]]);
        }

        $source = $info[2] === IMAGETYPE_JPEG
            ? @imagecreatefromjpeg($tmpName)
            : @imagecreatefrompng($tmpName);

        if ($source === false) {
            throw new ValidationException(['photo' => ['The image could not be processed.']]);
        }

        // Downscale to a sane avatar size; this also normalises the output.
        $maxDimension = 512;
        $width        = imagesx($source);
        $height       = imagesy($source);
        $scale        = min(1.0, $maxDimension / max($width, $height));

        $targetWidth  = max(1, (int) round($width * $scale));
        $targetHeight = max(1, (int) round($height * $scale));

        $canvas = imagecreatetruecolor($targetWidth, $targetHeight);
        imagefill($canvas, 0, 0, imagecolorallocate($canvas, 255, 255, 255));
        imagecopyresampled($canvas, $source, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height);

        // Randomised filename: the original name never touches the filesystem.
        $filename  = Crypto::randomHex(16) . '.jpg';
        $directory = (string) Config::get('app.paths.uploads') . '/' . $folder;

        if (!is_dir($directory)) {
            @mkdir($directory, 0750, true);
        }

        imagejpeg($canvas, $directory . '/' . $filename, 85);
        imagedestroy($canvas);
        imagedestroy($source);

        @chmod($directory . '/' . $filename, 0640);

        return $folder . '/' . $filename;
    }

    public function terminateSession(Request $request): Response
    {
        $sessionId = $request->routeInt('id');

        $owned = Database::instance()->scalar(
            'SELECT 1 FROM user_sessions WHERE session_id = :id AND user_id = :user',
            ['id' => $sessionId, 'user' => $this->requireUserId()]
        );

        if ($owned === null) {
            return $this->fail('NOT_FOUND', 'Session not found.', 404);
        }

        if ($sessionId === Auth::sessionId()) {
            return $this->fail('CANNOT_TERMINATE_OWN', 'Use Sign out to end your current session.', 422);
        }

        SessionService::terminate($sessionId, SessionService::REASON_ADMIN);

        return $this->json([], 'Session signed out.');
    }

    // ----------------------------------------------------- notifications --

    public function notifications(Request $request): Response
    {
        $user = Auth::user();

        $notifications = NotificationService::forUser(
            (int) $user['user_id'],
            (string) $user['role_slug'],
            50,
            $request->bool('unread_only', false)
        );

        if ($request->wantsJson()) {
            return $this->json([
                'grouped'      => NotificationService::group($notifications),
                'unread_count' => NotificationService::unreadCount((int) $user['user_id'], (string) $user['role_slug']),
            ]);
        }

        return $this->view('shared.notifications', [
            'pageTitle' => 'Notifications',
            'grouped'   => NotificationService::group($notifications),
        ]);
    }

    /** Passive badge poll — never extends the session (Part 18.4). */
    public function unreadCount(Request $request): Response
    {
        $user = Auth::user();

        return $this->json([
            'count' => NotificationService::unreadCount((int) $user['user_id'], (string) $user['role_slug']),
        ]);
    }

    public function markRead(Request $request): Response
    {
        $user = Auth::user();

        NotificationService::markRead(
            $request->routeInt('id'),
            (int) $user['user_id'],
            (string) $user['role_slug']
        );

        return $this->json([], 'Notification marked as read.');
    }

    public function markAllRead(Request $request): Response
    {
        $user  = Auth::user();
        $count = NotificationService::markAllRead((int) $user['user_id'], (string) $user['role_slug']);

        return $this->json(['marked' => $count], sprintf('%d notification(s) marked as read.', $count));
    }
}
