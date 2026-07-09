<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\InboxRequest;
use App\Http\Resources\Admin\AdminInboxResource;
use App\Models\Inbox;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class InboxController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return AdminInboxResource::collection(
            Inbox::withCount('conversations')->orderBy('name')->get()
        );
    }

    public function store(InboxRequest $request): JsonResponse
    {
        $inbox = Inbox::create($request->validated());

        return AdminInboxResource::make($inbox->loadCount('conversations'))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Inbox $inbox): AdminInboxResource
    {
        return AdminInboxResource::make($inbox->loadCount('conversations'));
    }

    public function update(InboxRequest $request, Inbox $inbox): AdminInboxResource
    {
        $inbox->update($request->validated());

        return AdminInboxResource::make($inbox->loadCount('conversations'));
    }

    public function destroy(Inbox $inbox): Response
    {
        abort_if(
            $inbox->conversations()->exists(),
            409,
            'This inbox has conversations and cannot be deleted; deactivate it instead.',
        );

        $inbox->delete();

        return response()->noContent();
    }
}
