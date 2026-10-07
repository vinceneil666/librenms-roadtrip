<?php

/**
 * Demo data for trying Road Trip on a TEST LibreNMS - never run it on a production install.
 *
 *   lnms tinker --execute="require '/path/to/librenms-roadtrip/dev/seed-demo.php';"
 *
 * Creates (and first removes again) a small made-up network, all host names ending in .demo.example.net:
 * locations, devices, ports with traffic rates, LLDP links, four custom maps that link to each other
 * (Core <-> Oslo Campus / Bergen DC / Branch Offices), a few unmapped devices, alerts and event log
 * entries. There is no poller in the test setup, so the numbers stay as seeded.
 */

use App\Models\Alert;
use App\Models\AlertRule;
use App\Models\CustomMap;
use App\Models\CustomMapEdge;
use App\Models\CustomMapNode;
use App\Models\Device;
use App\Models\Eventlog;
use App\Models\Link;
use App\Models\Location;
use App\Models\Port;

$suffix = '.demo.example.net';
$mapNames = ['Core Network', 'Oslo Campus', 'Bergen DC', 'Branch Offices'];

// ------------------------------------------------------------------ clean up a previous run
$old = Device::where('hostname', 'like', '%' . $suffix)->pluck('device_id');
Alert::whereIn('device_id', $old)->delete();
AlertRule::where('name', 'like', 'Road Trip demo:%')->delete();
Eventlog::whereIn('device_id', $old)->delete();
Link::whereIn('local_device_id', $old)->delete();
Port::whereIn('device_id', $old)->delete();
foreach (CustomMap::whereIn('name', $mapNames)->get() as $map) {
    $map->edges()->delete();
    $map->nodes()->delete();
    $map->delete();
}
Device::whereIn('device_id', $old)->delete();
Location::where('location', 'like', 'Demo: %')->delete();

// ------------------------------------------------------------------ locations
$locations = [];
foreach ([
    'core' => ['Demo: Oslo core site', 59.9127, 10.7461],
    'osl' => ['Demo: Oslo campus', 59.9399, 10.7217],
    'bgo' => ['Demo: Bergen data centre', 60.3913, 5.3221],
    'trd' => ['Demo: Trondheim office', 63.4305, 10.3951],
    'svg' => ['Demo: Stavanger office', 58.9700, 5.7331],
    'krs' => ['Demo: Kristiansand office', 58.1599, 8.0182],
] as $key => [$name, $lat, $lng]) {
    $locations[$key] = Location::create(['location' => $name, 'lat' => $lat, 'lng' => $lng, 'timestamp' => now()]);
}

// ------------------------------------------------------------------ devices
$devices = [];
$day = 86400;
$device = function (string $name, string $os, string $type, string $hardware, string $loc, array $extra = []) use (&$devices, $suffix, $locations, $day) {
    $d = new Device;
    $d->forceFill(array_merge([
        'hostname' => $name . $suffix,
        'sysName' => $name,
        'os' => $os,
        'type' => $type,
        'hardware' => $hardware,
        'status' => 1,
        'status_reason' => '',
        'uptime' => rand(20, 400) * $day,
        'disabled' => 0,
        'ignore' => 0,
        'snmp_disable' => 1,
        'location_id' => $locations[$loc]->id,
        'last_polled' => now(),
        'serial' => strtoupper(substr(md5($name), 0, 12)),
    ], $extra));
    $d->save();
    $devices[$name] = $d;

    return $d;
};

$device('edge-rtr-01', 'iosxe', 'network', 'Cisco ASR 1001-X', 'core');
$device('edge-rtr-02', 'iosxe', 'network', 'Cisco ASR 1001-X', 'core');
$device('fw-core-01', 'fortigate', 'firewall', 'FortiGate 200F', 'core');
$device('core-sw-01', 'nxos', 'network', 'Cisco Nexus 93180YC-FX', 'core');
$device('core-sw-02', 'nxos', 'network', 'Cisco Nexus 93180YC-FX', 'core');

