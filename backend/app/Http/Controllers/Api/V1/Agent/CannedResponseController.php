<?php

namespace App\Http\Controllers\Api\V1\Agent;

use App\Http\Controllers\Controller;
use App\Http\Resources\CannedResponseResource;
use App\Models\CannedResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class CannedResponseController extends Controller
{
    /** Active canned responses an agent can insert, filtered to a language when given. */
    public function index(Request $request): AnonymousResourceCollection
    {
        $language = $request->query('language');

        return CannedResponseResource::collection(
            CannedResponse::query()
                ->usableFor(is_string($language) ? $language : null)
                ->orderBy('title')
                ->get()
        );
    }
}
