<?php

use App\Services\Hook;
use Illuminate\Support\Facades\Route;
use SkinLoomBridge\Middleware\AuthenticateBridge;
use SkinLoomBridge\Middleware\FreezeWrites;

return function () {
    $tokenHash = trim((string) option('skinloom_bridge_token_hash', ''));
    $expiresAt = (int) option('skinloom_bridge_expires_at', 0);
    $tokenActive = $tokenHash !== '' && ($expiresAt === 0 || time() <= $expiresAt);

    if ($tokenActive && (bool) option('skinloom_bridge_freeze_writes', false)) {
        Hook::pushMiddleware(FreezeWrites::class);
    }

    Hook::addRoute(function () {
        Route::prefix('plugin/skinloom-bridge')
            ->namespace('SkinLoomBridge\Controllers')
            ->middleware([AuthenticateBridge::class])
            ->group(__DIR__.'/routes.php');
    });
};
