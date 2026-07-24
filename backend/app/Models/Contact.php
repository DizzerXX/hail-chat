<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A person across conversations, matched by email (else phone), so agents can
 * see a returning visitor's prior chats.
 */
class Contact extends Model
{
    protected $fillable = [
        'name',
        'email',
        'phone',
    ];

    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class);
    }

    /**
     * Find the contact matching an email or phone, or make a new one. Matching
     * prefers email, then phone; a new contact is only created when at least
     * one identifier is present.
     *
     * @param  array{name?: ?string, email?: ?string, phone?: ?string}  $visitor
     */
    public static function resolveFor(array $visitor): ?self
    {
        $email = $visitor['email'] ?? null;
        $phone = $visitor['phone'] ?? null;

        if (($email === null || $email === '') && ($phone === null || $phone === '')) {
            return null;
        }

        $contact = static::query()
            ->when($email, fn ($q) => $q->orWhere('email', $email))
            ->when($phone, fn ($q) => $q->orWhere('phone', $phone))
            ->first();

        if ($contact === null) {
            return static::create([
                'name' => $visitor['name'] ?? null,
                'email' => $email ?: null,
                'phone' => $phone ?: null,
            ]);
        }

        // Backfill anything we learned this time round.
        $contact->fill(array_filter([
            'name' => $contact->name ?: ($visitor['name'] ?? null),
            'email' => $contact->email ?: ($email ?: null),
            'phone' => $contact->phone ?: ($phone ?: null),
        ]));

        if ($contact->isDirty()) {
            $contact->save();
        }

        return $contact;
    }
}
