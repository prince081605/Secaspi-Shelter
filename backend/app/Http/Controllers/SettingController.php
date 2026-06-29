<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class SettingController extends Controller
{
    /**
     * Keys safe to expose on the unauthenticated public endpoint — branding, contact, hero,
     * socials, public images, the donation goal, and whether the AI assistant is enabled.
     * Internal AI config (`ai_persona`, `ai_daily_message_cap`) and `cost_per_meal` are withheld.
     */
    private const PUBLIC_KEYS = [
        'shelter_name', 'contact_email', 'contact_phone', 'address',
        'social_facebook', 'social_instagram', 'social_twitter',
        'hero_title', 'hero_subtitle', 'about_us_content', 'adoption_policies',
        'banner_image_path', 'logo_path', 'fund_usage_image_path',
        'donation_monthly_goal', 'ai_assistant_enabled',
    ];

    public function publicIndex()
    {
        $public = array_intersect_key(Setting::getAll(), array_flip(self::PUBLIC_KEYS));

        return response()->json($public);
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
            // AI Shelter Assistant controls (the API key lives in env, never here).
            'ai_assistant_enabled' => ['nullable', 'in:0,1'],
            'ai_daily_message_cap' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'ai_persona' => ['nullable', 'string', 'max:500'],
            // Cost of one meal, used to turn donations into "X meals funded" on the impact page.
            'cost_per_meal' => ['nullable', 'numeric', 'min:1'],
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
