<?php

namespace Database\Seeders;

use App\Models\Setting;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * LOCAL-ONLY rich mock dataset. NOT called by DatabaseSeeder (which the Docker deploy runs on
 * every push), so this never reaches production. Run manually:
 *   php artisan db:seed --class=Database\Seeders\MockDataSeeder
 */
class MockDataSeeder extends Seeder
{
    private array $usernames = [];

    public function run(): void
    {
        // Make a fresh clone self-contained: copy the git-tracked dog photos onto the public disk
        // so seeded animals show real images even when storage/ wasn't transferred.
        $this->copyDogPhotos();

        DB::transaction(function () {
            $admin = $this->seedAdmin();
            $users = $this->seedUsers();            // ['adopters'=>[], 'volunteers'=>[], 'staff'=>[]]
            $animalIds = $this->seedDogs();         // [animalId, ...]
            $this->seedDonations($users['all']);
            $this->seedAdoptionApplications($users['adopters'], $animalIds);
            $this->seedFosterApplications($users['adopters'], $animalIds);
            $this->seedRescueReports();
            $this->seedPersonnel(array_merge($users['volunteers'], $users['staff']));
            $this->seedVolunteerApplications($users['adopters']);
            $this->seedVisitations($users['adopters']);
            $this->seedIntakes($animalIds);
            $this->seedSettings();
        });

        // Care guides (idempotent) so the care-guide matcher has content to match against.
        $this->call(CareGuideSeeder::class);

        $this->command->info('Mock data seeded: '
            . DB::table('animals')->count() . ' animals, '
            . DB::table('users')->count() . ' users, '
            . DB::table('donations')->count() . ' donations.');
    }

    // ---------------------------------------------------------------- helpers

    /**
     * Copy the git-tracked dog photos (database/seeders/dog-photos/dog1-9.jpg) onto the public
     * disk (storage/app/public/animals/) when missing. Lets a fresh clone re-seed with working
     * images without transferring the gitignored storage/ folder. Idempotent.
     */
    private function copyDogPhotos(): void
    {
        $src = database_path('seeders/dog-photos');
        $dest = storage_path('app/public/animals');
        File::ensureDirectoryExists($dest);
        for ($i = 1; $i <= 9; $i++) {
            $file = "dog{$i}.jpg";
            if (File::exists("{$src}/{$file}") && ! File::exists("{$dest}/{$file}")) {
                File::copy("{$src}/{$file}", "{$dest}/{$file}");
            }
        }
    }

    private function pick(array $a)
    {
        return $a[array_rand($a)];
    }

    /** Weighted pick: ['value' => weight, ...] */
    private function weighted(array $map): string
    {
        $total = array_sum($map);
        $r = mt_rand(1, $total);
        foreach ($map as $value => $weight) {
            $r -= $weight;
            if ($r <= 0) {
                return (string) $value;
            }
        }
        return (string) array_key_first($map);
    }

    private function username(string $fullName): string
    {
        $base = Str::slug($fullName, '') ?: 'user';
        $base = Str::lower($base);
        $name = $base;
        $i = 1;
        while (in_array($name, $this->usernames, true)) {
            $name = $base . $i++;
        }
        $this->usernames[] = $name;
        return $name;
    }

    private function ref(string $prefix): string
    {
        return $prefix . '-' . Str::upper(Str::random(8));
    }

    // ---------------------------------------------------------------- seeders

    private function seedAdmin(): int
    {
        return DB::table('users')->insertGetId([
            'full_name' => 'Prince Estrella',
            'username' => $this->username('Prince Estrella'),
            'email' => 'prince.estrella16@gmail.com',
            'password' => Hash::make('prince13161729'),
            'phone' => '09171234567',
            'role' => 'admin',
            'status' => 'active',
            'email_verified' => 1,
            'created_at' => now(),
        ]);
    }

