<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

final class EmergencyPageController
{
    public function __invoke(Request $request, string $code): View
    {
        return view('emergency', [
            'code' => $code,
            'bffBaseUrl' => (string) config('services.emergency_bff.url', '/de-bff/emergency'),
        ]);
    }
}
