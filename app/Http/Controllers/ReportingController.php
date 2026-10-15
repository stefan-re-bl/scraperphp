<?php

namespace App\Http\Controllers;

use App\Models\Campaign;
use App\Models\Offer;
use App\Services\BackupService;
use App\Services\ExportService;
use App\Services\LeadFilterService;
use App\Services\MetricsService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class ReportingController extends Controller
{
    public function exports(): mixed
    {
        return view('sales.exports', ['datasets' => array_keys(ExportService::DATASETS), 'databasePath' => config('database.connections.sqlite.database'),
            'databaseExists' => File::exists(config('database.connections.sqlite.database')),
            'backups' => File::isDirectory(storage_path('app/private/backups')) ? collect(File::files(storage_path('app/private/backups')))->sortByDesc(fn ($f) => $f->getMTime())->take(20) : collect()]);
    }

    public function exportCsv(Request $request, string $dataset, ExportService $exports, LeadFilterService $filters): mixed
    {
        return $exports->csv($dataset, $filters->fromRequest($request));
    }

    public function exportJson(Request $request, ExportService $exports, LeadFilterService $filters): mixed
    {
        return response()->json($exports->json($filters->fromRequest($request)))->header('Content-Disposition', 'attachment; filename="commercial-export.json"');
    }

    public function exportPhones(Request $request, ExportService $exports, LeadFilterService $filters): mixed
    {
        return $exports->phones($filters->fromRequest($request));
    }

    public function exportAnalytics(Request $request, ExportService $exports, LeadFilterService $filters): mixed
    {
        return $exports->analyticsCsv($filters->fromRequest($request));
    }

    public function exportSqlite(BackupService $backups): mixed
    {
        return response()->download($backups->create(), 'ventas-snapshot.sqlite');
    }

    public function downloadBackup(string $name, BackupService $backups): mixed
    {
        abort_unless(preg_match('/^ventas-[a-zA-Z0-9\-]+\.sqlite$/', $name), 404);
        $path = storage_path('app/private/backups/'.$name);
        abort_unless(is_file($path), 404);
        $backups->verify($path);

        return response()->download($path, $name);
    }

    public function segmentedMetrics(Request $request, MetricsService $metrics): mixed
    {
        $data = $request->validate(['dimension' => 'nullable|in:campaign,channel,offer,segment,city,country,source,language,variant', 'from' => 'nullable|date', 'to' => 'nullable|date|after_or_equal:from', 'campaign' => 'nullable|exists:campaigns,id', 'offer_id' => 'nullable|exists:offers,id', 'rule_version' => 'nullable|string|max:255', 'min_score' => 'nullable|integer|min:0|max:100', 'max_score' => 'nullable|integer|min:0|max:100']);
        $dimension = $data['dimension'] ?? 'campaign';

        return view('sales.metrics', ['dimension' => $dimension, 'rows' => $metrics->segmented($dimension, $data), 'evaluation' => $metrics->scoreEvaluation($data), 'campaigns' => Campaign::orderBy('name')->get(), 'offers' => Offer::orderBy('name')->get()]);
    }

    public function verifyBackup(Request $request, BackupService $backups): mixed
    {
        $request->validate(['backup' => 'required|file|max:204800']);
        $stored = $request->file('backup')->store('restore');
        $path = storage_path('app/private/'.$stored);
        try {
            $summary = $backups->verify($path);
        } catch (\Throwable $exception) {
            File::delete($path);

            return back()->withErrors($exception->getMessage());
        }
        $token = Str::uuid()->toString();
        $request->session()->put('restore.'.$token, ['path' => $path, 'sha256' => $summary['sha256']]);

        return view('sales.restore', ['summary' => $summary, 'token' => $token]);
    }

    public function restoreBackup(Request $request, BackupService $backups): mixed
    {
        $data = $request->validate(['token' => 'required|uuid', 'confirmation' => 'required|in:RESTAURAR']);
        $staged = $request->session()->get('restore.'.$data['token']);
        abort_unless($staged && hash_file('sha256', $staged['path']) === $staged['sha256'], 422);
        Artisan::call('down');
        try {
            $previous = $backups->restore($staged['path']);
        } catch (\Throwable $exception) {
            return back()->withErrors($exception->getMessage());
        } finally {
            Artisan::call('up');
        }
        $request->session()->forget('restore.'.$data['token']);

        return redirect()->route('exports')->with('status', 'Base restaurada. Se conservó un respaldo previo: '.basename($previous));
    }
}