    private function seedUsers(): array
    {
        $first = ['Maria', 'Jose', 'Juan', 'Ana', 'Pedro', 'Rosa', 'Mark', 'Grace', 'John', 'Liza',
            'Paolo', 'Andrea', 'Carlo', 'Bea', 'Miguel', 'Nicole', 'Diego', 'Erica', 'Ramon', 'Karla',
            'Vincent', 'Joy', 'Allan', 'Mae', 'Ryan', 'Ivy', 'Dennis', 'Faith', 'Noel', 'Hazel',
            'Patrick', 'Janine', 'Kevin', 'Cristina', 'Bryan', 'Angelica', 'Rafael', 'Sheila', 'Oscar', 'Camille'];
        $last = ['Santos', 'Reyes', 'Cruz', 'Bautista', 'Garcia', 'Mendoza', 'Torres', 'Flores', 'Ramos',
            'Gonzales', 'Castro', 'Aquino', 'Villanueva', 'Domingo', 'Salazar', 'Navarro', 'Pascual',
            'Delos Santos', 'Tan', 'Lim', 'Manalo', 'Rivera', 'Fernandez', 'De Leon', 'Marquez'];

        // Hash once and reuse for every non-admin (bcrypt is slow; 1 hash vs 70).
        $sharedPassword = Hash::make('password123');

        $adopters = [];
        $volunteers = [];
        $staff = [];
        $emailUsed = [];

        $make = function (string $role) use ($first, $last, $sharedPassword, &$emailUsed) {
            $full = $this->pick($first) . ' ' . $this->pick($last);
            $uname = $this->username($full);
            $email = $uname;
            while (isset($emailUsed[$email . '@example.com'])) {
                $email = $uname . mt_rand(1, 999);
            }
            $email .= '@example.com';
            $emailUsed[$email] = true;

            return DB::table('users')->insertGetId([
                'full_name' => $full,
                'username' => $uname,
                'email' => $email,
                'password' => $sharedPassword,
                'phone' => '09' . mt_rand(100000000, 999999999),
                'role' => $role,
                'status' => $this->weighted(['active' => 92, 'suspended' => 8]),
                'email_verified' => $this->weighted(['1' => 80, '0' => 20]),
                'created_at' => now()->subDays(mt_rand(1, 300)),
            ]);
        };

        for ($i = 0; $i < 60; $i++) {
            $adopters[] = $make('user');
        }
        for ($i = 0; $i < 10; $i++) {
            $volunteers[] = $make('volunteer');
        }
        for ($i = 0; $i < 3; $i++) {
            $staff[] = $make('staff');
        }

        return [
            'adopters' => $adopters,
            'volunteers' => $volunteers,
            'staff' => $staff,
            'all' => array_merge($adopters, $volunteers, $staff),
        ];
    }

