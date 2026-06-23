<?php

namespace Database\Seeders;

use App\Models\CareGuide;
use Illuminate\Database\Seeder;

class CareGuideSeeder extends Seeder
{
    public function run(): void
    {
        $guides = [
            // ===== DOGS: BREED + AGE GUIDES =====
            ['title' => 'Aspin Puppy Diet', 'species' => 'dog', 'breed_keywords' => ['aspin', 'mixed', 'stray'], 'age_range' => 'puppy', 'category' => 'diet', 'content' => 'Aspin puppies need high-protein puppy food (24-28% protein) to support growth. Feed 3-4 times daily until 6 months, then transition to 2 meals. Provide fresh water constantly. Watch for rapid growth and adjust portions to prevent bloat.'],
            ['title' => 'Aspin Adult Maintenance', 'species' => 'dog', 'breed_keywords' => ['aspin', 'mixed', 'stray'], 'age_range' => 'adult', 'category' => 'diet', 'content' => 'Adult Aspins do well on quality adult dog food (18-25% protein). Feed once or twice daily depending on activity level. Average adult Aspin needs 1.5-2.5 cups daily. Monitor weight and adjust as needed.'],
            ['title' => 'Senior Aspin Care', 'species' => 'dog', 'breed_keywords' => ['aspin', 'mixed', 'stray'], 'age_range' => 'senior', 'category' => 'health', 'content' => 'Senior Aspins (7+ years) need softer food, joint supplements (glucosamine), and more frequent vet checkups. Provide orthopedic bedding for joint comfort. Watch for mobility issues and adjust exercise accordingly.'],

            // EXERCISE & ENERGY
            ['title' => 'Aspin Puppy Play & Exercise', 'species' => 'dog', 'breed_keywords' => ['aspin', 'mixed', 'stray'], 'age_range' => 'puppy', 'category' => 'exercise', 'content' => 'Aspin puppies are curious and energetic. Provide 15-20 minutes of supervised play several times daily. Use puzzle toys and interactive games. Avoid strenuous jumps or long runs until 12-18 months to protect developing joints.'],
            ['title' => 'Adult Aspin Exercise Needs', 'species' => 'dog', 'breed_keywords' => ['aspin', 'mixed', 'stray'], 'age_range' => 'adult', 'category' => 'exercise', 'content' => 'Adult Aspins typically need 45-60 minutes of exercise daily. Mix walks, fetch, and mental stimulation. They are intelligent and thrive with varied activities. Multiple shorter play sessions often work better than one long outing.'],

            // BEHAVIORAL GUIDES
            ['title' => 'Managing Separation Anxiety in Dogs', 'species' => 'dog', 'behavioral_keywords' => ['separation anxiety'], 'category' => 'behavioral', 'content' => 'Start with short absences (5-10 minutes) and gradually increase. Create a safe space (crate or room) with toys and comfortable bedding. Leave calming music or white noise. Practice departure routines without making a big fuss. Consider puzzle toys or long-lasting chews to occupy their mind.'],
            ['title' => 'Food Aggression Management', 'species' => 'dog', 'behavioral_keywords' => ['aggression & resource guarding', 'food aggression'], 'category' => 'behavioral', 'content' => 'Never hand-feed or take food away suddenly. Feed in a separate, quiet space away from other pets. Start with "drop it" training using high-value treats as exchange. Consult a trainer if severe. Avoid hand-reaching into the bowl during eating.'],
            ['title' => 'Handling Excessive Barking', 'species' => 'dog', 'behavioral_keywords' => ['excessive barking'], 'category' => 'behavioral', 'content' => 'Identify triggers (boredom, anxiety, alerting). Increase physical and mental exercise. Teach "quiet" command with positive reinforcement. Avoid yelling (dogs may think you\'re joining in). White noise can help mask external sounds. Consistency is key.'],
            ['title' => 'Leash Training & Pulling', 'species' => 'dog', 'behavioral_keywords' => ['pulling on leash'], 'category' => 'training', 'content' => 'Use a harness (gentler than collar). Stop walking when they pull; resume when leash goes slack. Reward walking beside you with treats. Practice short sessions. Be patient—this can take weeks. Consider a front-clip harness for better control.'],

            // DOG-TO-DOG & TERRITORIAL
            ['title' => 'Dog-to-Dog Aggression: Safe Introductions', 'species' => 'dog', 'behavioral_keywords' => ['dog-to-dog aggression'], 'category' => 'behavioral', 'content' => 'Introduce on neutral ground. Use separate handlers on loose leashes. Keep initial meetings short (5-10 min). Watch for stiff body, raised hackles, or staring—signs of tension. Separate if needed and try again later. Never force interaction. Multiple calm meetings build positive association.'],
            ['title' => 'Territorial Aggression at Home', 'species' => 'dog', 'behavioral_keywords' => ['territorial aggression'], 'category' => 'behavioral', 'content' => 'Create separate spaces for dogs initially. Use baby gates to manage sight lines. Feed separately. Reward calm behavior near the boundary. Gradually decrease barriers as comfort grows. Do not punish—redirect to positive behavior. Consult a trainer for severe cases.'],

            // FEAR & ANXIETY
            ['title' => 'Fear of Loud Noises: Desensitization', 'species' => 'dog', 'behavioral_keywords' => ['fear of loud noises'], 'category' => 'behavioral', 'content' => 'Create a safe haven (quiet room, crate with blanket). Play calming music or white noise. Start with recordings at very low volume; gradually increase. Reward calm behavior with treats and praise. Never force exposure. Some dogs benefit from anxiety wraps or supplements.'],
            ['title' => 'Building Trust After Trauma', 'species' => 'dog', 'behavioral_keywords' => ['post-trauma/trust issues'], 'category' => 'behavioral', 'content' => 'Go slowly. Avoid sudden movements or loud noises. Let them approach you; don\'t chase. Sit at their level. Offer high-value treats. Respect their boundaries. Exercise patience—trust rebuilds over weeks/months. Pair your presence with good things (food, play).'],
            ['title' => 'Extreme Shyness: Gradual Socialization', 'species' => 'dog', 'behavioral_keywords' => ['extreme shyness'], 'category' => 'behavioral', 'content' => 'Don\'t force interaction. Allow them to observe from safe distance. Reward small steps (approaching, sniffing). Practice in quiet environments first. Use high-value treats to create positive associations. Patience and consistency are essential. Progress may be slow but steady.'],

            // DESTRUCTIVE & ELIMINATION
            ['title' => 'Destructive Chewing: Redirect & Manage', 'species' => 'dog', 'behavioral_keywords' => ['destructive chewing & digging'], 'category' => 'behavioral', 'content' => 'Provide appropriate chew toys (Kong, Nylabone). Supervise or crate when unsupervised. Redirect to approved toys if caught chewing something else. Increase exercise to burn energy. Some dogs chew from boredom—puzzle toys help. Rotate toys to maintain interest.'],
            ['title' => 'Housebreaking: Routine & Patience', 'species' => 'dog', 'behavioral_keywords' => ['inappropriate elimination'], 'category' => 'training', 'content' => 'Establish a consistent outdoor schedule (after meals, play, sleep). Use a designated potty spot. Reward success immediately with praise and treats. Supervise indoors or crate when unwatched. Accidents are normal—never punish. Clean soiled areas thoroughly to remove scent cues.'],

            // EXCESSIVE ENERGY
            ['title' => 'High-Energy Dogs: Managing Excess Vigor', 'species' => 'dog', 'behavioral_keywords' => ['excessive energy'], 'category' => 'exercise', 'content' => 'Provide 60+ minutes of daily exercise (walks, fetch, running). Add mental stimulation (training, puzzle toys, scent games). Consider structured activities (agility, dock diving). Tired dogs are well-behaved dogs. Vary activities to prevent boredom. Some breeds benefit from dog sports.'],

            // ===== CATS: BREED + AGE GUIDES =====
            ['title' => 'Kitten Nutrition & Growth', 'species' => 'cat', 'age_range' => 'puppy', 'category' => 'diet', 'content' => 'Kittens need high-protein kitten food (30%+ protein) to support growth. Feed 3-4 times daily until 6 months, then transition to 2 meals. Provide fresh water always. Kittens grow rapidly and need extra calories. Switch to adult food at 12 months.'],
            ['title' => 'Adult Cat Feeding Guidelines', 'species' => 'cat', 'age_range' => 'adult', 'category' => 'diet', 'content' => 'Adult cats thrive on quality cat food (25-30% protein). Feed once or twice daily. Average indoor cat needs 150-200 calories daily. Monitor weight and adjust portions as needed. Provide fresh water constantly. Consider elevated water bowls to encourage hydration.'],
            ['title' => 'Senior Cat Health & Nutrition', 'species' => 'cat', 'age_range' => 'senior', 'category' => 'health', 'content' => 'Senior cats (7+ years) may need softer food if dental issues arise. More frequent vet checkups are important. Provide comfortable bedding and warm resting spots. Monitor for kidney disease, arthritis, and thyroid issues. Adjust food for lower activity level.'],

            // CAT EXERCISE & ENRICHMENT
            ['title' => 'Kitten Play & Enrichment', 'species' => 'cat', 'age_range' => 'puppy', 'category' => 'exercise', 'content' => 'Kittens are bundles of energy. Provide varied toys (balls, feathers, strings). Engage in interactive play 2-3 times daily for 10-15 minutes. Cat trees for climbing are essential. Supervise string/small toy play to prevent ingestion. Rotate toys to maintain interest.'],
            ['title' => 'Indoor Cat Enrichment & Activity', 'species' => 'cat', 'age_range' => 'adult', 'category' => 'exercise', 'content' => 'Indoor cats need mental and physical stimulation. Provide cat trees, window perches, and puzzle feeders. Interactive play sessions twice daily. Rotate toys weekly. Some cats enjoy training for tricks. Window time for bird watching is natural enrichment. Laser pointers should end with a catchable toy.'],

            // CAT BEHAVIORAL GUIDES
            ['title' => 'Cat Separation Anxiety Solutions', 'species' => 'cat', 'behavioral_keywords' => ['separation anxiety'], 'category' => 'behavioral', 'content' => 'Create a secure space with resources (litter, food, water, toys). Leave calming pheromone diffusers (Feliway). Some cats benefit from background music. Start with short absences and build up. Maintain a consistent routine. Over-greeting or goodbye rituals can worsen anxiety—avoid them.'],
            ['title' => 'Cat Aggression & Territorial Issues', 'species' => 'cat', 'behavioral_keywords' => ['aggression & resource guarding', 'territorial aggression'], 'category' => 'behavioral', 'content' => 'Multi-cat households: provide separate resources (litter, food, water, toys, perches). Use vertical space (cat trees) to reduce conflicts. Feed separately if needed. Reward calm interactions. Use Feliway to reduce tension. Never force interaction. Consult a vet behaviorist if severe.'],
            ['title' => 'Cat Destructive Behavior Management', 'species' => 'cat', 'behavioral_keywords' => ['destructive chewing & digging'], 'category' => 'behavioral', 'content' => 'Provide scratching posts (vertical and horizontal). Place near areas where cat scratches. Reward use with treats/praise. Use soft nail caps if needed. Deter unwanted surfaces with double-sided tape or citrus spray. Regular nail trims reduce damage. Understand—scratching is normal, redirect it.'],
            ['title' => 'Litter Box Issues: Causes & Solutions', 'species' => 'cat', 'behavioral_keywords' => ['inappropriate elimination'], 'category' => 'behavioral', 'content' => 'Rule out medical issues first (UTI, kidney disease). Provide more litter boxes than cats (e.g., 2 cats = 3 boxes). Place in quiet, accessible locations. Scoop daily, full clean weekly. Some cats prefer unscented litter. If avoiding box, try different litter, box style, or location. Clean accidents with enzymatic cleaner.'],
            ['title' => 'Fear of Strangers: Socialization Tips', 'species' => 'cat', 'behavioral_keywords' => ['fear of strangers'], 'category' => 'behavioral', 'content' => 'Allow cat to approach at their own pace. Provide high perches for escape routes. Use treats to create positive associations. Ask guests to let cat sniff their hand first. Short, calm visits build tolerance. Avoid forcing interaction. Some cats never enjoy crowds—that\'s okay. Respect their temperament.'],
            ['title' => 'Managing Excessive Vocalization', 'species' => 'cat', 'behavioral_keywords' => ['excessive vocalization'], 'category' => 'behavioral', 'content' => 'Identify cause: hunger, attention, heat cycle, or medical issue. Rule out health problems first. Provide more environmental enrichment. Increase interactive play. Don\'t reward meowing with attention (can reinforce it). Maintain consistent routines. Some breeds are naturally vocal—that\'s normal.'],

            // CAT GROOMING & HEALTH
            ['title' => 'Cat Grooming Essentials', 'species' => 'cat', 'category' => 'grooming', 'content' => 'Long-haired cats need daily brushing to prevent mats and reduce shedding. Short-haired cats benefit from weekly brushing. Nail trims every 2-3 weeks. Ear cleaning monthly if prone to issues. Dental care is important—brush teeth 2-3 times weekly. Make grooming a positive routine with treats.'],
            ['title' => 'Indoor Cat Health Maintenance', 'species' => 'cat', 'category' => 'health', 'content' => 'Annual vet checkups are essential. Keep up with vaccinations. Flea and tick prevention year-round. Provide a balanced diet appropriate to age and health status. Maintain a healthy weight—obesity leads to health problems. Watch for signs of illness: lethargy, loss of appetite, excessive thirst.'],
        ];

        // Idempotent: this seeder runs on every production deploy (docker-entrypoint.sh
        // calls `db:seed --force`). Keying on title + species refreshes content without
        // creating duplicate rows each time.
        foreach ($guides as $guide) {
            CareGuide::updateOrCreate(
                ['title' => $guide['title'], 'species' => $guide['species']],
                $guide
            );
        }
    }
}
