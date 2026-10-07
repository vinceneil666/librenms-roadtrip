<?php

namespace Vinceneil666\LibrenmsRoadtrip\Http;

use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use LibreNMS\Interfaces\Plugins\PluginManagerInterface;
use Vinceneil666\LibrenmsRoadtrip\RoadTripProvider;
use Vinceneil666\LibrenmsRoadtrip\World;

class RoadTripController extends Controller
{
    public function page(Request $request, PluginManagerInterface $plugins): View
    {
        $settings = array_merge(World::DEFAULTS, $plugins->getSettings(RoadTripProvider::NAME));

        return view(RoadTripProvider::NAME . '::page', [
            'world' => (new World($request->user(), $settings))->build(),
            'start' => $request->integer('device') ?: null,
            'version' => RoadTripProvider::VERSION,
        ]);
    }

    /** The same data as JSON - the game reloads it to keep traffic and status fresh. */
    public function world(Request $request, PluginManagerInterface $plugins): JsonResponse
    {
        $settings = array_merge(World::DEFAULTS, $plugins->getSettings(RoadTripProvider::NAME));

        return response()->json((new World($request->user(), $settings))->build());
    }
}
