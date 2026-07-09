<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\WebhookRequest;
use App\Http\Resources\Admin\AdminWebhookResource;
use App\Http\Resources\Admin\WebhookDeliveryResource;
use App\Models\Webhook;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class WebhookController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return AdminWebhookResource::collection(
            Webhook::with('inbox:id,name,slug')
                ->withCount([
                    'deliveries',
                    'deliveries as failed_deliveries_count' => fn ($query) => $query->where('status', 'failed'),
                ])
                ->orderBy('name')
                ->get()
        );
    }

    public function store(WebhookRequest $request): JsonResponse
    {
        $webhook = Webhook::create($request->validated());

        return AdminWebhookResource::make($webhook->load('inbox:id,name,slug'))
            ->response()
            ->setStatusCode(201);
    }

    public function update(WebhookRequest $request, Webhook $webhook): AdminWebhookResource
    {
        $webhook->update($request->validated());

        return AdminWebhookResource::make($webhook->load('inbox:id,name,slug'));
    }

    public function destroy(Webhook $webhook): Response
    {
        $webhook->delete();

        return response()->noContent();
    }

    public function deliveries(Webhook $webhook): AnonymousResourceCollection
    {
        return WebhookDeliveryResource::collection(
            $webhook->deliveries()->orderByDesc('id')->paginate(25)
        );
    }
}
