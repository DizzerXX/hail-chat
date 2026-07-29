<?php

namespace App\Services;

use App\Models\Conversation;
use Illuminate\Support\Collection;

/**
 * Resolves a per-inbox CMS/CRM URL *template* into a concrete link for a
 * conversation, substituting placeholders from the visitor details and their
 * pre-chat field answers. Every substituted value is URL-encoded; the template's
 * own structural characters (?, &, =) are left untouched.
 *
 * Placeholders:
 *   {id} {uuid}                 conversation id / uuid   (e.g. case_id=livechat-{id})
 *   {name} {email} {phone}      visitor details
 *   {field:KEY}                 a custom field answer (cascade = the joined path)
 *   {field:KEY:N}               level N of a cascade field (1-based)
 */
class CmsUrlResolver
{
    public function resolve(?string $template, Conversation $conversation): ?string
    {
        if ($template === null || trim($template) === '') {
            return null;
        }

        $fields = collect($conversation->meta['fields'] ?? []);

        return preg_replace_callback(
            '/\{(\w+)(?::(\w+))?(?::(\d+))?\}/',
            function (array $m) use ($conversation, $fields): string {
                $var = $m[1];
                $fieldKey = $m[2] ?? null;
                $level = isset($m[3]) && $m[3] !== '' ? (int) $m[3] : null;

                $value = match ($var) {
                    'id' => (string) $conversation->id,
                    'uuid' => $conversation->uuid,
                    'name' => $conversation->visitor_name,
                    'email' => $conversation->visitor_email,
                    'phone' => $conversation->visitor_phone,
                    'field' => $this->fieldValue($fields, $fieldKey, $level),
                    default => '',
                };

                return rawurlencode((string) ($value ?? ''));
            },
            $template,
        );
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $fields
     */
    private function fieldValue(Collection $fields, ?string $key, ?int $level): string
    {
        $field = $key === null ? null : $fields->firstWhere('key', $key);

        if ($field === null) {
            return '';
        }

        if ($level !== null) {
            return $field['path'][$level - 1]['label'] ?? '';
        }

        return (string) ($field['value'] ?? '');
    }
}
