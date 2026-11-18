<?php

use App\Events\PluginWasDeleted;
use App\Events\PluginWasDisabled;
use App\Events\PluginWasEnabled;

return [
    PluginWasEnabled::class => function () {
        $defaults = [
            'skinloom_bridge_token_hash' => '',
            'skinloom_bridge_expires_at' => '0',
            'skinloom_bridge_ttl_hours' => '24',
            'skinloom_bridge_ip_allowlist' => '',
            'skinloom_bridge_freeze_writes' => '0',
            'skinloom_bridge_export_sensitive' => '1',
        ];

        foreach ($defaults as $key => $value) {
            if (Option::get($key) === null) {
                Option::set($key, $value);
            }
        }
    },

    PluginWasDisabled::class => function () {
        option([
            'skinloom_bridge_token_hash' => '',
            'skinloom_bridge_expires_at' => '0',
            'skinloom_bridge_freeze_writes' => '0',
        ]);
    },

    PluginWasDeleted::class => function () {
        option([
            'skinloom_bridge_token_hash' => '',
            'skinloom_bridge_expires_at' => '0',
            'skinloom_bridge_freeze_writes' => '0',
        ]);
    },
];