$device('dist-sw-osl-01', 'iosxe', 'network', 'Cisco Catalyst 9500', 'osl');
$device('acc-sw-osl-01', 'iosxe', 'network', 'Cisco Catalyst 9300', 'osl');
$device('acc-sw-osl-02', 'iosxe', 'network', 'Cisco Catalyst 9300', 'osl', ['uptime' => 2400]);   // just rebooted
$device('acc-sw-osl-03', 'iosxe', 'network', 'Cisco Catalyst 9300', 'osl');
$device('wlc-osl-01', 'ciscowlc', 'wireless', 'Cisco Catalyst 9800-L', 'osl');
$device('ap-osl-01', 'ciscowap', 'wireless', 'Cisco Catalyst 9120AX', 'osl');
$device('ap-osl-02', 'ciscowap', 'wireless', 'Cisco Catalyst 9120AX', 'osl');
$device('ap-osl-03', 'ciscowap', 'wireless', 'Cisco Catalyst 9120AX', 'osl', ['status' => 0, 'status_reason' => 'icmp']);

$device('spine-bgo-01', 'junos', 'network', 'Juniper QFX5120-32C', 'bgo');
$device('spine-bgo-02', 'junos', 'network', 'Juniper QFX5120-32C', 'bgo');
$device('leaf-bgo-01', 'junos', 'network', 'Juniper QFX5120-48Y', 'bgo');
$device('leaf-bgo-02', 'junos', 'network', 'Juniper QFX5120-48Y', 'bgo');
$device('esx-bgo-01', 'vmware', 'server', 'Dell PowerEdge R760', 'bgo');
$device('esx-bgo-02', 'vmware', 'server', 'Dell PowerEdge R760', 'bgo');
$device('esx-bgo-03', 'vmware', 'server', 'Dell PowerEdge R760', 'bgo', ['disabled' => 1]);
$device('nas-bgo-01', 'linux', 'storage', 'NetApp AFF A250', 'bgo');

$device('fw-trd-01', 'fortigate', 'firewall', 'FortiGate 60F', 'trd');
$device('sw-trd-01', 'arubaos-cx', 'network', 'Aruba 6100', 'trd');
$device('fw-svg-01', 'fortigate', 'firewall', 'FortiGate 60F', 'svg', ['status' => 0, 'status_reason' => 'icmp']);
$device('sw-svg-01', 'arubaos-cx', 'network', 'Aruba 6100', 'svg');
$device('fw-krs-01', 'fortigate', 'firewall', 'FortiGate 40F', 'krs');

// not on any custom map - only found through LLDP
$device('lab-sw-01', 'procurve', 'network', 'HPE 2930F', 'osl');
$device('lab-sw-02', 'procurve', 'network', 'HPE 2930F', 'osl');
$device('printer-osl-01', 'linux', 'printer', 'HP LaserJet M507', 'osl');
$device('ups-osl-01', 'apc', 'power', 'APC Smart-UPS 3000', 'osl');

// ------------------------------------------------------------------ ports: name, speed (bit/s), load in % (in / out)
$ifIndex = [];
$port = function (string $dev, string $name, int $speedMbit, float $inPct, float $outPct, string $oper = 'up', string $alias = '') use (&$devices, &$ifIndex) {
    $d = $devices[$dev];
    $ifIndex[$dev] = ($ifIndex[$dev] ?? 0) + 1;
    $speed = $speedMbit * 1000000;
    $p = new Port;
    $p->forceFill([
        'device_id' => $d->device_id,
        'ifIndex' => $ifIndex[$dev],
        'ifName' => $name,
        'ifDescr' => $name,
        'ifAlias' => $alias,
        'ifType' => 'ethernetCsmacd',
        'ifSpeed' => $speed,
        'ifOperStatus' => $oper,
        'ifAdminStatus' => 'up',
        'ifInOctets_rate' => $oper === 'up' ? (int) ($speed * $inPct / 100 / 8) : 0,
        'ifOutOctets_rate' => $oper === 'up' ? (int) ($speed * $outPct / 100 / 8) : 0,
        'ifInErrors_rate' => 0,
        'ifOutErrors_rate' => 0,
        'poll_time' => time(),
    ]);
    $p->save();

    return $p;
};

