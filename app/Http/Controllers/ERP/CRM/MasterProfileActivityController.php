<?php

namespace App\Http\Controllers\ERP\CRM;

use App\Http\Controllers\Controller;
use App\Models\Dealer;
use App\Models\Depot;
use App\Models\MasterProfileActivity;
use Inertia\Inertia;
use Inertia\Response;

class MasterProfileActivityController extends Controller
{
    public function depot(
        Depot $depot
    ): Response {
        $depot->load([
            'company:id,name',
            'division:id,name',
            'district:id,name',
        ]);

        return $this->renderHistory(
            subject: $depot,

            type: 'Depot',

            name: $depot->name,

            code: $depot->depot_code,

            backUrl: "/erp/crm/depots/{$depot->id}"
        );
    }

    public function dealer(
        Dealer $dealer
    ): Response {
        $dealer->load([
            'company:id,name',
            'depot:id,name,depot_code',
            'upazila:id,name',
        ]);

        return $this->renderHistory(
            subject: $dealer,

            type: 'Dealer',

            name: $dealer->business_name,

            code: $dealer->dealer_code,

            backUrl: "/erp/crm/dealers/{$dealer->id}"
        );
    }

    private function renderHistory(
        Depot|Dealer $subject,
        string $type,
        string $name,
        ?string $code,
        string $backUrl
    ): Response {
        $activities =
            MasterProfileActivity::query()
                ->where(
                    'subject_type',
                    $subject->getMorphClass()
                )
                ->where(
                    'subject_id',
                    $subject->getKey()
                )
                ->with([
                    'actor:id,name,email',
                ])
                ->latest()
                ->paginate(30);

        return Inertia::render(
            'ERP/CRM/Activity/Index',
            [
                'subject' => [
                    'type' => $type,

                    'id' => $subject->getKey(),

                    'name' => $name,

                    'code' => $code,

                    'back_url' => $backUrl,
                ],

                'activities' => $activities,
            ]
        );
    }
}
