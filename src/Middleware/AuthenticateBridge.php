<?php

namespace SkinLoomBridge\Middleware;

use Closure;
use Illuminate\Http\Request;
use SkinLoomBridge\Support\IpMatcher;
use Symfony\Component\HttpFoundation\Response;

use function is_string;

class AuthenticateBridge
{
    public function handle(Request $request, Closure $next): Response
    {
        $expectedHash = trim((string) option('skinloom_bridge_token_hash', ''));
        $expiresAt = (int) option('skinloom_bridge_expires_at', 0);

        if ($expectedHash === '') {
            return $this->jsonError('bridge_not_configured', 'SkinLoom export bridge token has not been generated.', 503);
        }
        if ($expiresAt > 0 && time() > $expiresAt) {
            return $this->jsonError('bridge_token_expired', 'SkinLoom export bridge token has expired; generate a new token in Blessing Skin admin.', 401);
        }

        $allowlist = (string) option('skinloom_bridge_ip_allowlist', '');
        if (!IpMatcher::allowed($request->ip(), $allowlist)) {
            return $this->jsonError('ip_forbidden', 'Client IP is not permitted by bridge allowlist.', 403);
        }

        $provided = $this->extractBearer($request);
        if ($provided === '' || !hash_equals($expectedHash, hash('sha256', $provided))) {
            return $this->jsonError('unauthorized', 'Missing or invalid Bearer token.', 401);
        }

        /** @var Response $response */
        $response = $next($request);
        $this->applySecurityHeaders($response);

        return $response;
    }

    private function extractBearer(Request $request): string
    {
        $token = $request->bearerToken();
        if (is_string($token) && $token !== '') {
            return trim($token);
        }

        $raw = $request->server('HTTP_AUTHORIZATION')
            ?: $request->server('REDIRECT_HTTP_AUTHORIZATION')
            ?: '';
        if (is_string($raw) && preg_match('/^\s*Bearer\s+(.+)$/i', $raw, $matches)) {
            return trim($matches[1]);
        }

        return '';
    }

    private function jsonError(string $error, string $message, int $status): Response
    {
        $response = response()->json([
            'error' => $error,
            'message' => $message,
        ], $status, [], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        $this->applySecurityHeaders($response);

        return $response;
    }

    private function applySecurityHeaders(Response $response): void
    {
        $response->headers->set('Cache-Control', 'private, no-store, max-age=0');
        $response->headers->set('Pragma', 'no-cache');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-SkinLoom-Bridge-Version', '1');
    }
}
