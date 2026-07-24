<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\CannedResponseRequest;
use App\Http\Resources\CannedResponseResource;
use App\Models\CannedResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class CannedResponseController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return CannedResponseResource::collection(
            CannedResponse::query()->orderBy('title')->get()
        );
    }

    public function store(CannedResponseRequest $request): CannedResponseResource
    {
        return CannedResponseResource::make(CannedResponse::create($request->validated()));
    }

    public function update(CannedResponseRequest $request, CannedResponse $cannedResponse): CannedResponseResource
    {
        $cannedResponse->update($request->validated());

        return CannedResponseResource::make($cannedResponse);
    }

    public function destroy(CannedResponse $cannedResponse): Response
    {
        $cannedResponse->delete();

        return response()->noContent();
    }
}
