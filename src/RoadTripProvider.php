<?php

namespace Vinceneil666\LibrenmsRoadtrip;

use Illuminate\Support\ServiceProvider;
use LibreNMS\Interfaces\Plugins\Hooks\DeviceOverviewHook;
use LibreNMS\Interfaces\Plugins\Hooks\MenuEntryHook;
use LibreNMS\Interfaces\Plugins\Hooks\SettingsHook;
use LibreNMS\Interfaces\Plugins\PluginManagerInterface;
use Vinceneil666\LibrenmsRoadtrip\Hooks\DeviceOverview;
use Vinceneil666\LibrenmsRoadtrip\Hooks\MenuEntry;
use Vinceneil666\LibrenmsRoadtrip\Hooks\Settings;

class RoadTripProvider extends ServiceProvider
{
    public const NAME = 'roadtrip';

    public const VERSION = '0.1.0';

    public function boot(PluginManagerInterface $pluginManager): void
    {
        $pluginManager->publishHook(self::NAME, MenuEntryHook::class, MenuEntry::class);
        $pluginManager->publishHook(self::NAME, DeviceOverviewHook::class, DeviceOverview::class);
        $pluginManager->publishHook(self::NAME, SettingsHook::class, Settings::class);

        if (! $pluginManager->pluginEnabled(self::NAME)) {
            return;
        }

        $this->loadRoutesFrom(__DIR__ . '/../routes/web.php');
        $this->loadViewsFrom(__DIR__ . '/../resources/views', self::NAME);
    }
}
