<?php

namespace Modules\Billing\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\Core\CompanyService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Modules\Billing\Exceptions\MidtransGatewayException;
use Modules\Billing\Models\PaymentAttempt;
use Modules\Billing\Services\MidtransGateway;
use Modules\Billing\Services\PaymentAttemptService;
use Symfony\Component\HttpFoundation\Response;

/**
 * Midtrans HTTP notification. No session and no CSRF: the signature is the credential, and each company has its own
 * (its server key), so the company comes first: from the URL, or on the legacy URL from the order. The notification
 * only says which attempt to look at; the state that gets applied is re-fetched from the Status API, so a replayed,
 * reordered or tampered notification cannot book anything by itself.
 *
 * An order this install never made (a merchant shared with another project) is acknowledged and logged, never answered
 * with an error: Midtrans retries every non-2xx answer, so a 404 would be repeated for ever.
 */
class MidtransWebhookController extends Controller
{
    /** Fields every notification carries besides the signature. */
    private const REQUIRED = ['order_id', 'status_code', 'gross_amount', 'transaction_status'];

    public function __invoke(Request $request, MidtransGateway $gateway, PaymentAttemptService $attempts): Response
    {
        $notification = $request->json()->all();

        // Nothing is written, and Midtrans is not called, before the sender has proved who it is.
        if (! is_string($notification['signature_key'] ?? null)) {
            return response()->noContent(403);
        }

        foreach (self::REQUIRED as $field) {
            if (! is_string($notification[$field] ?? null)) {
                return response()->noContent(422);
            }
        }

        $companyId = MidtransGateway::companyIdForNotification($request);

        if ($companyId === null) {
            // A company URL that names no company is not ours. On the legacy URL the company comes from the order, so none
            // means an order no company made; without a key there is nothing to check the signature against.
            return $request->route()?->hasParameter('company') ? response()->noContent(404) : $this->foreignOrder($notification['order_id'], verified: false);
        }

        return CompanyService::runAs($companyId, fn (): Response => $this->receive($notification, $gateway, $attempts));
    }

    /**
     * Inside the company's scope: its gateway, its key, its attempts.
     *
     * @param  array<string, mixed>  $notification
     */
    private function receive(array $notification, MidtransGateway $gateway, PaymentAttemptService $attempts): Response
    {
        if (! $gateway->isEnabled()) {
            return response()->noContent(404);
        }

        if (! $gateway->isValidSignature($notification)) {
            return response()->noContent(403);
        }

        // The company is the one whose key signed, so the lookup is scoped to it: another company's order is simply not here.
        $attempt = PaymentAttempt::query()->where('order_id', $notification['order_id'])->first();

        if ($attempt === null) {
            return $this->foreignOrder($notification['order_id'], verified: true);
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

    /** The order id only, cut short: it comes from the sender and nothing else of the notification is kept. */
    private function foreignOrder(string $orderId, bool $verified): Response
    {
        Log::info('midtrans.foreign_order', ['order_id' => Str::limit($orderId, 64, ''), 'verified' => $verified]);

        return response('OK');
    }
}
