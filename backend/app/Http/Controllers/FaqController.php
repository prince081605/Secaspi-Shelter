<?php

namespace App\Http\Controllers;

use App\Models\FaqEntry;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * Admin CRUD for the assistant's trainable FAQ knowledge base. Editing here is how the admin
 * "trains" the assistant — the FaqMatcher picks up changes automatically (its cache is keyed on
 * the entries' latest update).
 */
class FaqController extends Controller
{
    public function adminIndex()
    {
        return response()->json([
            'faqs' => FaqEntry::orderByDesc('hits')->orderBy('question')->get(),
        ]);
    }

    public function store(Request $request)
    {
        $data = $this->validateData($request);
        $faq = FaqEntry::create($data);

        return response()->json(['faq' => $faq], 201);
    }

    public function update(Request $request, FaqEntry $faq)
    {
        $faq->update($this->validateData($request));

        return response()->json(['faq' => $faq]);
    }

    public function destroy(FaqEntry $faq)
    {
        $faq->delete();

        return response()->json(['message' => 'FAQ deleted']);
    }

    private function validateData(Request $request): array
    {
        $validator = Validator::make($request->all(), [
            'question' => ['required', 'string', 'max:255'],
            'answer' => ['required', 'string', 'max:5000'],
            'tags' => ['nullable', 'string', 'max:255'],
            'enabled' => ['sometimes', 'boolean'],
        ]);

        if ($validator->fails()) {
            abort(response()->json(['message' => 'Validation failed', 'errors' => $validator->errors()], 422));
        }

        return $validator->validated();
    }
}
