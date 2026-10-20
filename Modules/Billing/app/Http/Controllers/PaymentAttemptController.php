<?php

namespace Modules\Billing\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Modules\Billing\Http\Requests\RefundPaymentAttemptRequest;
use Modules\Billing\Models\PaymentAttempt;
use Modules\Billing\Services\PaymentAttemptService;

class PaymentAttemptController extends Controller
{
    /** Finance marks an online payment that could not be booked as returned to the customer (K4). */
    public function refund(RefundPaymentAttemptRequest $request, PaymentAttempt $attempt, PaymentAttemptService $attempts): RedirectResponse
    {
        $attempts->markRefunded($attempt, $request->user(), $request->validated('reference'), $request->validated('note'));

        return back()->with('success', 'Pembayaran ditandai dikembalikan.');
    }
}
