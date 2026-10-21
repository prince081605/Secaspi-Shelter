<?php

use Carbon\CarbonImmutable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * An animal's age was a fixed number of whole years, so a puppy could only be "0" and never grew
 * older. It is now an estimated birth date, and the age is worked out from it whenever it's shown
 * (App\Support\AnimalAge).
 *
 * The ages already on record become birth dates counted back from when each animal was recorded
 * (created_at), since that's when the age was true. An age of 0 meant "under a year", which is
 * taken as about 6 months.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('animals', function (Blueprint $table) {
            $table->date('birth_date')->nullable();
        });

        foreach (DB::table('animals')->whereNotNull('age')->get(['id', 'age', 'created_at']) as $animal) {
            $recorded = $animal->created_at ? CarbonImmutable::parse($animal->created_at) : CarbonImmutable::now('Asia/Manila');
            $born = (int) $animal->age === 0 ? $recorded->subMonthsNoOverflow(6) : $recorded->subYearsNoOverflow((int) $animal->age);
            DB::table('animals')->where('id', $animal->id)->update(['birth_date' => $born->toDateString()]);
        }

        Schema::table('animals', function (Blueprint $table) {
            $table->dropColumn('age');
        });
    }

    public function down(): void
    {
        Schema::table('animals', function (Blueprint $table) {
            $table->integer('age')->nullable();
        });

        $today = CarbonImmutable::now('Asia/Manila');
        foreach (DB::table('animals')->whereNotNull('birth_date')->get(['id', 'birth_date']) as $animal) {
            $years = (int) floor(CarbonImmutable::parse($animal->birth_date)->diffInYears($today));
            DB::table('animals')->where('id', $animal->id)->update(['age' => max(0, $years)]);
        }

        Schema::table('animals', function (Blueprint $table) {
            $table->dropColumn('birth_date');
        });
    }
};
