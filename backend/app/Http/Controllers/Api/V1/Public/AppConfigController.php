<?php

namespace App\Http\Controllers\Api\V1\Public;

use App\Http\Controllers\Controller;
use App\Models\ChatSettings;
use App\Models\SystemSettings;
use Illuminate\Http\JsonResponse;

class AppConfigController extends Controller
{
    /**
     * Theme values the embeddable widget reads on load. Unauthenticated and
     * intentionally minimal — only what a public page may safely see.
     */
    public function show(): JsonResponse
    {
        $settings = ChatSettings::current();

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
            'fields' => [
                'name' => [
                    'enabled' => $settings->field_name_enabled,
                    'required' => $settings->field_name_required,
                    'label' => $settings->widget_name_label,
                ],
                'email' => [
                    'enabled' => $settings->field_email_enabled,
                    'required' => $settings->field_email_required,
                    'label' => $settings->widget_email_label,
                ],
                'phone' => [
                    'enabled' => $settings->field_phone_enabled,
                    'required' => $settings->field_phone_required,
                    'label' => $settings->widget_phone_label,
                ],
                'custom' => $settings->custom_fields ?? [],
            ],
        ]]);
    }
}
