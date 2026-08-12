<?php
declare(strict_types=1);

namespace App\Controllers\Web;

use App\Controllers\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Services\AcademicStructureService;
use App\Services\AuthService;
use App\Services\UserRegistrationService;

final class UserController extends Controller
{
    public function index(Request $request): Response
    {
        $pagination = $this->pagination($request);

        $filters = [
            'search'           => $request->string('search', ''),
            'role'             => $request->string('role', ''),
            'status'           => $request->string('status', ''),
            'include_archived' => $request->bool('include_archived', false),
        ];

        $result = UserRegistrationService::paginate($filters, $pagination['page'], $pagination['per_page']);
        $meta   = $this->paginationMeta($result['total'], $pagination['page'], $pagination['per_page']);

        if ($request->wantsJson()) {
            return $this->json(['rows' => $result['rows'], 'pagination' => $meta]);
        }

        return $this->view('admin.users.index', [
            'pageTitle'   => 'User Accounts',
            'users'       => $result['rows'],
            'pagination'  => $meta,
            'filters'     => $filters,
            'departments' => AcademicStructureService::departments(true),
            'gradeLevels' => AcademicStructureService::gradeLevels(),
        ]);
    }

    /**
     * Register an administrator account. Teacher accounts are created through
     * the Teachers page, which collects the academic assignments too.
     */
    public function store(Request $request): Response
    {
        $data = $this->validate($request, [
            'username'   => 'nullable|string|min:4|max:32|slug',
            'email'      => 'required|email',
            'first_name' => 'required|string|max:60|alpha_space',
            'last_name'  => 'required|string|max:60|alpha_space',
            'password'   => 'nullable|string|max:200',
            'force_password_change' => 'nullable|bool',
        ]);

        $username = (string) ($data['username'] ?? '');

        if ($username === '') {
            $username = UserRegistrationService::suggestUsername(
                (string) $data['first_name'],
                (string) $data['last_name']
            );
        }

        $result = UserRegistrationService::register([
            'role'       => 'administrator',
            'username'   => $username,
            'email'      => $data['email'],
            'first_name' => $data['first_name'],
            'last_name'  => $data['last_name'],
            'password'   => $data['password'] ?? null,
            'password_confirmation' => $request->string('password_confirmation', (string) ($data['password'] ?? '')),
            'force_password_change' => $data['force_password_change'] ?? true,
        ], $this->requireUserId());

        return $this->json([
            'user_id'         => $result['user_id'],
            'username'        => $result['username'],
            'password'        => $result['password'],
            'generated'       => $result['generated'],
            'credential_slip' => UserRegistrationService::credentialSlip(
                $result['username'],
                $result['password'],
                'administrator',
                trim(sprintf('%s %s', $data['first_name'], $data['last_name']))
            ),
        ], 'Administrator account created. Copy the credentials now — they cannot be shown again.', 201);
    }

    public function update(Request $request): Response
    {
        $data = $this->validate($request, [
            'full_name' => 'nullable|string|max:150|alpha_space',
            'email'     => 'nullable|email',
            'status'    => 'nullable|in:active,inactive,locked,archived',
        ]);

        $userId = $request->routeInt('id');

        UserRegistrationService::updateUser($userId, $data, $this->requireUserId());

        return $this->json(['user_id' => $userId], 'Account updated.');
    }

    public function resetPassword(Request $request): Response
    {
        $this->requirePasswordConfirmation($request);

        $userId   = $request->routeInt('id');
        $password = AuthService::adminResetPassword($userId);

        $user = \App\Core\Database::instance()->selectOne(
            'SELECT username, full_name FROM users WHERE user_id = :id',
            ['id' => $userId]
        );

        return $this->json([
            'password'        => $password,
            'credential_slip' => UserRegistrationService::credentialSlip(
                (string) ($user['username'] ?? ''),
                $password,
                'user',
                (string) ($user['full_name'] ?? '')
            ),
        ], 'Password reset and all their sessions signed out. Copy it now — it cannot be shown again.');
    }

    public function archive(Request $request): Response
    {
        $this->requirePasswordConfirmation($request);

        UserRegistrationService::archive($request->routeInt('id'), $this->requireUserId());

        return $this->json([], 'Account archived and all its sessions terminated.');
    }

    public function restore(Request $request): Response
    {
        $this->requirePasswordConfirmation($request);

        UserRegistrationService::restore($request->routeInt('id'), $this->requireUserId());

        return $this->json([], 'Account restored. It is inactive until you set it active.');
    }

    /** Live username availability for the registration wizard. */
    public function checkUsername(Request $request): Response
    {
        $username = $request->string('username', '');

        return $this->json([
            'username'  => $username,
            'available' => UserRegistrationService::isUsernameAvailable($username),
        ]);
    }

    public function suggestUsername(Request $request): Response
    {
        return $this->json([
            'username' => UserRegistrationService::suggestUsername(
                $request->string('first_name', ''),
                $request->string('last_name', '')
            ),
        ]);
    }

    /** Strength meter feedback; advisory only, the policy is enforced server-side. */
    public function checkPassword(Request $request): Response
    {
        $password = $request->string('password', '');

        return $this->json([
            'score'  => \App\Services\PasswordPolicyService::score($password),
            'issues' => \App\Services\PasswordPolicyService::check($password, [
                $request->string('username', ''),
                $request->string('email', ''),
                $request->string('first_name', ''),
                $request->string('last_name', ''),
            ]),
        ]);
    }

    public function generatePassword(Request $request): Response
    {
        return $this->json(['password' => \App\Services\PasswordPolicyService::generate()]);
    }
}
