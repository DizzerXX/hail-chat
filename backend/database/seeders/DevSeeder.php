<?php

namespace Database\Seeders;

use App\Enums\AgentStatus;
use App\Models\Agent;
use App\Models\Faq;
use App\Models\Inbox;
use App\Models\Role;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Local development data — run explicitly:
 *
 *   php artisan db:seed --class=DevSeeder
 */
class DevSeeder extends Seeder
{
    private const FAQS = [
        [
            'keywords_ms' => ['kata laluan', 'tetapkan semula', 'lupa'],
            'question_ms' => 'Bagaimana saya menetapkan semula kata laluan saya?',
            'answer_ms' => 'Klik "Lupa kata laluan" pada halaman log masuk dan ikut pautan yang dihantar ke e-mel anda.',
            'keywords_en' => ['password', 'reset', 'forgot'],
            'question_en' => 'How do I reset my password?',
            'answer_en' => 'Click "Forgot password" on the login page and follow the link sent to your email.',
        ],
        [
            'keywords_ms' => ['waktu operasi', 'waktu perniagaan', 'buka'],
            'question_ms' => 'Apakah waktu operasi anda?',
            'answer_ms' => 'Kami beroperasi Isnin hingga Jumaat, 9 pagi hingga 6 petang (waktu Malaysia).',
            'keywords_en' => ['hours', 'operating', 'open'],
            'question_en' => 'What are your operating hours?',
            'answer_en' => 'We operate Monday to Friday, 9am to 6pm (Malaysia time).',
        ],
        [
            'keywords_ms' => ['harga', 'pelan', 'langganan'],
            'question_ms' => 'Di manakah saya boleh melihat harga dan pelan langganan?',
            'answer_ms' => 'Halaman harga penuh tersedia di laman web kami di bawah menu "Harga".',
            'keywords_en' => ['pricing', 'plans', 'subscription'],
            'question_en' => 'Where can I see pricing and subscription plans?',
            'answer_en' => 'The full pricing page is available on our website under the "Pricing" menu.',
        ],
        [
            'keywords_ms' => ['bayaran balik', 'pemulangan', 'batal'],
            'question_ms' => 'Bagaimana saya memohon bayaran balik?',
            'answer_ms' => 'Hubungi pasukan sokongan kami dengan nombor pesanan anda dalam tempoh 14 hari selepas pembelian.',
            'keywords_en' => ['refund', 'return', 'cancel'],
            'question_en' => 'How do I request a refund?',
            'answer_en' => 'Contact our support team with your order number within 14 days of purchase.',
        ],
        [
            'keywords_ms' => ['hubungi', 'telefon', 'emel'],
            'question_ms' => 'Bagaimana saya boleh menghubungi anda?',
            'answer_ms' => 'Anda boleh menghubungi kami melalui e-mel di support@telediant.com atau menerusi sembang langsung ini.',
            'keywords_en' => ['contact', 'phone', 'email'],
            'question_en' => 'How can I contact you?',
            'answer_en' => 'You can reach us by email at support@telediant.com or through this live chat.',
        ],
        [
            'keywords_ms' => ['akaun', 'daftar', 'buat akaun'],
            'question_ms' => 'Bagaimana saya mendaftar akaun baharu?',
            'answer_ms' => 'Tekan butang "Daftar" di penjuru kanan atas laman web dan lengkapkan borang pendaftaran.',
            'keywords_en' => ['account', 'register', 'sign up'],
            'question_en' => 'How do I register a new account?',
            'answer_en' => 'Press the "Sign up" button in the top-right corner of the website and complete the registration form.',
        ],
    ];

    public function run(): void
    {
        $this->call(RolePermissionSeeder::class);

        $inbox = Inbox::firstOrCreate(
            ['slug' => 'website'],
            ['name' => 'Website', 'bot_enabled' => true],
        );

        $team = Team::firstOrCreate(['name' => 'General']);

        $agentRole = Role::where('name', 'agent')->firstOrFail();

        $user = User::firstOrCreate(
            ['email' => 'agent@tdechat.test'],
            ['name' => 'Aina Agent', 'password' => Hash::make('password'), 'role_id' => $agentRole->id],
        );

        $agent = Agent::firstOrCreate(
            ['user_id' => $user->id],
            ['display_name' => 'Aina', 'status' => AgentStatus::Available],
        );

        $agent->teams()->syncWithoutDetaching([$team->id]);

        foreach (self::FAQS as $faq) {
            $existing = Faq::where('question', $faq['question_ms'])->first();
            $group = $existing?->translation_group ?? (string) Str::uuid();

            Faq::updateOrCreate(
                ['translation_group' => $group, 'language' => 'ms'],
                ['question' => $faq['question_ms'], 'answer' => $faq['answer_ms'], 'keywords' => $faq['keywords_ms']],
            );

            Faq::updateOrCreate(
                ['translation_group' => $group, 'language' => 'en'],
                ['question' => $faq['question_en'], 'answer' => $faq['answer_en'], 'keywords' => $faq['keywords_en']],
            );
        }
    }
}
