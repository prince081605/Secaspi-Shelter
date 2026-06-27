<?php

namespace Database\Seeders;

use App\Models\FaqEntry;
use Illuminate\Database\Seeder;

/**
 * Seeds the assistant's knowledge base with the previously-hardcoded answers, now editable by the
 * admin. Idempotent: keyed on the question text so re-running won't duplicate rows.
 */
class FaqSeeder extends Seeder
{
    public function run(): void
    {
        $entries = [
            [
                'question' => 'How do I adopt a dog?',
                'tags' => 'adoption process steps apply application requirements rehome',
                'answer' => 'To adopt: browse our available animals, submit an adoption application, and our team reviews it and arranges a home visit before finalizing. Start on the Adoption page — or try the Matchmaker quiz to find a good fit first.',
            ],
            [
                'question' => 'How much does it cost to adopt? (adoption fee)',
                'tags' => 'fee cost price magkano payment money charge',
                'answer' => 'Adoption involves a small processing/care fee that helps cover vaccination and basic care — our team confirms the exact amount during your application. The goal is responsible rehoming, not profit.',
            ],
            [
                'question' => 'How does fostering work?',
                'tags' => 'foster temporary care placement',
                'answer' => "Fostering means temporarily caring for an animal until it's adopted. Submit a foster request from an animal's page (or the Foster option) and we'll coordinate dates and support with you.",
            ],
            [
                'question' => 'How can I donate?',
                'tags' => 'donate donation gcash bank cash support contribute give money',
                'answer' => 'Thank you for considering a donation! 💛 You can give via GCash, cash, or bank transfer from the Donate page after logging in — every bit helps feed and treat our animals. You can also see your impact and badges on your dashboard.',
            ],
            [
                'question' => 'Where do my donations go?',
                'tags' => 'transparency money used funds spending breakdown',
                'answer' => 'Donations go to food, medical treatment, vaccinations, and shelter operations. You can see totals and a breakdown on our Transparency page.',
            ],
            [
                'question' => 'How do I volunteer?',
                'tags' => 'volunteer volunteering help out join team work',
                'answer' => "We'd love your help! 🤝 Submit a volunteer application from the Volunteer page and our team will reach out about onboarding and tasks.",
            ],
            [
                'question' => 'Can I visit the shelter? What are your hours?',
                'tags' => 'visit hours open time tour schedule come see meet the dogs appointment drop by',
                'answer' => "You can book a visit to meet our animals through the Visit page after logging in — pick a date and time slot and we'll confirm it.",
            ],
            [
                'question' => 'Where are you located?',
                'tags' => 'location address where directions find place',
                'answer' => 'You can find our address in the site footer or contact us for directions — and book a visit through the Visit page.',
            ],
            [
                'question' => 'How can I contact you?',
                'tags' => 'contact phone email reach number message call',
                'answer' => 'You can reach us through the contact options on the site. Logged-in users can also message us directly from the dashboard.',
            ],
            [
                'question' => 'I found a stray or injured animal — what do I do?',
                'tags' => 'rescue stray lost found injured hurt sick wounded abandoned report distress emergency saw on the street help an animal',
                'answer' => "If you've spotted a stray or an animal in distress, please file a rescue report from our home page — add the location (you can pin it on the map) and a photo, and our rescue team will respond as fast as possible.",
            ],
            [
                'question' => 'Are the animals vaccinated and healthy?',
                'tags' => 'vaccination vaccine health medical sick deworming spay neuter care',
                'answer' => 'Our animals receive vaccinations and basic medical care, and each profile lists its health records. For specific medical questions about an animal, book a visit or contact our team.',
            ],
        ];

        foreach ($entries as $e) {
            FaqEntry::firstOrCreate(
                ['question' => $e['question']],
                ['answer' => $e['answer'], 'tags' => $e['tags'], 'enabled' => true],
            );
        }
    }
}
