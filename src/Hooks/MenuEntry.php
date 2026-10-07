<?php

namespace Vinceneil666\LibrenmsRoadtrip\Hooks;

use LibreNMS\Interfaces\Plugins\Hooks\MenuEntryHook;

/** "Road Trip" in Overview -> Plugins. */
class MenuEntry implements MenuEntryHook
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array{0: string, 1: array<string, mixed>}
     */
    public function handle(string $pluginName): array
    {
        return ["$pluginName::menu", []];
    }
}