// ------------------------------------------------------------------ custom maps
$newMap = function (string $name, int $w, int $h) {
    $m = new CustomMap;
    $m->forceFill([
        'name' => $name, 'menu_group' => 'Road Trip demo', 'width' => $w . 'px', 'height' => $h . 'px',
        'node_align' => 10, 'reverse_arrows' => 0, 'edge_separation' => 10,
        'options' => ['interaction' => ['dragNodes' => false, 'dragView' => true, 'zoomView' => true],
                      'manipulation' => ['enabled' => false], 'physics' => ['enabled' => false]],
        'newnodeconfig' => ['borderWidth' => 1, 'color' => ['border' => '#2B7CE9', 'background' => '#D2E5FF'],
                            'font' => ['color' => '#343434', 'size' => 14, 'face' => 'arial'], 'icon' => [],
                            'label' => true, 'shape' => 'box', 'size' => 25],
        'newedgeconfig' => ['arrows' => ['to' => ['enabled' => true]], 'smooth' => ['type' => 'dynamic'],
                            'font' => ['color' => '#343434', 'size' => 12, 'face' => 'arial', 'align' => 'horizontal'],
                            'label' => true],
        'background_type' => 'none',
        'legend_colours' => null,
    ]);
    $m->save();

    return $m;
};
$maps = [
    'core' => $newMap('Core Network', 1400, 900),
    'osl' => $newMap('Oslo Campus', 1400, 900),
    'bgo' => $newMap('Bergen DC', 1400, 900),
    'branch' => $newMap('Branch Offices', 1400, 900),
];

$nodes = [];
$node = function (string $mapKey, string $key, int $x, int $y, ?string $dev = null, ?string $linkedMap = null, ?string $label = null) use (&$nodes, $maps, &$devices) {
    $n = new CustomMapNode;
    $n->forceFill([
        'custom_map_id' => $maps[$mapKey]->custom_map_id,
        'device_id' => $dev ? $devices[$dev]->device_id : null,
        'linked_custom_map_id' => $linkedMap ? $maps[$linkedMap]->custom_map_id : null,
        'label' => $label ?? $dev ?? $key,
        'style' => $linkedMap ? 'ellipse' : ($dev ? 'box' : 'icon'),
        'icon' => $dev || $linkedMap ? null : 'f0c2',
        'image' => '',
        'size' => 25, 'border_width' => 1, 'text_face' => 'arial', 'text_size' => 14, 'text_colour' => '#343434',
        'colour_bg' => $linkedMap ? '#FFE8A3' : '#D2E5FF', 'colour_bdr' => '#2B7CE9',
        'x_pos' => $x, 'y_pos' => $y,
    ]);
    $n->save();
    $nodes[$mapKey . ':' . $key] = $n;

    return $n;
};
$edge = function (string $mapKey, string $a, string $b, ?Port $p, string $label = '') use (&$nodes, $maps) {
    $na = $nodes[$mapKey . ':' . $a];
    $nb = $nodes[$mapKey . ':' . $b];
    $e = new CustomMapEdge;
    $e->forceFill([
        'custom_map_id' => $maps[$mapKey]->custom_map_id,
        'custom_map_node1_id' => $na->custom_map_node_id, 'custom_map_node2_id' => $nb->custom_map_node_id,
        'port_id' => $p?->port_id, 'reverse' => 0, 'style' => 'dynamic', 'showpct' => 1, 'showbps' => 0,
        'label' => $label, 'text_face' => 'arial', 'text_size' => 12, 'text_colour' => '#343434', 'text_align' => 'horizontal',
        'mid_x' => intdiv($na->x_pos + $nb->x_pos, 2), 'mid_y' => intdiv($na->y_pos + $nb->y_pos, 2),
    ]);
    $e->save();

    return $e;
};
$lldp = function (Port $a, Port $b) {
    foreach ([[$a, $b], [$b, $a]] as [$l, $r]) {
        $rd = $r->device;
        $link = new Link;
        $link->forceFill([
            'local_port_id' => $l->port_id, 'local_device_id' => $l->device_id,
            'remote_port_id' => $r->port_id, 'remote_device_id' => $r->device_id,
            'active' => 1, 'protocol' => 'lldp', 'remote_hostname' => $rd->sysName,
            'remote_port' => $r->ifName, 'remote_platform' => $rd->hardware, 'remote_version' => '',
        ]);
        $link->save();
    }
};
// a connection: one port on each side (LLDP both ways), an edge on the map using the first port
$connect = function (string $mapKey, string $a, string $aPort, string $b, string $bPort, int $mbit, float $inPct, float $outPct, string $oper = 'up') use ($port, $edge, $lldp, &$devices, &$nodes) {
    $pa = $port($a, $aPort, $mbit, $inPct, $outPct, $oper, "to $b");
    if (isset($devices[$b])) {
        $pb = $port($b, $bPort, $mbit, $outPct, $inPct, $oper, "to $a");
        $lldp($pa, $pb);
    }
    $edge($mapKey, $a, $b, $pa);
};

