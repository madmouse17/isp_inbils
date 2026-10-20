<?php

namespace Modules\NetworkAsset\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Core\Company;
use App\Services\Core\SettingService;
use App\Services\WhatsApp\PhoneNumber;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * The page isolated subscribers are redirected to. The router sends no subscriber with it, so one page answers both
 * causes (bills unpaid, limited by the ISP) and offers the three ways out: call, WhatsApp, the customer app. Public: only
 * the company's own public contact fields and the app link, never anything about a subscriber or a session. An inactive
 * company answers like an unknown one.
 */
class IsolirPageController extends Controller
{
    public function __invoke(Company $company): InertiaResponse
    {
        abort_unless($company->is_active, 404);

        // A landline or a foreign number has no WhatsApp: the button is hidden rather than guessed at.
        $whatsapp = PhoneNumber::normalizeIndonesianMobile($company->phone);

        return Inertia::render('Isolir/Show', [
            'company' => [
                'name' => $company->name,
                'phone' => $company->phone,
                'whatsapp_url' => $whatsapp === null ? null : 'https://wa.me/'.$whatsapp,
                'email' => $company->email,
                'website' => $company->website,
                'app_url' => SettingService::playStoreUrl('customer'),
            ],
        ]);
    }
}
