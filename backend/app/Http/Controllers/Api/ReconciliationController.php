<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Merchant;
use App\Models\ReconciliationException;
use App\Models\ReconciliationRun;
use App\Services\Reconciliation\ClearingFileParserService;
use App\Services\Reconciliation\ReconciliationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReconciliationController extends Controller
{
    public function __construct(
        protected ReconciliationService $reconciliationService,
        protected ClearingFileParserService $fileParser
    ) {}

    /**
     * List all reconciliation batch audit runs.
     */
    public function index(Request $request): JsonResponse
    {
        $query = ReconciliationRun::query();

        if ($request->filled('provider')) {
            $query->where('provider', strtoupper($request->query('provider')));
        }

        if ($request->filled('status')) {
            $query->where('status', strtoupper($request->query('status')));
        }

        $runs = $query->orderBy('created_at', 'desc')->get();

        return response()->json([
            'status' => 'success',
            'data' => $runs->map(fn($r) => $this->transformRun($r)),
        ]);
    }

    /**
     * View detailed reconciliation run with exceptions.
     */
    public function show(Request $request, string $id): JsonResponse
    {
        $run = ReconciliationRun::with('exceptions')->where('id', $id)->first();

        if (!$run) {
            return response()->json([
                'error' => 'not_found',
                'message' => "Reconciliation run [{$id}] not found.",
            ], 404);
        }

        return response()->json([
            'status' => 'success',
            'data' => $this->transformRun($run, true),
        ]);
    }

    /**
     * Ingest clearing report and execute two-way automated reconciliation.
     */
    public function process(Request $request): JsonResponse
    {
        /** @var Merchant $merchant */
        $merchant = $request->attributes->get('merchant');
        $provider = $request->input('provider', 'SIMULATED_GATEWAY');
        $date = $request->input('date', now()->toDateString());

        $providerRecords = [];
        $sourceFileName = null;

        // 1. Uploaded File
        if ($request->hasFile('file')) {
            $file = $request->file('file');
            $sourceFileName = $file->getClientOriginalName();
            $content = file_get_contents($file->getRealPath());
            $format = str_ends_with(strtolower($sourceFileName), '.json') ? 'json' : 'csv';
            $providerRecords = $this->fileParser->parse($content, $format);
        }
        // 2. Raw Text Content (CSV or JSON in request body)
        elseif ($request->filled('content')) {
            $content = $request->input('content');
            $format = $request->input('format', 'auto');
            $sourceFileName = $request->input('file_name', 'pasted_clearing_report.' . ($format === 'json' ? 'json' : 'csv'));
            $providerRecords = $this->fileParser->parse($content, $format);
        }
        // 3. Structured Records JSON Array
        elseif ($request->isJson() && $request->has('records')) {
            $recordsInput = $request->input('records');
            $sourceFileName = 'direct_api_payload.json';
            $providerRecords = $this->fileParser->parse(json_encode($recordsInput), 'json');
        } else {
            return response()->json([
                'error' => 'invalid_input',
                'message' => 'Please provide a clearing report via file upload [file], raw string [content], or JSON array [records].',
            ], 422);
        }

        if (empty($providerRecords)) {
            return response()->json([
                'error' => 'empty_clearing_file',
                'message' => 'The provided clearing file contains no valid transaction rows.',
            ], 422);
        }

        $run = $this->reconciliationService->processReconciliation(
            merchant: $merchant,
            providerRecords: $providerRecords,
            provider: $provider,
            sourceFileName: $sourceFileName,
            reconciliationDate: $date
        );

        return response()->json([
            'status' => 'success',
            'message' => "Reconciliation run [{$run->run_reference}] completed successfully.",
            'data' => $this->transformRun($run, true),
        ], 201);
    }

    /**
     * Generate realistic simulated provider clearing data for instant testing.
     */
    public function generateSampleFile(Request $request): JsonResponse
    {
        /** @var Merchant $merchant */
        $merchant = $request->attributes->get('merchant');
        $format = $request->input('format', 'csv');
        $autoRun = $request->boolean('auto_run', false);

        $clearingContent = $this->reconciliationService->generateSampleClearingFile($merchant, $format);

        if ($autoRun) {
            $parsed = $this->fileParser->parse($clearingContent, $format);
            $run = $this->reconciliationService->processReconciliation(
                merchant: $merchant,
                providerRecords: $parsed,
                provider: 'SIMULATED_GATEWAY',
                sourceFileName: 'simulated_settlement_' . date('Ymd') . '.' . $format,
                reconciliationDate: now()->toDateString()
            );

            return response()->json([
                'status' => 'success',
                'message' => 'Simulated clearing report generated and audited.',
                'raw_content' => $clearingContent,
                'data' => $this->transformRun($run, true),
            ], 201);
        }

        return response()->json([
            'status' => 'success',
            'format' => $format,
            'file_name' => 'simulated_provider_clearing_' . date('Ymd') . '.' . $format,
            'content' => $clearingContent,
        ]);
    }

    /**
     * List exceptions across runs with status/type filtering.
     */
    public function exceptions(Request $request): JsonResponse
    {
        $query = ReconciliationException::with(['run', 'transaction']);

        if ($request->filled('status')) {
            $query->where('status', strtoupper($request->query('status')));
        }

        if ($request->filled('type')) {
            $query->where('exception_type', strtoupper($request->query('type')));
        }

        if ($request->filled('run_id')) {
            $query->where('reconciliation_run_id', $request->query('run_id'));
        }

        $exceptions = $query->orderBy('created_at', 'desc')->limit(100)->get();

        return response()->json([
            'status' => 'success',
            'data' => $exceptions->map(fn($e) => $this->transformException($e)),
        ]);
    }

    /**
     * Triage or resolve a reconciliation exception.
     */
    public function resolveException(Request $request, string $id): JsonResponse
    {
        $request->validate([
            'action' => 'required|string|in:RESOLVED,INVESTIGATING,WRITTEN_OFF,FORCE_SUCCESS',
            'notes' => 'nullable|string|max:1000',
        ]);

        $exception = ReconciliationException::with(['transaction'])->where('id', $id)->first();

        if (!$exception) {
            return response()->json([
                'error' => 'not_found',
                'message' => "Reconciliation exception [{$id}] not found.",
            ], 404);
        }

        $user = $request->user();

        $resolved = $this->reconciliationService->resolveException(
            exception: $exception,
            action: $request->input('action'),
            notes: $request->input('notes'),
            userId: $user?->id
        );

        return response()->json([
            'status' => 'success',
            'message' => "Exception [{$exception->id}] transitioned to [{$resolved->status}].",
            'data' => $this->transformException($resolved),
        ]);
    }

    protected function transformRun(ReconciliationRun $run, bool $includeExceptions = false): array
    {
        $total = $run->total_internal_records + $run->total_provider_records;
        $matchRate = ($run->matched_records + $run->mismatched_records > 0)
            ? round(($run->matched_records / ($run->matched_records + $run->mismatched_records)) * 100, 1)
            : 100.0;

        $data = [
            'id' => $run->id,
            'run_reference' => $run->run_reference,
            'provider' => $run->provider,
            'source_file' => $run->source_file,
            'reconciliation_date' => $run->reconciliation_date->toDateString(),
            'total_internal_records' => (int) $run->total_internal_records,
            'total_provider_records' => (int) $run->total_provider_records,
            'matched_records' => (int) $run->matched_records,
            'mismatched_records' => (int) $run->mismatched_records,
            'match_rate_percent' => $matchRate,
            'matched_volume_minor' => (int) $run->matched_volume_minor,
            'mismatched_volume_minor' => (int) $run->mismatched_volume_minor,
            'status' => $run->status,
            'summary' => $run->summary,
            'created_at' => $run->created_at->toIso8601String(),
        ];

        if ($includeExceptions && $run->relationLoaded('exceptions')) {
            $data['exceptions'] = $run->exceptions->map(fn($e) => $this->transformException($e));
        }

        return $data;
    }

    protected function transformException(ReconciliationException $e): array
    {
        return [
            'id' => $e->id,
            'reconciliation_run_id' => $e->reconciliation_run_id,
            'run_reference' => $e->run?->run_reference,
            'transaction_id' => $e->transaction_id,
            'internal_reference' => $e->internal_reference,
            'provider_reference' => $e->provider_reference,
            'exception_type' => $e->exception_type,
            'internal_amount_minor' => $e->internal_amount_minor !== null ? (int) $e->internal_amount_minor : null,
            'provider_amount_minor' => $e->provider_amount_minor !== null ? (int) $e->provider_amount_minor : null,
            'internal_status' => $e->internal_status,
            'provider_status' => $e->provider_status,
            'discrepancy_details' => $e->discrepancy_details,
            'status' => $e->status,
            'resolution_notes' => $e->resolution_notes,
            'resolved_at' => $e->resolved_at?->toIso8601String(),
            'created_at' => $e->created_at->toIso8601String(),
        ];
    }
}
