<?php

namespace App\Http\Controllers;

use App\Jobs\RecalculateScores;
use App\Models\MessageTemplate;
use App\Models\Offer;
use App\Models\ScoringRule;
use App\Services\LeadScorer;
use Illuminate\Http\Request;

class CatalogController extends Controller
{
    public function offers(): mixed
    {
        return view('sales.offers', ['offers' => Offer::latest()->get()]);
    }

    private function offerData(Request $request, ?Offer $offer = null): array
    {
        $data = $request->validate(['name' => 'required|string|max:255', 'target_customer' => 'nullable|string|max:255', 'segment' => 'nullable|string|max:255',
            'pains' => 'nullable|string|max:10000', 'benefits' => 'nullable|string|max:10000', 'compatibility_signals' => 'nullable|string|max:10000',
            'assets' => 'nullable|string|max:10000', 'demo_url' => 'nullable|url:http,https|max:2048', 'portfolio_url' => 'nullable|url:http,https|max:2048',
            'suggested_cta' => 'nullable|string|max:255', 'keywords' => 'nullable|string|max:2000', 'match_signals' => 'nullable|string|max:2000', 'minimum_match' => 'nullable|integer|min:1|max:1000', 'scoring_profile' => 'nullable|in:booking,web,ecommerce,generic']);
        $keywords = array_values(array_filter(array_map('trim', explode(',', $data['keywords'] ?? ''))));
        $signals = array_values(array_filter(array_map('trim', explode(',', $data['match_signals'] ?? ''))));
        $data['rules'] = array_merge($offer?->rules ?? [], ['keywords' => $keywords ?: array_filter([$data['segment'] ?? null]), 'signals' => $signals, 'minimum' => $data['minimum_match'] ?? 20]);
        if (array_key_exists('scoring_profile', $data)) {
            if (filled($data['scoring_profile'])) {
                $data['rules']['scoring_profile'] = $data['scoring_profile'];
            } else {
                unset($data['rules']['scoring_profile']);
            }
        }
        unset($data['keywords'], $data['match_signals'], $data['minimum_match'], $data['scoring_profile']);

        return $data + ['active' => $request->boolean('active', true)];
    }

    public function storeOffer(Request $request): mixed
    {
        Offer::create($this->offerData($request));
        RecalculateScores::dispatch()->onQueue('sales');

        return back()->with('status', 'Oferta creada.');
    }

    public function updateOffer(Request $request, Offer $offer): mixed
    {
        $offer->update($this->offerData($request, $offer));
        RecalculateScores::dispatch()->onQueue('sales');

        return back()->with('status', 'Oferta actualizada.');
    }

    public function templates(): mixed
    {
        return view('sales.templates', ['templates' => MessageTemplate::latest()->get(), 'offers' => Offer::get()]);
    }

    private function templateData(Request $request): array
    {
        return $request->validate(['offer_id' => 'nullable|exists:offers,id', 'name' => 'required|string|max:255', 'language' => 'required|string|max:10',
            'channel' => 'required|in:email,phone,whatsapp,manual', 'segment' => 'nullable|string|max:255', 'stage' => 'required|in:initial,follow_up_1,follow_up_2',
            'subject' => 'nullable|string|max:255', 'body' => 'required|string|max:20000']) + ['active' => $request->boolean('active', true)];
    }

    public function storeTemplate(Request $request): mixed
    {
        MessageTemplate::create($this->templateData($request));

        return back()->with('status', 'Plantilla creada.');
    }

    public function updateTemplate(Request $request, MessageTemplate $messageTemplate): mixed
    {
        $messageTemplate->update($this->templateData($request));

        return back()->with('status', 'Plantilla actualizada.');
    }

    public function scoring(): mixed
    {
        app(LeadScorer::class)->rules();

        return view('sales.scoring', ['rules' => ScoringRule::with('offer')->orderBy('offer_id')->orderBy('component')->get(), 'offers' => Offer::get()]);
    }

    public function saveRule(Request $request, ?ScoringRule $scoringRule = null): mixed
    {
        $data = $request->validate(['component' => 'required|string|max:100|regex:/^[a-z0-9_]+$/', 'offer_id' => 'nullable|exists:offers,id',
            'dimension' => 'required|in:data,contact,fit', 'points' => 'required|integer|min:-100|max:100', 'weight' => 'required|integer|min:1|max:10', 'description' => 'required|string|max:255']);
        $data['active'] = $request->boolean('active');
        if ($scoringRule) {
            $scoringRule->update($data);
        } else {
            ScoringRule::updateOrCreate(['component' => $data['component'], 'offer_id' => $data['offer_id'] ?? null], $data);
        }
        RecalculateScores::dispatch()->onQueue('sales');

        return back()->with('status', 'Regla guardada. Puntajes pendientes de actualización.');
    }
}
