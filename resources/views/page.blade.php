@extends('layouts.librenmsv1')

@section('title', 'Road Trip')

@section('css')
<style>
    .rt-page { padding: 8px 14px 14px; }
    .rt-head { display: flex; align-items: baseline; gap: 14px; flex-wrap: wrap; margin-bottom: 8px; }
    .rt-head h2 { margin: 0; font-size: 22px; font-weight: 700; color: #1f2937; }
    .rt-head p { margin: 0; color: #6b7280; }
    .rt-wrap { position: relative; height: calc(100vh - 150px); min-height: 460px; border-radius: 8px; overflow: hidden;
               background: #1d6fa5; user-select: none; box-shadow: 0 2px 10px rgba(0, 0, 0, .15); }
    .rt-wrap canvas { display: block; width: 100%; height: 100%; outline: none; }
    .rt-hud { position: absolute; top: 10px; left: 10px; background: rgba(15, 23, 42, .8); color: #f8fafc;
              border-radius: 8px; padding: 8px 12px; font: 13px/1.5 system-ui, sans-serif; pointer-events: none;
              min-width: 230px; max-width: 360px; }
    .rt-hud .rt-big { font-size: 20px; font-weight: 700; font-variant-numeric: tabular-nums; }
    .rt-hud .rt-muted { color: #cbd5e1; }
    .rt-hud .rt-road { margin-top: 4px; padding-top: 4px; border-top: 1px solid rgba(255, 255, 255, .15); }
    .rt-radio { position: absolute; top: 10px; left: 50%; transform: translateX(-50%); max-width: min(720px, 60%);
                background: rgba(15, 23, 42, .85); color: #f8fafc; border-radius: 20px; padding: 6px 16px;
                font: 13px/1.4 system-ui, sans-serif; pointer-events: none; white-space: nowrap; overflow: hidden;
                text-overflow: ellipsis; transition: opacity .4s; }
    .rt-radio .rt-sev { display: inline-block; width: 9px; height: 9px; border-radius: 50%; margin: 0 6px 0 4px; }
    .rt-help { position: absolute; bottom: 10px; left: 10px; background: rgba(15, 23, 42, .75); color: #e2e8f0;
               border-radius: 8px; padding: 6px 10px; font: 12px/1.5 system-ui, sans-serif; pointer-events: none;
               max-width: calc(100% - 250px); }
    .rt-help kbd { background: #334155; color: #fff; border: 0; padding: 1px 5px; font-size: 11px; box-shadow: none; }
    .rt-start { position: absolute; inset: 0; display: flex; align-items: center; justify-content: center;
                background: rgba(15, 23, 42, .45); color: #fff; font: 600 22px system-ui, sans-serif; cursor: pointer; }
    .rt-start span { background: rgba(15, 23, 42, .88); padding: 16px 26px; border-radius: 10px; text-align: center; }
    .rt-start small { display: block; font-size: 14px; font-weight: 400; color: #cbd5e1; margin-top: 6px; }
    .rt-bar { position: absolute; inset: 0; z-index: 5; display: none; align-items: center; justify-content: center;
              background: rgba(0, 0, 0, .65); }
    .rt-bar.rt-open { display: flex; }
    .rt-bar canvas { width: min(800px, 94%); aspect-ratio: 16 / 9; height: auto; image-rendering: pixelated;
                     border: 6px solid #0f172a; border-radius: 8px; box-shadow: 0 20px 60px rgba(0, 0, 0, .5); }
    .rt-bar-skip { position: absolute; bottom: 14px; right: 18px; color: #e2e8f0; font: 12px system-ui, sans-serif; }
    .rt-modal { position: fixed; inset: 0; z-index: 2000; background: rgba(15, 23, 42, .6); display: none;
                align-items: center; justify-content: center; }
    .rt-modal.rt-open { display: flex; }
    .rt-dialog { width: min(1280px, 95vw); height: 88vh; background: #fff; border-radius: 8px; display: flex;
                 flex-direction: column; overflow: hidden; box-shadow: 0 20px 60px rgba(0, 0, 0, .4); }
    .rt-dialog-head { display: flex; align-items: center; gap: 10px; padding: 10px 14px; border-bottom: 1px solid #e5e7eb; }
    .rt-dialog-head h3 { margin: 0; font-size: 17px; flex: 1; color: #1f2937; }
    .rt-dialog iframe { flex: 1; width: 100%; border: 0; }
    .rt-empty { padding: 40px; text-align: center; color: #fff; font: 16px system-ui, sans-serif; }
</style>
@endsection

@section('content')
<div class="rt-page">
    <div class="rt-head">
        <h2><i class="fa fa-car" aria-hidden="true"></i> Road Trip</h2>
        <p>Your custom maps are islands, links are roads with live traffic, and map links are bridges. Stop on a
            <strong>P</strong> to look inside.</p>
    </div>
    <div class="rt-wrap" id="rt-wrap">
        <canvas id="rt-canvas" tabindex="0" aria-label="Road Trip - drive with the arrow keys"></canvas>
        <div class="rt-radio" id="rt-radio"></div>
        <div class="rt-hud" id="rt-hud"></div>
        <div class="rt-help">
            <kbd>↑</kbd><kbd>↓</kbd><kbd>←</kbd><kbd>→</kbd> / <kbd>W</kbd><kbd>A</kbd><kbd>S</kbd><kbd>D</kbd> drive
            · <kbd>Space</kbd> brake · stop on a <strong>P</strong> to visit · <kbd>N</kbd> next problem · <kbd>O</kbd> overview · <kbd>J</kbd> jump ramp
            · <kbd>Esc</kbd> back on the road · <kbd>H</kbd> horn · <kbd>M</kbd> sound · <kbd>R</kbd> back to the start
        </div>
        <div class="rt-bar" id="rt-bar" aria-live="polite">
            <canvas id="rt-bar-canvas" width="800" height="450" aria-label="Bar scene"></canvas>
            <div class="rt-bar-skip">Esc to skip</div>
        </div>
        <div class="rt-start" id="rt-start"><span>🚗 Click here, then drive with the arrow keys
            <small>Islands: {{ count($world['islands']) }} · press N to drive to the next problem</small></span></div>
    </div>
</div>

<div class="rt-modal" id="rt-modal" role="dialog" aria-modal="true" aria-labelledby="rt-modal-title">
    <div class="rt-dialog">
        <div class="rt-dialog-head">
            <h3 id="rt-modal-title"></h3>
            <a class="btn btn-sm btn-default" id="rt-modal-open" href="#" target="_top">
                <i class="fa fa-external-link" aria-hidden="true"></i> Open page
            </a>
            <button type="button" class="btn btn-sm btn-primary" id="rt-modal-close">
                <i class="fa fa-car" aria-hidden="true"></i> Back on the road (Esc)
            </button>
        </div>
        <iframe id="rt-frame" title="LibreNMS page"></iframe>
    </div>
</div>

<script type="application/json" id="rt-data">{!! json_encode($world, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES) !!}</script>
<script type="application/json" id="rt-config">{!! json_encode(['start' => $start, 'refresh' => route('roadtrip.world'), 'version' => $version], JSON_HEX_TAG | JSON_UNESCAPED_SLASHES) !!}</script>
<script>
@include('roadtrip::game')
</script>
@endsection