    private function seedDogs(): array
    {
        $names = ['Brownie', 'Bantay', 'Max', 'Bella', 'Tito', 'Whitey', 'Blackie', 'Princess', 'Buddy',
            'Lucky', 'Coco', 'Rocky', 'Daisy', 'Choco', 'Spike', 'Lassie', 'Rex', 'Shadow', 'Ginger',
            'Peanut', 'Oreo', 'Mocha', 'Snoopy', 'Bruno', 'Tiger', 'Lily', 'Chichay', 'Bogart', 'Asong',
            'Pippa', 'Maya', 'Toby', 'Nala', 'Simba', 'Bingo', 'Kobe', 'Luna', 'Milo', 'Pancho', 'Tala'];
        $breeds = ['Aspin', 'Aspin', 'Aspin', 'Askal', 'Mixed', 'Mixed', 'Labrador Retriever', 'Shih Tzu',
            'Beagle', 'Golden Retriever', 'Pomeranian', 'Dachshund', 'Chihuahua', 'Siberian Husky', 'Terrier Mix'];
        $behaviors = ['separation anxiety', 'excessive barking', 'food aggression', 'pulling on leash',
            'dog-to-dog aggression', 'territorial aggression', 'fear of loud noises', 'post-trauma/trust issues',
            'extreme shyness', 'destructive chewing & digging', 'inappropriate elimination'];
        $stories = [
            'Rescued from the streets of Calamba, malnourished but friendly.',
            'Surrendered by an owner who could no longer provide care.',
            'Found abandoned near a market, now recovering well.',
            'Rescued from a flooded area during the rainy season.',
            'Taken in after being spotted limping along the highway.',
            'Part of a litter rescued from an unsafe backyard.',
        ];
        $vaccines = ['Anti-Rabies', '5-in-1 (DHLPP)', 'Anti-Rabies Booster', 'Deworming', 'Bordetella'];
        $medTypes = ['checkup', 'surgery', 'treatment', 'deworming', 'grooming'];

        $ids = [];

        for ($i = 0; $i < 58; $i++) {
            $tags = [];
            $tagCount = $this->weighted(['0' => 35, '1' => 30, '2' => 25, '3' => 10]);
            $pool = $behaviors;
            shuffle($pool);
            for ($t = 0; $t < (int) $tagCount; $t++) {
                $tags[] = $pool[$t];
            }

            $animalId = DB::table('animals')->insertGetId([
                'name' => $this->pick($names),
                'species' => 'dog',
                'breed' => $this->pick($breeds),
                'age' => mt_rand(0, 12),
                'gender' => $this->pick(['male', 'female']),
                'size' => $this->pick(['small', 'medium', 'large']),
                'weight' => mt_rand(30, 350) / 10,
                'status' => $this->weighted([
                    'available' => 58, 'adopted' => 15, 'fostered' => 10,
                    'medical' => 8, 'quarantine' => 6, 'archived' => 3,
                ]),
                'rescue_story' => $this->pick($stories),
                'behavioral_assessment' => empty($tags) ? null : json_encode($tags),
                'created_at' => now()->subDays(mt_rand(1, 300)),
            ]);
            $ids[] = $animalId;

            // One main photo, random dog1-9.
            DB::table('animal_photos')->insert([
                'animal_id' => $animalId,
                'photo_url' => 'animals/dog' . mt_rand(1, 9) . '.jpg',
                'is_main' => 1,
            ]);

            // 0-3 medical records (with cost, for the financial view).
            $medCount = mt_rand(0, 3);
            for ($m = 0; $m < $medCount; $m++) {
                DB::table('medical_records')->insert([
                    'animal_id' => $animalId,
                    'type' => $this->pick($medTypes),
                    'description' => 'Routine ' . $this->pick($medTypes) . ' performed at the shelter clinic.',
                    'vet_name' => 'Dr. ' . $this->pick(['Cruz', 'Reyes', 'Santos', 'Lim']),
                    'cost' => mt_rand(300, 6000),
                    'record_date' => now()->subDays(mt_rand(1, 200))->toDateString(),
                    'notes' => null,
                ]);
            }

            // 1-2 vaccinations; some with a future next_due -> generate a reminder.
            $vacCount = mt_rand(1, 2);
            for ($v = 0; $v < $vacCount; $v++) {
                $given = now()->subDays(mt_rand(10, 300));
                $hasDue = mt_rand(0, 1) === 1;
                $nextDue = $hasDue ? $given->copy()->addYear() : null;
                $vaccineName = $this->pick($vaccines);

                $vacId = DB::table('vaccinations')->insertGetId([
                    'animal_id' => $animalId,
                    'vaccine_name' => $vaccineName,
                    'date_given' => $given->toDateString(),
                    'next_due' => $nextDue?->toDateString(),
                ]);

                if ($nextDue) {
                    DB::table('reminders')->insert([
                        'animal_id' => $animalId,
                        'remindable_type' => 'App\\Models\\Vaccination',
                        'remindable_id' => $vacId,
                        'title' => $vaccineName . ' booster due',
                        'reminder_date' => $nextDue->toDateString(),
                        'status' => 'pending',
                        'created_at' => now(),
                    ]);
                }
            }
        }

        return $ids;
    }

