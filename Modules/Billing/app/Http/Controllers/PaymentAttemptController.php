<?php

namespace Modules\Billing\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Modules\Billing\Http\Requests\CancelPaymentAttemptRequest;
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

    /** Staff close a payment page that is still open and have Midtrans cancel its transaction. */
    public function cancel(CancelPaymentAttemptRequest $request, PaymentAttempt $attempt, PaymentAttemptService $attempts): RedirectResponse
    {
        $attempts->cancel($attempt);

        return back()->with('success', 'Pembatalan dikirim ke Midtrans.');
    }
}
