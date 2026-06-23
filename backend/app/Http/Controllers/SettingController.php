<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class SettingController extends Controller
{
    public function publicIndex()
    {
        return response()->json(Setting::getAll());
    }

    public function adminIndex()
    {
        return response()->json(Setting::getAll());
    }

    public function adminUpdate(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'shelter_name' => ['nullable', 'string', 'max:150'],
            'contact_email' => ['nullable', 'email', 'max:150'],
            'contact_phone' => ['nullable', 'string', 'max:30'],
            'address' => ['nullable', 'string', 'max:255'],
            'social_facebook' => ['nullable', 'string', 'max:255'],
            'social_instagram' => ['nullable', 'string', 'max:255'],
            'social_twitter' => ['nullable', 'string', 'max:255'],
            'hero_title' => ['nullable', 'string', 'max:200'],
            'hero_subtitle' => ['nullable', 'string', 'max:500'],
            'about_us_content' => ['nullable', 'string'],
            'adoption_policies' => ['nullable', 'string'],
            'donation_monthly_goal' => ['nullable', 'numeric', 'min:0'],
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }

        Setting::setMany($validator->validated());

        return response()->json(['settings' => Setting::getAll()]);
    }

    public function adminUploadImage(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'key' => ['required', 'in:logo_path,banner_image_path,fund_usage_image_path'],
            'image' => ['required', 'image', 'max:5120'],
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }

        $path = $request->file('image')->store('settings');
        Setting::setMany([$request->input('key') => $path]);

        return response()->json(['settings' => Setting::getAll()]);
    }
}
