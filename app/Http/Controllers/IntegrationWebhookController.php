<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\IntegrationConnection;
use App\Support\IntegrationEvents;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use JsonException;

class IntegrationWebhookController extends Controller
{
    public function __invoke(Request $request, string $publicId): JsonResponse
    {
        $body = $request->getContent();
        if (strlen($body) > 65536) {
            return response()->json(['status' => 'payload_too_large'], 413);
        }
        if (! $request->isJson()) {
            return response()->json(['status' => 'json_required'], 415);
        }
        $timestamp = $request->header('X-Endu-Timestamp', '');
        $signature = $request->header('X-Endu-Signature', '');
        if (! preg_match('/\A[0-9]{10}\z/', $timestamp) || abs(now()->timestamp - (int) $timestamp) > 300
            || ! preg_match('/\Asha256=[a-f0-9]{64}\z/', $signature)) {
            return response()->json(['status' => 'unauthorized'], 401);
        }

        $connector = IntegrationConnection::where('public_id', $publicId)->where('enabled', true)->first();
        if ($connector === null || ! $this->validSignature($connector, $timestamp, $signature, $body)) {
            return response()->json(['status' => 'unauthorized'], 401);
        }
        try {
            $envelope = json_decode($body, true, 24, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return response()->json(['status' => 'invalid_json'], 422);
        }
        $validator = Validator::make(is_array($envelope) ? $envelope : [], [
            'event_id' => ['required', 'string', 'max:80', IntegrationEvents::REFERENCE],
            'account' => ['required', 'string', 'max:80', IntegrationEvents::REFERENCE],
            'topic' => ['required', 'string', 'max:32'],
            'data' => ['required', 'array'],
        ]);
        if ($validator->fails()) {
            return response()->json(['status' => 'invalid_envelope'], 422);
        }
        $input = $validator->validated();

        return DB::transaction(function () use ($connector, $input, $timestamp, $signature, $body): JsonResponse {
            $branch = Branch::whereKey($connector->branch_id)->lockForUpdate()->firstOrFail();
            $connection = $branch->integrationConnections()->lockForUpdate()->findOrFail($connector->id);
            if (! $connection->enabled || $branch->organization->suspended_at !== null
                || ! hash_equals($connection->account_ref, $input['account']) || ! $this->validSignature($connection, $timestamp, $signature, $body)) {
                return response()->json(['status' => 'unauthorized'], 401);
            }
            $digest = hash('sha256', $body);
            $eventId = strtoupper($input['event_id']);
            $previous = $connection->events()->where('external_id', $eventId)->first();
            if ($previous) {
                if (! hash_equals($previous->digest, $digest)) {
                    return response()->json(['status' => 'event_id_conflict'], 409);
                }

                return response()->json(['status' => 'duplicate', 'original' => $previous->status], $previous->status === 'rejected' ? 422 : 200);
            }

            $topic = IntegrationEvents::TOPICS[$connection->kind] ?? null;
            if ($input['topic'] !== $topic) {
                $result = 'unexpected_topic';
            } else {
                try {
                    $result = IntegrationEvents::process($connection, $input['data']);
                } catch (ValidationException) {
                    $result = 'invalid_payload';
                }
            }
            $status = match ($result) {
                'applied' => 'applied',
                'stale_revision', 'stale_status', 'confirmed_order' => 'ignored',
                default => 'rejected',
            };
            $connection->events()->create(['external_id' => $eventId, 'topic' => $topic ?? 'unknown',
                'digest' => $digest, 'status' => $status, 'reason' => $result]);
            $connection->update(['last_received_at' => now()]);

            return response()->json(['status' => $status, 'result' => $result], $status === 'rejected' ? 422 : 200);
        }, 3);
    }

    private function validSignature(IntegrationConnection $connection, string $timestamp, string $provided, string $body): bool
    {
        $secret = $connection->secret;

        return $secret !== null && hash_equals('sha256='.hash_hmac('sha256', $timestamp.'.'.$connection->public_id.'.'.$body, $secret), $provided);
    }
}