// Core Network
$node('core', 'internet', 140, 200, null, null, 'Internet');
$node('core', 'edge-rtr-01', 380, 120, 'edge-rtr-01');
$node('core', 'edge-rtr-02', 380, 300, 'edge-rtr-02');
$node('core', 'fw-core-01', 620, 210, 'fw-core-01');
$node('core', 'core-sw-01', 860, 120, 'core-sw-01');
$node('core', 'core-sw-02', 860, 320, 'core-sw-02');
$node('core', 'to-osl', 1180, 80, null, 'osl', 'Oslo Campus');
$node('core', 'to-bgo', 1180, 330, null, 'bgo', 'Bergen DC');
$node('core', 'to-branch', 760, 620, null, 'branch', 'Branch Offices');
$edge('core', 'edge-rtr-01', 'internet', $port('edge-rtr-01', 'Te0/0/0', 10000, 41, 18, 'up', 'Transit A'));
$edge('core', 'edge-rtr-02', 'internet', $port('edge-rtr-02', 'Te0/0/0', 10000, 12, 6, 'up', 'Transit B'));
$connect('core', 'fw-core-01', 'port1', 'edge-rtr-01', 'Te0/0/1', 10000, 38, 15);
$connect('core', 'fw-core-01', 'port2', 'edge-rtr-02', 'Te0/0/1', 10000, 9, 4);
$connect('core', 'core-sw-01', 'Eth1/49', 'fw-core-01', 'port3', 10000, 22, 47);
$connect('core', 'core-sw-02', 'Eth1/49', 'fw-core-01', 'port4', 10000, 7, 11);
$connect('core', 'core-sw-01', 'Eth1/53', 'core-sw-02', 'Eth1/53', 40000, 3, 3);

// uplinks to the other maps: ports on the core switches, edges to the bridge nodes
$upOsl = $port('core-sw-01', 'Eth1/1', 10000, 63, 28, 'up', 'Oslo Campus uplink');
$edge('core', 'core-sw-01', 'to-osl', $upOsl);
$upBgo = $port('core-sw-02', 'Eth1/2', 40000, 97, 81, 'up', 'Bergen DC uplink');
$edge('core', 'core-sw-02', 'to-bgo', $upBgo);
$upBranch = $port('core-sw-02', 'Eth1/3', 1000, 31, 12, 'up', 'MPLS to branches');
$edge('core', 'core-sw-02', 'to-branch', $upBranch);

// Oslo Campus
$node('osl', 'to-core', 140, 120, null, 'core', 'Core Network');
$node('osl', 'dist-sw-osl-01', 420, 160, 'dist-sw-osl-01');
$node('osl', 'acc-sw-osl-01', 720, 60, 'acc-sw-osl-01');
$node('osl', 'acc-sw-osl-02', 720, 260, 'acc-sw-osl-02');
$node('osl', 'acc-sw-osl-03', 720, 460, 'acc-sw-osl-03');
$node('osl', 'wlc-osl-01', 420, 420, 'wlc-osl-01');
$node('osl', 'ap-osl-01', 1020, 40, 'ap-osl-01');
$node('osl', 'ap-osl-02', 1020, 240, 'ap-osl-02');
$node('osl', 'ap-osl-03', 1020, 460, 'ap-osl-03');
$p = $port('dist-sw-osl-01', 'Te1/1/1', 10000, 28, 63, 'up', 'to core-sw-01');
$lldp($p, $upOsl);
$edge('osl', 'dist-sw-osl-01', 'to-core', $p);
$connect('osl', 'dist-sw-osl-01', 'Te1/0/1', 'acc-sw-osl-01', 'Te1/1/1', 10000, 18, 44);
$connect('osl', 'dist-sw-osl-01', 'Te1/0/2', 'acc-sw-osl-02', 'Te1/1/1', 10000, 6, 9);
$connect('osl', 'dist-sw-osl-01', 'Te1/0/3', 'acc-sw-osl-03', 'Te1/1/1', 10000, 72, 55);
$connect('osl', 'dist-sw-osl-01', 'Te1/0/4', 'wlc-osl-01', 'Te0/1/0', 10000, 14, 21);
$connect('osl', 'acc-sw-osl-01', 'Gi1/0/1', 'ap-osl-01', 'Gi0', 1000, 35, 58);
$connect('osl', 'acc-sw-osl-02', 'Gi1/0/1', 'ap-osl-02', 'Gi0', 1000, 12, 26);
$connect('osl', 'acc-sw-osl-03', 'Gi1/0/1', 'ap-osl-03', 'Gi0', 1000, 0, 0, 'down');

