<?php

namespace App\Http\Controllers\Api\V1\Public;

use App\Http\Controllers\Controller;
use App\Models\ChatSettings;
use App\Models\Inbox;
use App\Models\SystemSettings;
use App\Services\PreChatForm;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AppConfigController extends Controller
{
    /**
     * Theme values the embeddable widget reads on load. Unauthenticated and
     * intentionally minimal — only what a public page may safely see.
     */
    public function show(Request $request, PreChatForm $preChat): JsonResponse
    {
        $settings = ChatSettings::current();

        // The widget names its inbox so a per-inbox pre-chat form can be
        // returned; an older embed omits it and gets the global form.
        $slug = $request->query('inbox');
        $inbox = is_string($slug) ? Inbox::where('slug', $slug)->first() : null;
        $fields = $preChat->for($inbox);

        return response()->json(['data' => [
            // System branding for the login page / browser tab / dashboard.
            'brand' => SystemSettings::current()->only(SystemSettings::BRANDING),
            'bubble_color' => $settings->bubble_color,
            'widget' => $settings->only(ChatSettings::BRANDING),
            'faq' => [
                'enabled' => $settings->faq_deflection_enabled,
                'skip_bot' => $settings->faq_skip_bot,
                'title' => ['ms' => $settings->widget_faq_title_ms, 'en' => $settings->widget_faq_title_en],
                'prompt' => ['ms' => $settings->widget_faq_prompt_ms, 'en' => $settings->widget_faq_prompt_en],
                'button' => ['ms' => $settings->widget_faq_button_ms, 'en' => $settings->widget_faq_button_en],
            ],
            'queue' => [
                'wait_time_enabled' => $settings->wait_time_enabled,
                'no_agents' => ['ms' => $settings->no_agents_ms, 'en' => $settings->no_agents_en],
            ],
            'fields' => [
                'name' => [
                    'enabled' => $fields['field_name_enabled'],
                    'required' => $fields['field_name_required'],
                    'label' => $fields['widget_name_label'],
                ],
                'email' => [
                    'enabled' => $fields['field_email_enabled'],
                    'required' => $fields['field_email_required'],
                    'label' => $fields['widget_email_label'],
                ],
                'phone' => [
                    'enabled' => $fields['field_phone_enabled'],
                    'required' => $fields['field_phone_required'],
                    'label' => $fields['widget_phone_label'],
                ],
                'custom' => $fields['custom_fields'] ?? [],
            ],
        ]]);
    }
}
