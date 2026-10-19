<?php

namespace App\Http\Controllers;

use App\Services\WhatsApp\WhatsAppCloudClient;
use App\Services\WhatsApp\WhatsAppWebhookHandler;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Meta's WhatsApp webhook, one URL for every company: the GET handshake when the callback URL is saved in the app dashboard,
 * and the signed POST with delivery statuses and customer replies. No session and no CSRF: the verify token and the body
 * signature are the only credentials, and a signature only counts for the companies whose own app secret made it.
 */
class WhatsAppWebhookController extends Controller
{
    public function verify(Request $request, WhatsAppCloudClient $client): Response
    {
        // PHP turns the dots of Meta's hub.* query parameters into underscores.
        $token = $request->query('hub_verify_token');
        $challenge = $request->query('hub_challenge');

        if ($request->query('hub_mode') !== 'subscribe' || ! is_string($token) || ! is_string($challenge) || ! $client->isValidVerifyToken($token)) {
            return response()->noContent(403);
        }

        return response($challenge, 200, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }

    public function receive(Request $request, WhatsAppCloudClient $client, WhatsAppWebhookHandler $handler): Response
    {
        $body = $request->getContent();

        // Nothing is read, let alone written, before Meta has proved who it is: the companies whose app secret signs the
        // body are the only ones the payload may touch.
        $companies = $client->verifiedCompanies($body, $request->header('X-Hub-Signature-256'));

        if ($companies === []) {
            return response()->noContent(403);
        }

        $payload = json_decode($body, true);

        if (is_array($payload)) {
            $handler->handle($payload, $companies);
        }

        // Anything but 200 makes Meta resend for days; content we ignore is still acknowledged.
        return response('OK');
    }
}
