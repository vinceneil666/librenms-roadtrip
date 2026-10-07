<?php

namespace Vinceneil666\LibrenmsRoadtrip;

use App\Models\Alert;
use App\Models\CustomMap;
use App\Models\Device;
use App\Models\Eventlog;
use App\Models\Link;
use App\Models\Port;
use App\Models\User;
use Illuminate\Support\Collection;
use LibreNMS\Util\Url;

/**
 * Everything the game needs, as plain arrays: the custom maps the user may view (islands), their nodes
 * (buildings, landmarks and bridges) and edges (roads, with live port traffic), the devices that are only
 * known through LLDP/CDP (an extra island) and recent events (the car radio) - only what the user may see.
 */
class World
{
    public const DEFAULTS = [
        // Build an extra island from LLDP/CDP neighbours of devices that are on no custom map
        'discovered_island' => true,
        // At most this many devices on the discovered island
        'max_discovered' => 150,
        // Event log entries for the car radio
        'radio_events' => 25,
    ];

    /** @var array<int, Device> */
    private array $devices = [];

    /** @var array<int, int> device_id => number of active alerts */
    private array $alerts = [];

    /**
     * @param  array<string, mixed>  $settings
     */
    public function __construct(private readonly User $user, private readonly array $settings = self::DEFAULTS)
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function build(): array
    {
        $maps = CustomMap::query()->orderBy('name')->get()
            ->filter(fn (CustomMap $map) => $this->user->can('view', $map))
            ->load(['nodes.device.location', 'edges.port.device']);

        $this->alerts = Alert::query()->where('state', '>', 0)
            ->selectRaw('device_id, count(*) as n')->groupBy('device_id')->pluck('n', 'device_id')->all();

        $islands = $maps->map(fn (CustomMap $map) => $this->island($map))->values()->all();

        $mappedDeviceIds = $maps->flatMap(fn (CustomMap $m) => $m->nodes->pluck('device_id'))->filter()->unique()->all();
        if ($this->settings['discovered_island']) {
            $discovered = $this->discoveredIsland($mappedDeviceIds);
            if ($discovered) {
                $islands[] = $discovered;
            }
        }

        return [
            'islands' => $islands,
            'radio' => $this->radio(),
            'generated' => now()->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function island(CustomMap $map): array
    {
        $nodes = $map->nodes->map(function ($node) {
            $device = $node->device;
            if ($device && ! $this->user->can('view', $device)) {
                $device = null;
            }

            return [
                'id' => 'n' . $node->custom_map_node_id,
                'x' => (int) $node->x_pos,
                'y' => (int) $node->y_pos,
                'label' => $node->label,
                'device' => $device ? $this->device($device) : null,
                'bridge' => $node->linked_custom_map_id,
                'bridge_down' => $node->linked_custom_map_id ? $node->linkedMapIsDown() : false,
            ];
        })->values();

        $edges = $map->edges->map(fn ($edge) => [
            'from' => 'n' . $edge->custom_map_node1_id,
            'to' => 'n' . $edge->custom_map_node2_id,
            'label' => $edge->label,
            'port' => $edge->port && $this->user->can('view', $edge->port->device) ? $this->port($edge->port, (bool) $edge->reverse) : null,
        ])->values();

        return [
            'id' => $map->custom_map_id,
            'name' => $map->name,
            'group' => $map->menu_group,
            'url' => url('maps/custom/' . $map->custom_map_id),
            'nodes' => $nodes->all(),
            'edges' => $edges->all(),
            'layout' => 'map',
        ];
    }

    /**
     * Devices that are on no custom map but are LLDP/CDP neighbours of each other or of mapped devices.
     *
     * @param  array<int, int>  $mappedDeviceIds
     * @return array<string, mixed>|null
     */
    private function discoveredIsland(array $mappedDeviceIds): ?array
    {
        $links = Link::query()->where('active', 1)->where('remote_device_id', '>', 0)
            ->whereIn('local_device_id', Device::query()->hasAccess($this->user)->select('device_id'))
            ->with(['port', 'device', 'remoteDevice'])->get();

        $mapped = array_flip($mappedDeviceIds);
        $ids = new Collection;
        foreach ($links as $link) {
            foreach ([$link->local_device_id, $link->remote_device_id] as $id) {
                if (! isset($mapped[$id])) {
                    $ids->push($id);
                }
            }
        }
        $ids = $ids->unique()->take((int) $this->settings['max_discovered']);
        if ($ids->isEmpty()) {
            return null;
        }

        $devices = Device::query()->hasAccess($this->user)->whereIn('device_id', $ids)->with('location')->get()->keyBy('device_id');
        $nodes = $devices->map(fn (Device $d) => [
            'id' => 'd' . $d->device_id, 'x' => null, 'y' => null, 'label' => $d->displayName(),
            'device' => $this->device($d), 'bridge' => null, 'bridge_down' => false,
        ]);

        // links between two discovered devices, once per pair; links to mapped devices become bridges
        $edges = [];
        $bridges = [];
        foreach ($links as $link) {
            $a = $link->local_device_id;
            $b = $link->remote_device_id;
            if ($devices->has($a) xor $devices->has($b)) {
                [$here, $there] = $devices->has($a) ? [$a, $b] : [$b, $a];
                if (isset($mapped[$there])) {
                    $bridges[$here . '-' . $there] = ['from' => 'd' . $here, 'to_device' => $there];
                }
                continue;
            }
            if (! $devices->has($a) || ! $devices->has($b)) {
                continue;
            }
            $key = min($a, $b) . '-' . max($a, $b);
            if (isset($edges[$key])) {
                continue;
            }
            $edges[$key] = [
                'from' => 'd' . $a, 'to' => 'd' . $b, 'label' => strtoupper((string) $link->protocol),
                'port' => $link->port ? $this->port($link->port, false) : null,
            ];
        }

        return [
            'id' => 'discovered',
            'name' => 'Discovered (LLDP/CDP)',
            'group' => null,
            'url' => url('map'),
            'nodes' => $nodes->values()->all(),
            'edges' => array_values($edges),
            'bridges' => array_values($bridges),
            'layout' => 'auto',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function device(Device $device): array
    {
        $warning = (int) \App\Facades\LibrenmsConfig::get('uptime_warning', 86400);
        $state = match (true) {
            (bool) $device->disabled => 'disabled',
            ! $device->status => 'down',
            $device->uptime > 0 && $device->uptime < $warning => 'rebooted',
            default => 'up',
        };

        return [
            'id' => $device->device_id,
            'name' => $device->displayName(),
            'short' => $device->sysName ?: $device->displayName(),
            'hostname' => $device->hostname,
            'url' => Url::deviceUrl($device),
            'type' => $device->type ?: 'network',
            'os' => $device->os,
            'hardware' => (string) $device->hardware,
            'location' => $device->location?->location,
            'state' => $state,
            'reason' => $device->status_reason,
            'alerts' => (int) ($this->alerts[$device->device_id] ?? 0),
            'uptime' => (int) $device->uptime,
            'icon' => asset($device->icon),
        ];
    }

    /**
     * Traffic of the port as LibreNMS custom maps compute it: rate / speed per direction.
     *
     * @return array<string, mixed>
     */
    private function port(Port $port, bool $reverse): array
    {
        [$speedOut, $speedIn] = $port->getSpeeds();
        $in = (float) $port->ifInOctets_rate * 8;
        $out = (float) $port->ifOutOctets_rate * 8;
        if ($reverse) {
            [$in, $out] = [$out, $in];
            [$speedIn, $speedOut] = [$speedOut, $speedIn];
        }
        $pct = fn (float $rate, int $speed) => $speed > 0 ? round($rate / $speed * 100, 1) : null;

        return [
            'id' => $port->port_id,
            'name' => $port->getLabel(),
            'device' => $port->device ? ($port->device->sysName ?: $port->device->displayName()) : null,
            'alias' => (string) $port->ifAlias,
            'url' => Url::portUrl($port),
            'graph' => url('graph.php') . '?' . http_build_query(['type' => 'port_bits', 'id' => $port->port_id,
                'from' => '-1d', 'width' => 480, 'height' => 160, 'legend' => 'no']),
            'speed' => max($speedIn, $speedOut),
            'up' => self::value($port->ifOperStatus) === 'up' && self::value($port->ifAdminStatus) !== 'down'
                && (bool) $port->device?->status,
            'in_bps' => $in,
            'out_bps' => $out,
            'in_pct' => $pct($in, $speedIn),
            'out_pct' => $pct($out, $speedOut),
        ];
    }

    /** Port status columns are enums in newer LibreNMS versions and strings in older ones. */
    private static function value(mixed $v): string
    {
        return $v instanceof \BackedEnum ? (string) $v->value : (string) $v;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function radio(): array
    {
        return Eventlog::query()->whereIn('device_id', Device::query()->hasAccess($this->user)->select('device_id'))
            ->with('device')->latest('datetime')->limit((int) $this->settings['radio_events'])->get()
            ->map(fn (Eventlog $e) => [
                'time' => (string) $e->datetime,
                'device' => $e->device ? ($e->device->sysName ?: $e->device->displayName()) : null,
                'device_id' => $e->device_id,
                'message' => (string) $e->message,
                'severity' => (int) $e->severity,
                'type' => (string) $e->type,
            ])->all();
    }
}
