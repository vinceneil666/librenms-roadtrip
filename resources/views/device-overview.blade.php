<div class="panel panel-default">
    <div class="panel-heading">
        <a href="{{ route('roadtrip.page', ['device' => $device->device_id]) }}">
            <i class="fa fa-car fa-fw" aria-hidden="true"></i> <strong>Road Trip</strong>
        </a>
    </div>
    <div class="panel-body">
        <a class="btn btn-sm btn-primary" href="{{ route('roadtrip.page', ['device' => $device->device_id]) }}">
            <i class="fa fa-car" aria-hidden="true"></i> Drive to {{ $device->displayName() }}
        </a>
        <span class="text-muted" style="margin-left: 8px">start the car right outside this device</span>
    </div>
</div>
