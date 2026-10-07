<?php

namespace Vinceneil666\LibrenmsRoadtrip\Hooks;

use LibreNMS\Interfaces\Plugins\Hooks\SettingsHook;

/** Plugin settings page (Overview -> Plugins -> Plugin Admin -> Road Trip). */
class Settings implements SettingsHook
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>
     */
    public function handle(string $pluginName, array $settings): array
    {
        return [
            'content_view' => "$pluginName::settings",
            'settings' => array_merge(\Vinceneil666\LibrenmsRoadtrip\World::DEFAULTS, $settings),
        ];
    }
}