    private function seedDonations(array $userIds): void
    {
        $amounts = [50, 100, 100, 200, 200, 500, 500, 1000, 1000, 2000, 5000];
        for ($i = 0; $i < 180; $i++) {
            $method = $this->weighted(['gcash' => 60, 'cash' => 25, 'bank' => 15]);
            DB::table('donations')->insert([
                'user_id' => $this->pick($userIds),
                'reference_no' => $this->ref('DON'),
                'amount' => $this->pick($amounts),
                'payment_method' => $method,
                'proof_image' => null,
                'status' => $this->weighted(['verified' => 75, 'pending' => 15, 'rejected' => 10]),
                'is_anonymous' => $this->weighted(['1' => 40, '0' => 60]),
                'donated_at' => now()->subDays(mt_rand(0, 180))->subHours(mt_rand(0, 23)),
            ]);
        }
    }

    private function seedAdoptionApplications(array $userIds, array $animalIds): void
    {
        $housing = ['Owned house with yard', 'Condominium', 'Apartment', 'Townhouse'];
        $occ = ['Teacher', 'Engineer', 'Nurse', 'Student', 'Business Owner', 'Freelancer', 'Government Employee'];
        for ($i = 0; $i < 50; $i++) {
            $status = $this->weighted(['pending' => 35, 'approved' => 20, 'completed' => 25, 'declined' => 20]);
            $hvStatus = match ($status) {
                'approved' => 'scheduled',
                'completed' => 'completed',
                default => 'not_scheduled',
            };
            DB::table('adoption_applications')->insert([
                'user_id' => $this->pick($userIds),
                'animal_id' => $this->pick($animalIds),
                'reference_no' => $this->ref('ADO'),
                'status' => $status,
                'full_name' => $this->pick(['Maria Santos', 'Juan Cruz', 'Ana Reyes', 'Mark Lim', 'Grace Tan', 'Carlo Rivera']),
                'address' => mt_rand(1, 200) . ' Rizal St., Calamba, Laguna',
                'occupation' => $this->pick($occ),
                'housing_type' => $this->pick($housing),
                'pet_experience' => $this->pick(['First-time pet owner.', 'Has cared for dogs before.', 'Currently has one other dog.']),
                'reason' => $this->pick(['Looking for a companion.', 'Want to give a rescue a loving home.', 'Family is ready for a pet.']),
                'contact_number' => '09' . mt_rand(100000000, 999999999),
                'home_visit_status' => $hvStatus,
                'home_visit_date' => $hvStatus === 'not_scheduled' ? null : now()->addDays(mt_rand(-30, 20))->toDateString(),
                'home_visit_notes' => $hvStatus === 'completed' ? 'Home environment suitable.' : null,
                'read_at' => $status === 'pending' ? null : now(),
                'created_at' => now()->subDays(mt_rand(1, 120)),
            ]);
        }
    }

    private function seedFosterApplications(array $userIds, array $animalIds): void
    {
        for ($i = 0; $i < 15; $i++) {
            $status = $this->weighted(['pending' => 40, 'approved' => 25, 'fostering' => 20, 'completed' => 15]);
            $start = in_array($status, ['fostering', 'completed'], true) ? now()->subDays(mt_rand(10, 90)) : null;
            DB::table('foster_applications')->insert([
                'user_id' => $this->pick($userIds),
                'animal_id' => $this->pick($animalIds),
                'status' => $status,
                'full_name' => $this->pick(['Liza Flores', 'Noel Castro', 'Bea Aquino', 'Ramon Garcia']),
                'address' => mt_rand(1, 200) . ' Mabini St., Calamba, Laguna',
                'occupation' => $this->pick(['Teacher', 'Nurse', 'Freelancer']),
                'housing_type' => $this->pick(['Owned house with yard', 'Apartment']),
                'pet_experience' => 'Has fostered animals before.',
                'reason' => 'Able to provide temporary care.',
                'start_date' => $start?->toDateString(),
                'end_date' => $status === 'completed' ? $start?->copy()->addDays(mt_rand(30, 60))->toDateString() : null,
                'notes' => null,
                'created_at' => now()->subDays(mt_rand(1, 100)),
            ]);
        }
    }

