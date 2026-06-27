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

            // ---- How the system works (account, features, workflows) -----------------------
            [
                'question' => 'How do I create an account or sign up?',
                'tags' => 'register signup sign up create account join new user get started',
                'answer' => 'Click Register, then enter your name, email, and a password. Once registered you can log in to adopt, donate, book visits, message us, and track everything from your dashboard.',
            ],
            [
                'question' => 'How do I log in or reset my password?',
                'tags' => 'login log in sign in password forgot reset account access cannot',
                'answer' => "Use the Login page with your email and password. Forgot it? Click 'Forgot password' on the login page and we'll email you a reset link.",
            ],
            [
                'question' => 'What is SECASPI Shelter and what do you do?',
                'tags' => 'about who what mission shelter secaspi rescue rehome story do',
                'answer' => 'We rescue, rehabilitate, and rehome dogs and other animals. Through this site you can adopt, foster, donate, volunteer, book a visit, or report a stray. See the About section on our home page for our full story.',
            ],
            [
                'question' => 'What is an Aspin?',
                'tags' => 'aspin askal native philippine dog asong pinoy breed',
                'answer' => "An Aspin (asong Pinoy), also called askal, is a native Philippine dog. They're typically healthy, smart, and loyal companions — many of our rescues are Aspins looking for a forever home.",
            ],
            [
                'question' => 'How do I track my adoption application?',
                'tags' => 'track status application progress pending approved adoption dashboard check',
                'answer' => "Log in and open your dashboard — your applications are listed with their current status (pending, approved, declined, or completed). You'll also get a notification when the status changes.",
            ],
            [
                'question' => 'What happens after I submit an adoption application?',
                'tags' => 'after apply review process home visit next steps approval adoption then',
                'answer' => "Our team reviews your application, may schedule a home visit to make sure it's a good fit, then approves or declines it. You're notified at each step, and the animal is reserved for you once approved.",
            ],
            [
                'question' => 'Can I apply to adopt more than one animal?',
                'tags' => 'multiple applications more than one limit same animal reapply again',
                'answer' => 'Yes — you can apply for different animals anytime. For the same animal, you can re-apply 30 days after a previous request.',
            ],
            [
                'question' => 'What is a home visit?',
                'tags' => 'home visit inspection check house safe suitable adoption',
                'answer' => "A home visit is a quick check by our team to make sure your home is safe and suitable for the animal before an adoption is finalized — a normal part of responsible rehoming.",
            ],
            [
                'question' => 'How do I find a pet that fits my lifestyle?',
                'tags' => 'matchmaker quiz match find suit recommend fit lifestyle which pet best',
                'answer' => "Try our Matchmaker! Answer a few quick questions (your home, activity level, experience, household, and preferences) and we'll rank our available animals by how well they fit — and explain why. Use the 'Find your match' button on the Adoption page.",
            ],
            [
                'question' => 'What payment methods can I use to donate?',
                'tags' => 'payment method gcash cash bank transfer donate how to pay',
                'answer' => 'You can donate via GCash, cash, or bank transfer from the Donate page after logging in. For GCash, please upload a screenshot of your payment as proof.',
            ],
            [
                'question' => 'Can I donate anonymously?',
                'tags' => 'anonymous private hidden name donate public leaderboard',
                'answer' => "Absolutely. When donating you can choose to stay anonymous, and your name won't appear on public pages or the leaderboard.",
            ],
            [
                'question' => 'How do I see my donation history or receipt?',
                'tags' => 'history receipt past donations record dashboard my donations',
                'answer' => 'Log in and check your dashboard — your past donations and their status are listed there, and you can view a receipt for each one.',
            ],
            [
                'question' => 'How long until my donation is verified?',
                'tags' => 'verify pending status donation approved how long time wait',
                'answer' => "Donations start as 'pending' and are verified by our team, usually quickly. You'll get a notification once it's verified, and it then counts toward your impact.",
            ],
            [
                'question' => 'What are badges and how do I earn them?',
                'tags' => 'badges impact gamification earn rewards achievements points',
                'answer' => 'Badges celebrate your support! You earn them by donating and volunteering — e.g. First Gift, Generous Heart (₱1,000 total), Shelter Champion (₱5,000), Loyal Supporter (3 donations), Helping Hand (10 volunteer hours), and Dedicated Volunteer (50 hours). See them on your dashboard\'s My Impact page.',
            ],
            [
                'question' => 'What is the leaderboard?',
                'tags' => 'leaderboard top donors volunteers ranking community impact',
                'answer' => "The leaderboard recognizes our top donors and volunteers (names show as 'First L.' for privacy, and anonymous donors aren't listed). Find it on the My Impact page.",
            ],
            [
                'question' => "What does 'meals funded' mean?",
                'tags' => 'meals funded impact donation estimate how many',
                'answer' => 'It\'s a friendly way to show your impact — we estimate how many animal meals your donations have covered, based on an average cost per meal.',
            ],
            [
                'question' => 'How do I message the shelter or staff?',
                'tags' => 'message contact staff chat conversation dashboard talk reach inbox',
                'answer' => "Log in and open Messages in your dashboard — start a conversation and our staff will reply. You'll get a notification (and email) when they respond.",
            ],
            [
                'question' => 'What is the notification bell?',
                'tags' => 'notification bell alerts updates unread dashboard',
                'answer' => 'The bell at the top of your dashboard shows your updates — adoption/foster status changes, donation verifications, replies to your messages, and more. A number badge means you have unread notifications.',
            ],
            [
                'question' => 'What is the QR code on each animal?',
                'tags' => 'qr code scan share profile animal sticker',
                'answer' => "Every animal has its own QR code. Scanning it opens that animal's public profile — great for sharing a specific dog or for events. You'll find it on the animal's detail page.",
            ],
            [
                'question' => 'What do volunteers do?',
                'tags' => 'volunteer tasks duties hours what do help activities',
                'answer' => 'Volunteers help with shelter tasks like caring for animals and events. Apply on the Volunteer page; once approved, our team assigns tasks and tracks your hours (which also earn badges!).',
            ],
            [
                'question' => "Can I see an animal's medical records or vaccinations?",
                'tags' => 'medical records health vaccination history profile vaccinated shots',
                'answer' => "Yes — each animal's profile shows its health information, including vaccinations and medical records, so you know its care history.",
            ],
            [
                'question' => "How do I know an animal's temperament or behavior?",
                'tags' => 'behavior temperament assessment personality energy good with kids sociable',
                'answer' => "Each animal's profile includes a behavioral assessment describing its temperament (e.g. energy level, sociability). The Matchmaker also uses this to suggest animals that fit your home.",
            ],
            [
                'question' => 'What can this assistant help me with?',
                'tags' => 'assistant help what can you do chatbot ai questions capabilities',
                'answer' => 'I can answer questions about adopting, fostering, donating, volunteering, visiting, reporting strays, our available animals, and how this site works — just ask in your own words!',
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
