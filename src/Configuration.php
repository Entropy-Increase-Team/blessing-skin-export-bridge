<?php

namespace SkinLoomBridge;

use App\Models\User;
use App\Services\OptionForm;
use Illuminate\Http\Request;
use Option;
use SkinLoomBridge\Support\IpMatcher;

class Configuration
{
    public function render(Request $request)
    {
        $user = $request->user();
        abort_if(!$user || (int) $user->permission < User::SUPER_ADMIN, 403);

        $freshToken = null;
        $actionMessage = null;
        $actionMessageType = 'info';

        if ($request->isMethod('post') && $request->has('bridge_action')) {
            $action = (string) $request->input('bridge_action');
            if ($action === 'generate') {
                $ttlHours = max(1, min(168, (int) option('skinloom_bridge_ttl_hours', 24)));
                $freshToken = bin2hex(random_bytes(32));
                option([
                    'skinloom_bridge_token_hash' => hash('sha256', $freshToken),
                    'skinloom_bridge_expires_at' => (string) (time() + $ttlHours * 3600),
                ]);
                $actionMessage = trans('SkinLoomBridge::config.messages.token_generated', ['hours' => $ttlHours]);
                $actionMessageType = 'success';
            } elseif ($action === 'revoke') {
                option([
                    'skinloom_bridge_token_hash' => '',
                    'skinloom_bridge_expires_at' => '0',
                    'skinloom_bridge_freeze_writes' => '0',
                ]);
                $actionMessage = trans('SkinLoomBridge::config.messages.token_revoked');
                $actionMessageType = 'warning';
            }
        }

        $form = Option::form(
            'skinloom_bridge',
            trans('SkinLoomBridge::config.settings.title'),
            function (OptionForm $form) {
                $form->select('skinloom_bridge_ttl_hours', trans('SkinLoomBridge::config.settings.ttl.title'))
                    ->option('1', trans('SkinLoomBridge::config.settings.ttl.h1'))
                    ->option('6', trans('SkinLoomBridge::config.settings.ttl.h6'))
                    ->option('24', trans('SkinLoomBridge::config.settings.ttl.h24'))
                    ->option('72', trans('SkinLoomBridge::config.settings.ttl.h72'))
                    ->option('168', trans('SkinLoomBridge::config.settings.ttl.h168'))
                    ->hint(trans('SkinLoomBridge::config.settings.ttl.hint'));

                $form->textarea('skinloom_bridge_ip_allowlist', trans('SkinLoomBridge::config.settings.ip_allowlist.title'))
                    ->rows(3)
                    ->description(trans('SkinLoomBridge::config.settings.ip_allowlist.description'));

                $form->checkbox('skinloom_bridge_freeze_writes', trans('SkinLoomBridge::config.settings.freeze_writes.title'))
                    ->label(trans('SkinLoomBridge::config.settings.freeze_writes.label'))
                    ->description(trans('SkinLoomBridge::config.settings.freeze_writes.description'));

                $form->checkbox('skinloom_bridge_export_sensitive', trans('SkinLoomBridge::config.settings.export_sensitive.title'))
                    ->label(trans('SkinLoomBridge::config.settings.export_sensitive.label'))
                    ->description(trans('SkinLoomBridge::config.settings.export_sensitive.description'));
            }
        );

        $invalidCidr = null;
        if ($request->isMethod('post') && !$request->has('bridge_action')) {
            $invalidCidr = IpMatcher::validateList((string) $request->input('skinloom_bridge_ip_allowlist', ''));
        }

        // Skip OptionForm::handle() when token buttons were submitted or CIDR validation failed.
        if ($invalidCidr !== null) {
            $form->addMessage(trans('SkinLoomBridge::config.messages.invalid_cidr', ['entry' => $invalidCidr]), 'danger');
        } elseif (!$request->isMethod('post') || !$request->has('bridge_action')) {
            $form->handle();
        }

        $tokenHash = trim((string) option('skinloom_bridge_token_hash', ''));
        $expiresAt = (int) option('skinloom_bridge_expires_at', 0);
        $now = time();
        $isActive = $tokenHash !== '' && ($expiresAt === 0 || $expiresAt >= $now);
        $siteUrl = rtrim(url('/'), '/');

        $bundleJson = $freshToken !== null
            ? json_encode(['url' => $siteUrl, 'token' => $freshToken], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            : null;

        return view('SkinLoomBridge::config', [
            'form' => $form,
            'siteUrl' => $siteUrl,
            'freshToken' => $freshToken,
            'bundleJson' => $bundleJson,
            'tokenActive' => $isActive,
            'tokenExpired' => $tokenHash !== '' && $expiresAt > 0 && $expiresAt < $now,
            'expiresAtFormatted' => $expiresAt > 0 ? date('Y-m-d H:i:s T', $expiresAt) : '',
            'isHttps' => $request->isSecure() || str_starts_with(strtolower($siteUrl), 'https://'),
            'fsDriver' => (string) config('filesystems.disks.textures.driver', 'local'),
            'actionMessage' => $actionMessage,
            'actionMessageType' => $actionMessageType,
        ]);
    }
}
