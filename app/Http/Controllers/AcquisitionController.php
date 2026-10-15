<?php

namespace App\Http\Controllers;

use App\Jobs\ImportLeadFile;
use App\Models\Campaign;
use App\Models\CampaignQuery;
use App\Models\ImportRun;
use App\Models\ScrapeRun;
use App\Services\CsvSerializer;
use App\Services\GoogleMapsScraperService;
use App\Services\LeadImporter;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

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
            'query' => ['nullable', 'string', 'max:255'], 'location' => ['nullable', 'string', 'max:255'],
        ]);
        $campaign = Campaign::findOrFail($data['campaign_id']);
        $analysis = $importer->analyze($request->file('csv')->getRealPath(), $campaign, [], $data['source'] ?? $campaign->source);
        $run = $importer->enqueue($request->file('csv'), $campaign, $data + ['analysis' => $analysis]);
        $target = $request->filled('redirect_campaign') ? route('campaigns.show', ['campaign' => $campaign->id, 'tab' => 'leads']) : '/leads';

        return redirect($target)->with('status', 'Importación en cola. Revisa su resultado en Adquisición.');
    }

    public function scraper(): mixed
    {
        return view('sales.scraper', [
            'campaigns' => Campaign::with('queries')->latest()->get(),
            'queries' => CampaignQuery::latest()->limit(100)->get(),
            'runs' => ScrapeRun::latest()->limit(50)->get(),
            'imports' => ImportRun::latest()->limit(50)->get(),
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
            'dry_run' => ['sometimes', 'nullable', 'declined'],
        ], ['dry_run.declined' => 'La simulación de búsqueda ya no está disponible. Actualizá la página para iniciar una búsqueda real.']);
        $query = CampaignQuery::findOrFail($data['campaign_query_id']);
        $run = $scraper->start($query->campaign, $query, false);

        return back()->with('status', "Búsqueda #{$run->id} en cola. El progreso se actualiza automáticamente.");
    }

    public function runCampaignAcquisition(Request $request, Campaign $campaign, GoogleMapsScraperService $scraper): mixed
    {
        $data = $request->validate([
            'query' => ['required', 'string', 'max:255'],
            'location' => ['nullable', 'string', 'max:255'],
            'result_limit' => ['required', 'integer', 'min:1', 'max:500'],
            'dry_run' => ['sometimes', 'nullable', 'declined'],
        ], ['dry_run.declined' => 'La simulación de búsqueda ya no está disponible. Actualizá la página para iniciar una búsqueda real.']);
        unset($data['dry_run']);
        $query = CampaignQuery::create($data + ['campaign_id' => $campaign->id, 'status' => 'ready']);
        $run = $scraper->start($campaign, $query, false);

        return redirect()->route('campaigns.show', ['campaign' => $campaign->id, 'tab' => 'acquisition'])
            ->with('status', "Búsqueda #{$run->id} en cola. El progreso se actualiza automáticamente.");
    }

    public function preview(Request $request, LeadImporter $importer): mixed
    {
        $data = $request->validate(['campaign_id' => 'required|exists:campaigns,id', 'csv' => 'required|file|mimes:csv,txt|max:20480', 'source' => 'nullable|in:google_maps,csv_manual,referral,web', 'query' => 'nullable|string|max:255', 'location' => 'nullable|string|max:255']);
        $campaign = Campaign::findOrFail($data['campaign_id']);
        $path = storage_path('app/private/'.$request->file('csv')->store('imports'));
        $token = Str::uuid()->toString();
        $staged = ['path' => $path, 'campaign_id' => $campaign->id, 'source' => $data['source'] ?? $campaign->source, 'query' => $data['query'] ?? null, 'location' => $data['location'] ?? null];
        $analysis = $importer->analyze($path, $campaign, [], $staged['source']);
        $request->session()->put('import.'.$token, $staged + ['analysis' => $analysis]);

        return view('sales.import-preview', ['preview' => $importer->preview($path), 'analysis' => $analysis, 'token' => $token, 'fields' => LeadImporter::FIELDS]);
    }

    public function analyzeImport(Request $request, LeadImporter $importer): mixed
    {
        $data = $request->validate(['token' => 'required|uuid', 'mapping' => 'nullable|array', 'mapping.*' => 'nullable|string|max:255']);
        $staged = $request->session()->get('import.'.$data['token']);
        abort_unless($staged, 404);
        $mapping = array_filter($data['mapping'] ?? [], fn ($column) => filled($column));
        $analysis = $importer->analyze($staged['path'], Campaign::findOrFail($staged['campaign_id']), $mapping, $staged['source']);
        $request->session()->put('import.'.$data['token'], array_replace($staged, ['analysis' => $analysis]));

        return view('sales.import-preview', ['preview' => $importer->preview($staged['path'], $mapping), 'analysis' => $analysis, 'token' => $data['token'], 'fields' => LeadImporter::FIELDS]);
    }

    public function confirmImport(Request $request, LeadImporter $importer): mixed
    {
        $data = $request->validate(['token' => 'required|uuid', 'mapping' => 'nullable|array', 'mapping.*' => 'nullable|string|max:255', 'analysis_hash' => 'nullable|string|size:64']);
        $staged = $request->session()->get('import.'.$data['token']);
        abort_unless($staged, 404);
        $mapping = array_filter($data['mapping'] ?? [], fn ($column) => filled($column));
        $campaign = Campaign::findOrFail($staged['campaign_id']);
        $analysis = $importer->analyze($staged['path'], $campaign, $mapping, $staged['source']);
        if (isset($data['analysis_hash']) && ! hash_equals($analysis['analysis_hash'], $data['analysis_hash'])) {
            throw ValidationException::withMessages(['mapping' => 'El archivo o la asignación cambió. Analiza las columnas antes de confirmar.']);
        }
        if ($analysis['valid'] === 0) {
            throw ValidationException::withMessages(['csv' => 'El archivo no contiene filas válidas con esta asignación.']);
        }
        $run = $importer->enqueuePath($staged['path'], $campaign, ['source' => $staged['source'], 'mapping' => $mapping, 'query' => $staged['query'], 'location' => $staged['location'], 'analysis' => $analysis]);
        $run->update(['file_hash' => $analysis['file_hash']]);
        $request->session()->forget('import.'.$data['token']);

        return redirect()->route('campaigns.show', ['campaign' => $run->campaign_id, 'tab' => 'acquisition'])->with('status', 'Importación en cola. Las filas inválidas se registrarán para descargar su resultado.');
    }

    public function downloadRejections(ImportRun $importRun, LeadImporter $importer): mixed
    {
        abort_unless(is_file($importRun->file_path), 404);
        if ($importRun->file_hash && hash_file('sha256', $importRun->file_path) !== $importRun->file_hash) {
            throw ValidationException::withMessages(['csv' => 'El archivo original cambió. No se pueden reconstruir las filas rechazadas.']);
        }

        return response()->streamDownload(function () use ($importRun, $importer): void {
            $stream = fopen('php://output', 'w');
            fwrite($stream, "\xEF\xBB\xBF");
            foreach ($importer->rejectedRows($importRun) as $row) {
                fputcsv($stream, array_map(CsvSerializer::cell(...), $row), escape: '');
            }
            fclose($stream);
        }, 'importacion-'.$importRun->id.'-rechazados.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
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
        abort_unless(in_array($importRun->status, ['failed', 'cancelled', 'processing'])
            && (! $importRun->claimed_at || $importRun->claimed_at->lessThan(now()->subSeconds(LeadImporter::CLAIM_SECONDS))), 422);
        if ($importRun->campaign->kill_switch || $importRun->campaign->status === 'paused') {
            return back()->withErrors('Reactivá la campaña antes de reintentar.');
        }
        $updated = ImportRun::whereKey($importRun->id)->whereIn('status', ['failed', 'cancelled', 'processing'])
            ->where(fn ($query) => $query->whereNull('claimed_at')->orWhere('claimed_at', '<', now()->subSeconds(LeadImporter::CLAIM_SECONDS)))
            ->update(['status' => 'queued', 'error_message' => null, 'claim_token' => null, 'claimed_at' => null, 'finished_at' => null]);
        abort_unless($updated === 1, 422);
        ImportLeadFile::dispatch($importRun->id)->onQueue('sales');

        return back()->with('status', 'Importación nuevamente en cola.');
    }

    public function importStatus(ImportRun $importRun): mixed
    {
        return response()->json($importRun->only(['id', 'status', 'raw_count', 'imported_count', 'duplicate_count', 'excluded_count', 'error_count', 'error_message']));
    }
}
