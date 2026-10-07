<?php

namespace Vinceneil666\LibrenmsRoadtrip\Hooks;

use App\Models\Device;
use Illuminate\Contracts\View\View;
use LibreNMS\Interfaces\Plugins\Hooks\DeviceOverviewHook;

/** A small "Drive here" box on every device's overview page. */
class DeviceOverview implements DeviceOverviewHook
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    public function handle(string $pluginName, array $settings, Device $device): View
    {
        return view("$pluginName::device-overview", ['device' => $device, 'settings' => $settings]);
    }
}
