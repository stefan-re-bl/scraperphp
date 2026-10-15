<?php

namespace App\Http\Controllers;

use App\Models\Exclusion;
use App\Models\MessageTemplate;
use App\Models\Offer;
use App\Services\ExclusionService;
use App\Services\PreferencesService;
use Illuminate\Http\Request;

class SettingsController extends Controller
{
    public function settings(PreferencesService $preferences): mixed
    {
        return view('sales.settings', ['offers' => Offer::latest()->get(), 'templates' => MessageTemplate::latest()->get(), 'exclusions' => Exclusion::latest()->limit(30)->get(), 'preferences' => $preferences->all()]);
    }

    public function savePreferences(Request $request, PreferencesService $preferences): mixed
    {
        $data = $request->validate(['timezone' => 'required|timezone', 'currency' => 'required|string|size:3', 'country' => 'required|string|max:255', 'language' => 'required|string|max:10', 'date_format' => 'required|in:d/m/Y H:i,Y-m-d H:i,m/d/Y H:i']);
        $data['currency'] = strtoupper($data['currency']);
        $preferences->save($data);

        return back()->with('status', 'Preferencias guardadas.');
    }

    public function exclusions(): mixed
    {
        return view('sales.exclusions', ['exclusions' => Exclusion::latest()->orderByDesc('id')->paginate(100)->withQueryString()]);
    }

    public function storeExclusion(Request $request, ExclusionService $exclusions): mixed
    {
        $data = $request->validate(['type' => 'required|in:email,domain,phone,name,source_identifier', 'value' => 'required|string|max:255', 'reason' => 'nullable|string|max:255']);
        $exclusions->store($data['type'], $data['value'], $data['reason'] ?? null);

        return back()->with('status', 'Exclusión aplicada a registros existentes y futuras importaciones.');
    }

    public function deleteExclusion(Exclusion $exclusion): mixed
    {
        $exclusion->delete();

        return back()->with('status', 'Exclusión eliminada. Los prospectos conservan su pausa hasta revisión manual.');
    }
}
