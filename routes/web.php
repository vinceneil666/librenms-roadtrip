<?php

use Illuminate\Support\Facades\Route;
use Vinceneil666\LibrenmsRoadtrip\Http\RoadTripController;

Route::middleware(['web', 'auth'])->group(function (): void {
    Route::get('plugin/roadtrip', [RoadTripController::class, 'page'])->name('roadtrip.page');
    Route::get('plugin/roadtrip/world', [RoadTripController::class, 'world'])->name('roadtrip.world');
});