    private function seedRescueReports(): void
    {
        $locations = ['Brgy. Parian, Calamba', 'Crossing, Calamba', 'Brgy. Real, Calamba', 'Los Baños highway', 'Brgy. Halang, Calamba'];
        $descs = ['Stray dog limping, possibly hit by a vehicle.', 'Abandoned puppies in a box.', 'Aggressive-looking dog scaring residents.', 'Malnourished dog tied without food.', 'Injured dog near the market.'];
        for ($i = 0; $i < 30; $i++) {
            $status = $this->weighted(['pending' => 30, 'assigned' => 25, 'in_progress' => 20, 'resolved' => 25]);
            $assigned = $status === 'pending' ? null : 'Team ' . $this->pick(['Alpha', 'Bravo', 'Charlie']);
            DB::table('rescue_reports')->insert([
                'reporter_name' => $this->pick(['Concerned Citizen', 'Anonymous', 'Maria L.', 'Pedro R.', 'Barangay Tanod']),
                'contact_number' => '09' . mt_rand(100000000, 999999999),
                'location' => $this->pick($locations),
                'description' => $this->pick($descs),
                'urgency' => $this->weighted(['low' => 20, 'medium' => 35, 'high' => 30, 'critical' => 15]),
                'status' => $status,
                'photo_url' => null,
                'assigned_to' => $assigned,
                'admin_notes' => $status === 'resolved' ? 'Animal rescued and brought to the shelter.' : null,
                'read_at' => $status === 'pending' ? null : now(),
                'created_at' => now()->subDays(mt_rand(1, 90)),
            ]);
        }
    }

    private function seedPersonnel(array $personnelUserIds): void
    {
        $tasks = ['Morning feeding', 'Kennel cleaning', 'Dog walking', 'Bathing & grooming', 'Adoption event assistance', 'Medication assistance', 'Intake processing'];
        foreach ($personnelUserIds as $userId) {
            $role = DB::table('users')->where('id', $userId)->value('role');
            $volunteerId = DB::table('volunteers')->insertGetId([
                'user_id' => $userId,
                'type' => $role === 'staff' ? 'staff' : 'volunteer',
                'availability' => $this->pick(['Weekends', 'Weekdays', 'Flexible', 'Mon/Wed/Fri']),
                'hours_rendered' => mt_rand(0, 120),
                'performance_notes' => $this->pick([null, 'Reliable and punctual.', 'Great with the animals.']),
                'created_at' => now()->subDays(mt_rand(10, 200)),
            ]);

            $taskCount = mt_rand(1, 3);
            for ($t = 0; $t < $taskCount; $t++) {
                DB::table('volunteer_tasks')->insert([
                    'volunteer_id' => $volunteerId,
                    'task_name' => $this->pick($tasks),
                    'status' => $this->weighted(['assigned' => 35, 'ongoing' => 30, 'completed' => 35]),
                    'assigned_date' => now()->subDays(mt_rand(0, 60))->toDateString(),
                    'created_at' => now(),
                ]);
            }
        }
    }

    private function seedVolunteerApplications(array $userIds): void
    {
        $pick = collect($userIds)->shuffle()->take(10);
        foreach ($pick as $userId) {
            $status = $this->weighted(['pending' => 50, 'approved' => 30, 'rejected' => 20]);
            DB::table('volunteer_applications')->insert([
                'user_id' => $userId,
                'availability' => $this->pick(['Weekends', 'Weekdays', 'Flexible']),
                'experience' => $this->pick(['No prior experience but eager to help.', 'Volunteered at another shelter.', 'Owns several rescue dogs.']),
                'reason' => 'Want to contribute to animal welfare.',
                'status' => $status,
                'admin_notes' => $status === 'rejected' ? 'Schedule did not match current needs.' : null,
                'read_at' => $status === 'pending' ? null : now(),
                'created_at' => now()->subDays(mt_rand(1, 80)),
            ]);
        }
    }

