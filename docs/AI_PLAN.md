# AI Assistant Plan — Progress Tracker

Goal: a **free, self-contained "AI"** for the shelter site — a trainable FAQ knowledge base with
similarity matching, plus a natural-language dog finder. No API key required, no ongoing cost.
(Optional paid upgrades noted at the end.)

Last updated: 2026-06-27

---

## ✅ DONE

### Step 1 — Trainable FAQ Knowledge Base (shipped, commit `08eead6`)
- [x] `faq_entries` table + `FaqEntry` model (question, answer, tags, enabled, hits)
- [x] `FaqMatcher` service — pure-PHP **TF-IDF cosine similarity** so paraphrases match
      (e.g. "whats the process to get a dog" → adoption answer). Cached, auto-refreshes on edits.
- [x] Assistant routing: greetings → knowledge base → live animal search → paid AI (only if a key
      is added) → friendly fallback
- [x] `animalAnswer` tightened to require real "list intent" (so "I saw a hurt puppy" → rescue, not
      a dog list)
- [x] Admin **"AI Training"** screen (Dashboard → Operations → 🧠 AI Training): add / edit / delete
      Q&As, enable toggle, **Hits** column, and a **🧪 Test a question** box (train → test in one place)
- [x] Admin-only API: `GET/POST /api/admin/faqs`, `PUT/DELETE /api/admin/faqs/{id}`
- [x] Tests: `FaqMatcherTest` (3), `FaqControllerTest` (3); full suite green (111)

### Train the assistant with system knowledge (shipped, commit `cd74767`)
- [x] Seeded **34 Q&As** covering: sign-up/login/password, what SECASPI & Aspins are, adoption flow
      (apply, track, after-apply, re-apply rule, home visit), Matchmaker, donations (methods,
      anonymity, history, verification), badges + leaderboard + "meals funded", messaging, the
      notification bell, animal QR codes, volunteering, medical records, temperament, assistant help
- [x] Verified live: paraphrased questions answer correctly from the knowledge base

---

## ⏳ NOT DONE (next steps)

### Step 2 — Natural-language dog finder
- [ ] Parse free-text queries ("calm small dog good with kids", "young energetic dog for a yard")
      into structured intent (species, size, age band, temperament, lifestyle)
- [ ] Score & rank available animals via existing `AdoptionMatchService`, returning matches **with
      reasons** (not just a flat list)
- [ ] Wire into the assistant (upgrade `animalAnswer`) and optionally add a natural-language search
      box on the Adoption page
- [ ] Tests + live verification

### Step 3 — Optional semantic upgrade (only if needed, small cost)
- [ ] Swap TF-IDF for true **embeddings** (store one per FAQ, generated once via a cheap embeddings
      API) behind the same `FaqMatcher` interface — better paraphrase understanding, no UI rework

### Optional — Full open-ended AI (already plumbed, just turn on)
- [ ] Add `OPENAI_API_KEY` to backend env → admin Settings → turn AI Assistant **On**
      (cost-capped; without it, the assistant stays in free knowledge-base mode)

---

## 📌 Recommended content polish (you / admin, anytime — no code)
- [ ] Replace generic answers with **real specifics** in AI Training: exact address, opening hours,
      adoption fee amount, and adoption policies
- [ ] Add any shelter-specific Q&As the panel might ask

## Key files (reference)
- `backend/app/Services/FaqMatcher.php` — similarity engine
- `backend/app/Http/Controllers/AiAssistantController.php` — chat routing
- `backend/app/Http/Controllers/FaqController.php` — admin CRUD
- `backend/database/seeders/FaqSeeder.php` — seeded knowledge (editable after seeding)
- `frontend/src/pages/admin/FaqTrainingAdmin.jsx` — the AI Training screen