// Bergen DC
$node('bgo', 'to-core', 140, 420, null, 'core', 'Core Network');
$node('bgo', 'spine-bgo-01', 460, 180, 'spine-bgo-01');
$node('bgo', 'spine-bgo-02', 460, 620, 'spine-bgo-02');
$node('bgo', 'leaf-bgo-01', 820, 180, 'leaf-bgo-01');
$node('bgo', 'leaf-bgo-02', 820, 620, 'leaf-bgo-02');
$node('bgo', 'esx-bgo-01', 1180, 80, 'esx-bgo-01');
$node('bgo', 'esx-bgo-02', 1180, 300, 'esx-bgo-02');
$node('bgo', 'esx-bgo-03', 1180, 520, 'esx-bgo-03');
$node('bgo', 'nas-bgo-01', 1180, 740, 'nas-bgo-01');
$p = $port('spine-bgo-01', 'et-0/0/0', 40000, 81, 97, 'up', 'to core-sw-02');
$lldp($p, $upBgo);
$edge('bgo', 'spine-bgo-01', 'to-core', $p);
$connect('bgo', 'spine-bgo-02', 'et-0/0/0', 'spine-bgo-01', 'et-0/0/1', 40000, 15, 12);
$connect('bgo', 'leaf-bgo-01', 'et-0/0/48', 'spine-bgo-01', 'et-0/0/2', 100000, 44, 39);
$connect('bgo', 'leaf-bgo-01', 'et-0/0/49', 'spine-bgo-02', 'et-0/0/2', 100000, 41, 36);
$connect('bgo', 'leaf-bgo-02', 'et-0/0/48', 'spine-bgo-01', 'et-0/0/3', 100000, 23, 19);
$connect('bgo', 'leaf-bgo-02', 'et-0/0/49', 'spine-bgo-02', 'et-0/0/3', 100000, 0, 0, 'down');
$connect('bgo', 'esx-bgo-01', 'vmnic0', 'leaf-bgo-01', 'xe-0/0/1', 25000, 52, 64);
$connect('bgo', 'esx-bgo-02', 'vmnic0', 'leaf-bgo-01', 'xe-0/0/2', 25000, 118, 91);   // overloaded
$connect('bgo', 'esx-bgo-03', 'vmnic0', 'leaf-bgo-02', 'xe-0/0/1', 25000, 0, 0);
$connect('bgo', 'nas-bgo-01', 'e0a', 'leaf-bgo-02', 'xe-0/0/2', 25000, 33, 71);

// Branch Offices
$node('branch', 'to-core', 700, 120, null, 'core', 'Core Network');
$node('branch', 'fw-trd-01', 300, 380, 'fw-trd-01');
$node('branch', 'sw-trd-01', 160, 620, 'sw-trd-01');
$node('branch', 'fw-svg-01', 700, 420, 'fw-svg-01');
$node('branch', 'sw-svg-01', 700, 700, 'sw-svg-01');
$node('branch', 'fw-krs-01', 1100, 380, 'fw-krs-01');
foreach (['fw-trd-01' => [24, 11], 'fw-svg-01' => [0, 0], 'fw-krs-01' => [57, 22]] as $fw => [$in, $out]) {
    $p = $port($fw, 'wan1', 1000, $in, $out, $fw === 'fw-svg-01' ? 'down' : 'up', 'MPLS');
    $edge('branch', $fw, 'to-core', $p);
}
$connect('branch', 'sw-trd-01', 'uplink', 'fw-trd-01', 'internal1', 1000, 19, 8);
$connect('branch', 'sw-svg-01', 'uplink', 'fw-svg-01', 'internal1', 1000, 0, 0, 'down');

