<?php

namespace Modules\Billing\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Services\Core\SettingService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * Where Snap sends a customer who paid from the mobile app (`callbacks.finish` and `callbacks.error`). Public, and never
 * a source of payment state: it looks up nothing, changes nothing and does not repeat the order id, the amount or any
 * other part of the address. The one thing it reads is `transaction_status`, and only to choose between four sentences;
 * a value outside the list below, or none, gets the neutral one. The state that counts is the invoice in the app.
 */
class PaymentFinishController extends Controller
{
    /** What Snap's `transaction_status` says, as one of the three outcomes the page words. */
    private const OUTCOMES = [
        'settlement' => 'success',
        'capture' => 'success',
        'pending' => 'pending',
        'authorize' => 'pending',
        'deny' => 'failed',
        'cancel' => 'failed',
        'expire' => 'failed',
        'failure' => 'failed',
    ];

    public function __invoke(Request $request): InertiaResponse
    {
        $status = $request->query('transaction_status');

        return Inertia::render('Portal/Payments/Finished', [
            'outcome' => is_string($status) ? (self::OUTCOMES[$status] ?? 'unknown') : 'unknown',
            'appUrl' => SettingService::playStoreUrl('customer'),
        ]);
    }
}
