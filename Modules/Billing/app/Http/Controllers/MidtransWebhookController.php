<?php

namespace Modules\Billing\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Modules\Billing\Exceptions\MidtransGatewayException;
use Modules\Billing\Models\PaymentAttempt;
use Modules\Billing\Services\MidtransGateway;
use Modules\Billing\Services\PaymentAttemptService;
use Symfony\Component\HttpFoundation\Response;

/**
 * Midtrans HTTP notification. No session and no CSRF: the signature is the credential. The
 * notification only says which attempt to look at; the state that gets applied is re-fetched from the
 * Status API, so a replayed, reordered or tampered notification cannot book anything by itself.
 */
class MidtransWebhookController extends Controller
{
    /** Fields every notification carries besides the signature. */
    private const REQUIRED = ['order_id', 'status_code', 'gross_amount', 'transaction_status'];

    public function __invoke(Request $request, MidtransGateway $gateway, PaymentAttemptService $attempts): Response
    {
        if (! $gateway->isEnabled()) {
            return response()->noContent(404);
        }

        $notification = $request->json()->all();

        // Nothing is looked up, let alone written, before the sender has proved who it is.
        if (! is_string($notification['signature_key'] ?? null)) {
            return response()->noContent(403);
        }

        foreach (self::REQUIRED as $field) {
            if (! is_string($notification[$field] ?? null)) {
                return response()->noContent(422);
            }
        }

        if (! $gateway->isValidSignature($notification)) {
            return response()->noContent(403);
        }

        // The tenant is whatever the stored attempt says, never anything in the payload.
        $attempt = PaymentAttempt::withoutCompany()->where('order_id', $notification['order_id'])->first();

        if ($attempt === null) {
            return response()->noContent(404);
        }

        try {
            $status = $gateway->transactionStatus($attempt->order_id)
                ?? throw new MidtransGatewayException('Midtrans has no transaction for a notified order.');
            $attempts->applyNotification($attempt, $status);
        } catch (MidtransGatewayException|InvalidArgumentException $e) {
            // 503 makes Midtrans retry; the reconciliation command is the backstop.
            Log::warning('midtrans.webhook_unverified', ['order_id' => $attempt->order_id, 'reason' => $e->getMessage()]);

            return response()->noContent(503);
        }

        return response('OK');
    }
}
