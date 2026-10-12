<?php

namespace App\Http\Controllers\ERP\CRM;

use App\Http\Controllers\Controller;
use App\Models\Depot;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DepotController extends Controller
{
    public function index(
        Request $request
    ): Response {
        $depots =
            Depot::query()
                ->with([
                    'company:id,name',
                    'division:id,name',
                    'district:id,name',
                    'manager:id,name',
                ])
                ->latest()
                ->paginate(20)
                ->withQueryString();

        return Inertia::render(
            'ERP/CRM/Depots/Index',
            [
                'depots' => $depots,
            ]
        );
    }

    public function show(
        Depot $depot
    ): Response {
        $depot->load([
            'company:id,name',
            'division:id,name',
            'district:id,name',
            'manager:id,name,email',
            'applications.documents',
        ]);

        return Inertia::render(
            'ERP/CRM/Depots/Show',
            [
                'depot' => $depot,
            ]
        );
    }
}
