<?php

namespace App\Http\Controllers;

use App\Jobs\ImportLeadFile;
use App\Models\Campaign;
use App\Models\CampaignQuery;
use App\Models\ImportRun;
use App\Models\ScrapeRun;
use App\Services\GoogleMapsScraperService;
use App\Services\LeadImporter;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class AcquisitionController extends Controller
{
    public function importForm(): mixed
    {
        return view('sales.import', [
            'campaigns' => Campaign::orderBy('name')->get(),
        ]);
    }

    public function import(Request $request, LeadImporter $importer): mixed
    {
        $data = $request->validate([
            'campaign_id' => ['required', 'exists:campaigns,id'],
            'csv' => ['required', 'file', 'mimes:csv,txt', 'max:20480'],
            'source' => ['nullable', 'in:google_maps,csv_manual,referral,web'],
            'query' => ['nullable', 'string', 'max:255'],
            'location' => ['nullable', 'string', 'max:255'],
        ]);

        $run = $importer->enqueue($request->file('csv'), Campaign::findOrFail($data['campaign_id']), $data);

        $target = $request->filled('redirect_campaign')
            ? route('campaigns.show', ['campaign' => $data['campaign_id'], 'tab' => 'leads'])
            : '/leads';

        return redirect($target)->with('status', "Importación {$run->status}. Nuevos: {$run->imported_count}. Posibles duplicados: {$run->possible_duplicate_count}. Duplicados: {$run->duplicate_count}.");
    }

    public function scraper(): mixed
    {
        return view('sales.scraper', [
            'campaigns' => Campaign::with('queries')->latest()->get(),
            'queries' => CampaignQuery::latest()->limit(100)->get(),
            'runs' => ScrapeRun::latest()->limit(50)->get(),
        ]);
    }

    public function storeCampaignQuery(Request $request): mixed
    {
        CampaignQuery::create($request->validate([
            'campaign_id' => ['required', 'exists:campaigns,id'],
            'query' => ['required', 'string', 'max:255'],
            'location' => ['nullable', 'string', 'max:255'],
            'result_limit' => ['required', 'integer', 'min:1', 'max:500'],
        ]) + ['status' => 'ready']);

        return back()->with('status', 'Query creada.');
    }

    public function runScraper(Request $request, GoogleMapsScraperService $scraper): mixed
    {
        $data = $request->validate([
            'campaign_query_id' => ['required', 'exists:campaign_queries,id'],
            'dry_run' => ['nullable', 'boolean'],
        ]);
        $query = CampaignQuery::findOrFail($data['campaign_query_id']);
        $dryRun = $request->boolean('dry_run', true);
        $run = $scraper->start($query->campaign, $query, $dryRun);

        $message = $dryRun
            ? "Dry-run listo para la query #{$query->id}."
            : "Scraper iniciado en segundo plano. Run #{$run->id}. Refresca resultados en unos minutos.";

        return back()->with('status', $message);
    }

    public function runCampaignAcquisition(Request $request, Campaign $campaign, GoogleMapsScraperService $scraper): mixed
    {
        $data = $request->validate([
            'query' => ['required', 'string', 'max:255'],
            'location' => ['nullable', 'string', 'max:255'],
            'result_limit' => ['required', 'integer', 'min:1', 'max:500'],
            'dry_run' => ['nullable', 'boolean'],
        ]);

        $queryData = $data;
        unset($queryData['dry_run']);

        $query = CampaignQuery::create($queryData + [
            'campaign_id' => $campaign->id,
            'status' => 'ready',
        ]);
        $dryRun = $request->boolean('dry_run', true);
        $run = $scraper->start($campaign, $query, $dryRun);
        $message = $dryRun
            ? "Dry-run listo. Query #{$query->id}."
            : "Adquisición iniciada en segundo plano. Run #{$run->id}. Refresca esta pestaña para ver encontrados, importados y errores.";

        return redirect()->route('campaigns.show', ['campaign' => $campaign->id, 'tab' => 'acquisition'])
            ->with('status', $message);
    }

    public function preview(Request $request, LeadImporter $importer): mixed
    {
        $data = $request->validate(['campaign_id' => 'required|exists:campaigns,id', 'csv' => 'required|file|mimes:csv,txt|max:20480', 'source' => 'required|in:google_maps,csv_manual,referral,web']);
        $path = $request->file('csv')->store('imports');
        $token = Str::uuid()->toString();
        $request->session()->put('import.'.$token, ['path' => storage_path('app/private/'.$path), 'campaign_id' => $data['campaign_id'], 'source' => $data['source']]);

        return view('sales.import-preview', ['preview' => $importer->preview(storage_path('app/private/'.$path)), 'token' => $token, 'fields' => LeadImporter::FIELDS]);
    }

    public function confirmImport(Request $request, LeadImporter $importer): mixed
    {
        $data = $request->validate(['token' => 'required|uuid', 'mapping' => 'nullable|array', 'mapping.*' => 'nullable|string|max:255']);
        $staged = $request->session()->get('import.'.$data['token']);
        abort_unless($staged, 404);
        $run = $importer->enqueuePath($staged['path'], Campaign::findOrFail($staged['campaign_id']), ['source' => $staged['source'], 'mapping' => $data['mapping'] ?? []]);
        $request->session()->forget('import.'.$data['token']);

        return redirect()->route('campaigns.show', ['campaign' => $run->campaign_id, 'tab' => 'acquisition'])->with('status', 'Importación en cola.');
    }

    public function status(ScrapeRun $scrapeRun): mixed
    {
        return response()->json($scrapeRun->only(['id', 'status', 'phase', 'raw_count', 'imported_count', 'duplicate_count', 'error_count', 'heartbeat_at', 'stderr']));
    }

    public function cancel(ScrapeRun $scrapeRun, GoogleMapsScraperService $scraper): mixed
    {
        $scraper->cancel($scrapeRun);

        return back()->with('status', 'Cancelación solicitada.');
    }

    public function retry(ScrapeRun $scrapeRun, GoogleMapsScraperService $scraper): mixed
    {
        $scraper->retry($scrapeRun);

        return back()->with('status', 'Búsqueda nuevamente en cola.');
    }

    public function retryImport(ImportRun $importRun): mixed
    {
        abort_unless(in_array($importRun->status, ['failed', 'cancelled']) && ! $importRun->claimed_at, 422);
        if ($importRun->campaign->kill_switch || $importRun->campaign->status === 'paused') {
            return back()->withErrors('Reactivá la campaña antes de reintentar.');
        }
        $importRun->update(['status' => 'queued', 'error_message' => null]);
        ImportLeadFile::dispatch($importRun->id)->onQueue('sales');

        return back()->with('status', 'Importación nuevamente en cola.');
    }

    public function importStatus(ImportRun $importRun): mixed
    {
        return response()->json($importRun->only(['id', 'status', 'raw_count', 'imported_count', 'duplicate_count', 'excluded_count', 'error_count', 'error_message']));
    }
}
