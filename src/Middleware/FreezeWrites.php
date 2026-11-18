<?php

namespace SkinLoomBridge\Middleware;

use Closure;
use Illuminate\Http\Request;

class FreezeWrites
{
    public function handle(Request $request, Closure $next)
    {
        if ($request->isMethodSafe() || $request->isMethod('OPTIONS')) {
            return $next($request);
        }

        // Keep admin operations (including revoking/unfreezing), authentication, and bridge routes active.
        if (
            $request->is('admin')
            || $request->is('admin/*')
            || $request->is('auth/login')
            || $request->is('auth/logout')
            || $request->is('auth/captcha')
            || $request->is('plugin/skinloom-bridge/*')
        ) {
            return $next($request);
        }

        // Double check token liveness so an expired token cannot leave writes permanently locked.
        $expectedHash = trim((string) option('skinloom_bridge_token_hash', ''));
        $expiresAt = (int) option('skinloom_bridge_expires_at', 0);
        if ($expectedHash === '' || ($expiresAt > 0 && time() > $expiresAt)) {
            return $next($request);
        }

        $msg = trans('SkinLoomBridge::general.freeze_notice');
        if ($request->expectsJson() || $request->is('api/*')) {
            return response()->json(['code' => 503, 'message' => $msg], 503, [], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        }

        return abort(503, $msg);
    }
}
