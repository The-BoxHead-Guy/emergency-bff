<?php

declare(strict_types=1);

use App\Http\Controllers\EmergencyPageController;
use Illuminate\Support\Facades\Route;

Route::get('/emergency/{code}', EmergencyPageController::class)
    ->where('code', '[A-Za-z][A-Za-z0-9]{5}')
    ->name('emergency.panel');

/*
 * Embedded-kernel mode only. Keep this route last and exclude it from
 * Laravel's CSRF middleware; the BFF validates its own token.
 *
 * Route::any('/de-bff/emergency/{path?}', function (Illuminate\Http\Request $request) {
 *     $kernel = app(App\Emergency\EmbeddedBff::class)->kernel();
 *     $kernel
 *         ->handle(DataEmergencia\EmergencyBff\Http\Request::fromGlobals())
 *         ->send();
 * })->where('path', '.*')->middleware('web');
 */
