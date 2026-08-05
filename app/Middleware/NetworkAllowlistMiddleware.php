<?php
declare(strict_types=1);

namespace App\Middleware;

use App\Core\Config;
use App\Core\Exceptions\HttpException;
use App\Core\Middleware;
use App\Core\Request;
use App\Core\Response;
use App\Services\NetworkService;
use App\Services\SecurityLogService;

/**
 * LAN-only enforcement (Part 6, "Only internal IP addresses may access the
 * server").
 *
 * Parameter selects which allowlist applies: `network-allowlist:device` for the
 * IoT API, default for the web UI. A blocked IP is refused before any
 * application code runs, and every refusal is logged with the endpoint that was
 * targeted.
 */
final class NetworkAllowlistMiddleware extends Middleware
{
    public function handle(Request $request, callable $next): Response
    {
        $ip    = $request->ip();
        $scope = $this->parameters[0] ?? 'web';

        if (NetworkService::isBlocked($ip)) {
            SecurityLogService::log(
                SecurityLogService::BLOCKED_IP_ATTEMPT,
                'high',
                sprintf('Blocked IP %s attempted %s %s.', $ip, $request->method(), $request->path())
            );

            throw new HttpException(403, 'IP_BLOCKED', 'Your network address is blocked.');
        }

        /** @var list<string> $allowlist */
        $allowlist = $scope === 'device'
            ? Config::get('security.network.trusted_device_cidrs', [])
            : Config::get('security.network.trusted_web_cidrs', []);

        // An empty allowlist means "not configured", not "deny everything" —
        // locking an administrator out of their own server on a mis-set
        // environment variable would be worse than the risk it prevents.
        if ($allowlist === []) {
            return $next($request);
        }

        if (NetworkService::ipInAnyCidr($ip, $allowlist)) {
            return $next($request);
        }

        SecurityLogService::log(
            SecurityLogService::IP_NOT_ALLOWLISTED,
            'high',
            sprintf('Request from %s is outside the %s allowlist (%s %s).', $ip, $scope, $request->method(), $request->path()),
            ['allowlist' => $allowlist, 'scope' => $scope]
        );

        throw new HttpException(
            403,
            'IP_NOT_ALLOWLISTED',
            'This system is only reachable from the school network.'
        );
    }
}
