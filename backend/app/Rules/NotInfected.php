<?php

namespace App\Rules;

use App\Contracts\VirusScanner;
use App\Exceptions\VirusScanException;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;

/**
 * Rejects an uploaded file that the antivirus scanner flags as infected. A no-op
 * when scanning is disabled. When the scanner itself errors, `attachments.av.on_error`
 * decides between failing closed (block) or open (allow + log).
 */
class NotInfected implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! config('attachments.av.enabled') || ! $value instanceof UploadedFile) {
            return;
        }

        try {
            $signature = app(VirusScanner::class)->scan($value->getRealPath());
        } catch (VirusScanException $exception) {
            Log::warning('Attachment virus scan failed.', ['error' => $exception->getMessage()]);

            if (config('attachments.av.on_error') !== 'allow') {
                $fail('This file could not be scanned for malware. Please try again.');
            }

            return;
        }

        if ($signature !== null) {
            Log::warning('Attachment rejected by the virus scanner.', ['signature' => $signature]);
            $fail('This file was rejected by the malware scanner.');
        }
    }
}
