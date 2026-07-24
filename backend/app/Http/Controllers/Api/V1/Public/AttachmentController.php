<?php

namespace App\Http\Controllers\Api\V1\Public;

use App\Http\Controllers\Controller;
use App\Models\Attachment;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AttachmentController extends Controller
{
    /**
     * Stream an attachment inline. Bound by uuid — possessing the uuid is the
     * download capability, matching the conversation-uuid trust model, so this
     * route stays public (both the widget and the agent SPA use it directly in
     * <img src>).
     */
    public function show(Attachment $attachment): StreamedResponse
    {
        $disk = Storage::disk($attachment->disk);

        abort_unless($disk->exists($attachment->path), 404);

        return $disk->response($attachment->path, $attachment->name, [
            'Content-Type' => $attachment->mime_type,
        ]);
    }
}
