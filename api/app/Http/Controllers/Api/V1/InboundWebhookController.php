<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Webhooks\WebhookIngestor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InboundWebhookController extends Controller
{
    public function shopify(Request $request, WebhookIngestor $ingestor): JsonResponse
    {
        return $ingestor->ingest('shopify', $request);
    }

    public function bigcommerce(Request $request, WebhookIngestor $ingestor): JsonResponse
    {
        return $ingestor->ingest('bigcommerce', $request);
    }
}
