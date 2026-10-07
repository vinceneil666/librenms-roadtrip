<div style="padding: 1em 2em">
    <h4>Road Trip settings</h4>
    <p class="text-muted">Change them with <code>lnms plugin:settings</code> or in the plugins table; defaults are shown.</p>
    <table class="table table-condensed" style="max-width: 640px">
        <tr><th>discovered_island</th><td>{{ $settings['discovered_island'] ? 'yes' : 'no' }}</td>
            <td class="text-muted">Build an extra island from LLDP/CDP neighbours that are on no custom map</td></tr>
        <tr><th>max_discovered</th><td>{{ $settings['max_discovered'] }}</td>
            <td class="text-muted">At most this many devices on that island</td></tr>
        <tr><th>radio_events</th><td>{{ $settings['radio_events'] }}</td>
            <td class="text-muted">Event log entries read out on the car radio</td></tr>
    </table>
</div>
