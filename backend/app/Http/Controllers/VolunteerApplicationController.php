<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\MarksAdminRead;
use App\Models\User;
use App\Models\Volunteer;
use App\Models\VolunteerApplication;
use App\Notifications\VolunteerApplicationStatusChanged;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class VolunteerApplicationController extends Controller
{
    use MarksAdminRead;

    private const STATUSES = ['pending', 'approved', 'rejected'];

    /**
     * The IDs an applicant may present. Kept in sync by hand with frontend/src/lib/validIdTypes.js,
     * the same arrangement donation categories use; the "unknown id type" test is what catches
     * the two lists drifting apart.
     */
    private const ID_TYPES = [
        'school_id',
        'national_id',
        'umid',
        'drivers_license',
        'postal_id',
        'philhealth',
        'passport',
        'other',
    ];

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'availability' => ['required', 'string', 'max:150'],
            'experience' => ['nullable', 'string'],
            'reason' => ['required', 'string'],
            // Volunteers work with the animals and meet the public, so the shelter records who
            // they are. 5 MB matches every other upload in the app.
            'valid_id_type' => ['required', Rule::in(self::ID_TYPES)],
            'valid_id_number' => ['required', 'string', 'max:100'],
            'valid_id_image' => ['required', 'image', 'max:5120'],
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }

        $user = $request->user();

        // One open application at a time, and not if they're already a volunteer.
        if (Volunteer::where('user_id', $user->id)->exists()) {
            return response()->json(['message' => 'You are already a volunteer.'], 409);
        }
        if (VolunteerApplication::where('user_id', $user->id)->where('status', 'pending')->exists()) {
            return response()->json(['message' => 'You already have a pending volunteer application.'], 409);
        }

        $data = $validator->validated();
        // Named explicitly rather than spread: validated() now carries the UploadedFile under
        // 'valid_id_image', and spreading that into create() would try to mass-assign the file
        // object itself. Only the stored path belongs on the model.
        $idPath = null;

        try {
            // Stored after the duplicate guards above, so a rejected application never leaves
            // an orphaned upload on disk.
            $idPath = $request->file('valid_id_image')->store('volunteer-ids');

            $application = VolunteerApplication::create([
                'user_id' => $user->id,
                'status' => 'pending',
                'availability' => $data['availability'],
                'experience' => $data['experience'] ?? null,
                'reason' => $data['reason'],
                'valid_id_type' => $data['valid_id_type'],
                'valid_id_number' => $data['valid_id_number'],
                'valid_id_path' => $idPath,
            ]);
        } catch (\Throwable $e) {
            if ($idPath) {
                Storage::delete($idPath);
            }

            Log::error('Failed to create volunteer application', [
                'user_id' => $user->id,
                'exception' => $e,
            ]);

            return response()->json(['message' => 'Failed to submit your application. Please try again.'], 500);
        }

        return response()->json(['application' => $this->toItem($application)], 201);
    }

    public function index(Request $request)
    {
        $applications = VolunteerApplication::where('user_id', $request->user()->id)
            ->orderByDesc('id')
            ->get();

        return response()->json([
            'applications' => $applications->map(fn (VolunteerApplication $a) => $this->toItem($a))->values(),
        ]);
    }

    public function adminIndex(Request $request)
    {
        $query = VolunteerApplication::query()->with('user');

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        if ($request->boolean('unread')) {
            $query->whereNull('read_at');
        }

        $applications = $query->orderByDesc('id')->paginate(20)->withQueryString();

        $applications->getCollection()->transform(fn (VolunteerApplication $a) => $this->toAdminItem($a));

        return response()->json($applications);
    }

    public function adminUpdate(Request $request, VolunteerApplication $application)
    {
        $validator = Validator::make($request->all(), [
            'status' => ['sometimes', 'in:'.implode(',', self::STATUSES)],
            'admin_notes' => ['nullable', 'string', 'max:1000'],
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }

        $data = $validator->validated();
        if (is_null($application->read_at)) {
            $data['read_at'] = now();
        }

        $application->update($data);
        $statusChanged = $application->wasChanged('status');

        // Approving onboards the applicant: create a Volunteer (carrying their stated
        // availability) and promote their role. Mirrors VolunteerController@adminStore.
        if ($statusChanged && $application->status === 'approved') {
            $volunteer = Volunteer::firstOrCreate(
                ['user_id' => $application->user_id],
                ['availability' => $application->availability]
            );

            // role isn't mass-assignable (privilege-escalation guard), so forceFill it —
            // same approach as UserController::adminUpdate.
            $user = User::find($application->user_id);
            if ($user && $user->role !== 'admin') {
                $user->forceFill(['role' => 'volunteer'])->save();
            }
        }

        $application = $application->fresh('user');

        if ($statusChanged && $application->user) {
            (new VolunteerApplicationStatusChanged($application))->sendTo($application->user);
        }

        return response()->json(['application' => $this->toAdminItem($application)]);
    }

    public function adminMarkRead(VolunteerApplication $application)
    {
        $this->markReadOnce($application);

        return response()->json(['application' => $this->toAdminItem($application)]);
    }

    private function toItem(VolunteerApplication $a): array
    {
        return [
            'id' => $a->id,
            'availability' => $a->availability,
            'experience' => $a->experience,
            'reason' => $a->reason,
            'status' => $a->status,
            'admin_notes' => $a->admin_notes,
            'created_at' => $a->created_at,
        ];
    }

    /**
     * The staff view of an application. The ID lives here and deliberately not in toItem(): that
     * one answers the applicant's own request, and echoing their ID number and a link to the
     * photo back over the wire would put it somewhere it does not need to be.
     */
    private function toAdminItem(VolunteerApplication $a): array
    {
        return [
            ...$this->toItem($a),
            'valid_id_type' => $a->valid_id_type,
            'valid_id_number' => $a->valid_id_number,
            'valid_id_url' => $a->valid_id_path ? Storage::url($a->valid_id_path) : null,
            'read_at' => $a->read_at,
            'applicant' => $a->user ? [
                'id' => $a->user->id,
                'full_name' => $a->user->full_name,
                'email' => $a->user->email,
                'phone' => $a->user->phone,
            ] : null,
        ];
    }
}