    private function seedVisitations(array $userIds): void
    {
        for ($i = 0; $i < 20; $i++) {
            $status = $this->weighted(['pending' => 30, 'approved' => 30, 'rejected' => 15, 'completed' => 25]);
            $date = $status === 'completed'
                ? now()->subDays(mt_rand(1, 40))
                : now()->addDays(mt_rand(1, 30));
            DB::table('visitations')->insert([
                'user_id' => $this->pick($userIds),
                'requested_date' => $date->toDateString(),
                'time_slot' => $this->pick(['morning', 'afternoon', 'evening']),
                'num_visitors' => mt_rand(1, 4),
                'notes' => $this->pick([null, 'Interested in adopting a medium-sized dog.', 'Family visit with kids.']),
                'status' => $status,
                'admin_notes' => null,
                'read_at' => $status === 'pending' ? null : now(),
                'created_at' => now()->subDays(mt_rand(0, 50)),
            ]);
        }
    }

    private function seedIntakes(array $animalIds): void
    {
        for ($i = 0; $i < 10; $i++) {
            $status = $this->weighted(['pending' => 30, 'under_assessment' => 25, 'approved' => 15, 'converted' => 20, 'rejected' => 10]);
            DB::table('intakes')->insert([
                'intake_type' => $this->pick(['rescue', 'owner_surrender', 'stray']),
                'reporter_name' => $this->pick(['Brgy. Official', 'Walk-in', 'Anonymous', 'Rescuer Volunteer']),
                'contact_number' => '09' . mt_rand(100000000, 999999999),
                'location' => $this->pick(['Calamba', 'Los Baños', 'Cabuyao']),
                'animal_name' => $this->pick(['Unnamed', 'Brownie', 'Blackie', 'Puppy']),
                'species' => 'Dog',
                'breed' => $this->pick(['Aspin', 'Mixed', 'Askal']),
                'estimated_age' => $this->pick(['2 months', '1 year', '3 years', 'Adult']),
                'gender' => $this->pick(['male', 'female']),
                'description' => 'Brought in for assessment and possible shelter intake.',
                'status' => $status,
                'assessment_notes' => $status === 'pending' ? null : 'Initial health check done.',
                'assessed_by' => $status === 'pending' ? null : 'Dr. Cruz',
                'assessment_date' => $status === 'pending' ? null : now()->subDays(mt_rand(1, 30))->toDateString(),
                'converted_animal_id' => $status === 'converted' ? $this->pick($animalIds) : null,
                'created_at' => now()->subDays(mt_rand(1, 60)),
            ]);
        }
    }

    private function seedSettings(): void
    {
        Setting::setMany([
            'shelter_name' => 'SECASPI Shelter',
            'contact_email' => 'secaspishelter@gmail.com',
            'contact_phone' => '0917 123 4567',
            'address' => 'Calamba, Laguna, Philippines',
            'social_facebook' => 'https://facebook.com/secaspishelter',
            'social_instagram' => 'https://instagram.com/secaspishelter',
            'hero_title' => 'Give a Rescued Aspin a Loving Home',
            'hero_subtitle' => 'SECASPI rescues, rehabilitates, and rehomes stray and surrendered animals across Calamba, Laguna.',
            'about_us_content' => 'SECASPI is a volunteer-run animal welfare shelter dedicated to rescuing, treating, and finding forever homes for dogs in need.',
            'adoption_policies' => 'Adopters must be at least 18 years old, provide a safe home environment, and agree to a follow-up home visit.',
            'donation_monthly_goal' => '80000',
            'logo_path' => 'animals/pbi.jpg',
            'fund_usage_image_path' => 'animals/gcash.png',
        ]);
    }
}
