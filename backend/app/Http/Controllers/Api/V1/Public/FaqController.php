<?php

namespace App\Http\Controllers\Api\V1\Public;

use App\Http\Controllers\Controller;
use App\Http\Resources\FaqResource;
use App\Models\Inbox;
use App\Services\Faq\PublicFaqService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class FaqController extends Controller
{
    public function index(Request $request, Inbox $inbox, PublicFaqService $faqs): AnonymousResourceCollection
    {
        abort_unless($inbox->is_active, 404);

        return FaqResource::collection($faqs->list($request->query('language')));
    }
}
