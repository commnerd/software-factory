<?php

namespace App\Http\Middleware;

use App\Models\ApprovalGate;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'pendingGates' => fn () => ApprovalGate::where('status', 'pending')->count(),
            'flash' => fn () => [
                'success' => $request->session()->get('success'),
                'error' => $request->session()->get('error'),
                'skill_test' => $request->session()->get('skill_test'),
            ],
        ];
    }
}