// devices found only through LLDP (no custom map): lab switches, a printer, a UPS
$p1 = $port('lab-sw-01', '49', 10000, 4, 7, 'up', 'to dist-sw-osl-01');
$lldp($p1, $port('dist-sw-osl-01', 'Te1/0/8', 10000, 7, 4, 'up', 'to lab-sw-01'));
$lldp($port('lab-sw-01', '50', 1000, 9, 3), $port('lab-sw-02', '50', 1000, 3, 9));
$lldp($port('lab-sw-02', '1', 1000, 1, 2), $port('printer-osl-01', 'eth0', 1000, 2, 1));
$lldp($port('lab-sw-02', '2', 1000, 0, 1), $port('ups-osl-01', 'mgmt', 100, 1, 0));

// ------------------------------------------------------------------ alerts and event log
$rule = new AlertRule;
$rule->forceFill(['name' => 'Road Trip demo: device down', 'severity' => 'critical', 'disabled' => 0,
                  'query' => '', 'builder' => '{}', 'extra' => '{}', 'proc' => '', 'notes' => '', 'invert_map' => 0]);
$rule->save();
$warnRule = new AlertRule;
$warnRule->forceFill(['name' => 'Road Trip demo: port utilisation over 90%', 'severity' => 'warning', 'disabled' => 0,
                      'query' => '', 'builder' => '{}', 'extra' => '{}', 'proc' => '', 'notes' => '', 'invert_map' => 0]);
$warnRule->save();
foreach ([['fw-svg-01', $rule], ['ap-osl-03', $rule], ['core-sw-02', $warnRule], ['esx-bgo-02', $warnRule]] as [$dev, $r]) {
    $a = new Alert;
    $a->forceFill(['device_id' => $devices[$dev]->device_id, 'rule_id' => $r->id, 'state' => 1, 'alerted' => 1,
                   'open' => 1, 'note' => '', 'timestamp' => now(), 'info' => '']);
    $a->save();
}

$events = [
    ['fw-svg-01', 'Device status changed to Down from icmp check.', 'availability', 5, 18],
    ['ap-osl-03', 'Device status changed to Down from icmp check.', 'availability', 5, 42],
    ['acc-sw-osl-02', 'Device rebooted after 212 days 4 hours', 'reboot', 4, 40],
    ['spine-bgo-02', 'Interface et-0/0/3 went down (ifOperStatus up -> down)', 'interface', 4, 55],
    ['core-sw-02', 'Port Eth1/2 utilisation above 90% (Bergen DC uplink)', 'interface', 3, 7],
    ['esx-bgo-02', 'Port vmnic0 utilisation above 100%', 'interface', 3, 3],
    ['fw-core-01', 'Configuration changed by admin', 'system', 2, 90],
    ['wlc-osl-01', '212 wireless clients associated', 'wireless', 1, 12],
];
foreach ($events as [$dev, $msg, $type, $severity, $minutesAgo]) {
    $e = new Eventlog;
    $e->forceFill(['device_id' => $devices[$dev]->device_id, 'datetime' => now()->subMinutes($minutesAgo),
                   'message' => $msg, 'type' => $type, 'severity' => $severity, 'reference' => null, 'username' => '']);
    $e->save();
}

// LibreNMS logs "Device ... has been created" for every device above - keep the radio to the interesting news
Eventlog::whereIn('device_id', collect($devices)->pluck('device_id'))->where('message', 'like', '%has been created%')->delete();

echo 'Road Trip demo: ' . count($devices) . ' devices, ' . Port::whereIn('device_id', collect($devices)->pluck('device_id'))->count()
    . ' ports, ' . count($maps) . " custom maps.\n";
