<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Import\ImportService;
use App\Http\Controllers\Controller;
use App\Models\ImportJob;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * download template → upload → map → validate → preview → fix → commit
 */
class ImportController extends Controller
{
    public function __construct(private readonly ImportService $imports) {}

    public function template(Request $request, string $entity)
    {
        $this->can();

        $template = $this->imports->template($entity);

        if ($request->input('format') === 'json') {
            return ApiResponse::data($template);
        }

        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle(ucfirst($entity));

        $column = 'A';
        foreach ($template['columns'] as $definition) {
            $sheet->setCellValue($column.'1', $definition['label'].($definition['required'] ? ' *' : ''));
            $sheet->setCellValue($column.'2', $definition['help']);
            $sheet->getStyle($column.'1')->getFont()->setBold(true);
            $sheet->getColumnDimension($column)->setWidth(max(18, min(50, strlen($definition['help']) / 2)));
            $column++;
        }

        $sheet->getStyle('A2:'.$column.'2')->getFont()->setItalic(true)->getColor()->setARGB('FF6B7280');
        $sheet->freezePane('A3');

        $path = tempnam(sys_get_temp_dir(), 'sasa-template').'.xlsx';
        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();

        return response()->download($path, "sasa-{$entity}-template.xlsx")->deleteFileAfterSend();
    }

    public function upload(Request $request)
    {
        $this->can();

        $data = $request->validate([
            'entity' => ['required', Rule::in(ImportService::ENTITIES)],
            'file' => ['required', 'file', 'mimes:csv,txt,xlsx,xls', 'max:20480'],
        ]);

        $job = $this->imports->upload($this->project(), $data['entity'], $request->file('file'));

        return ApiResponse::data([
            'job' => $job,
            'template' => $this->imports->template($data['entity']),
        ], [], 201);
    }

    public function validateJob(Request $request, ImportJob $importJob)
    {
        $this->can();
        abort_unless($importJob->project_id === $this->project()->id, 404);

        $data = $request->validate([
            'mapping' => ['required', 'array'],
        ]);

        $job = $this->imports->validateJob($importJob, $data['mapping']);

        return ApiResponse::data($job, [
            'message' => $job->rows_invalid > 0
                ? "{$job->rows_valid} rows are ready. {$job->rows_invalid} need attention before they can be imported."
                : "All {$job->rows_valid} rows are ready to import.",
        ]);
    }

    public function commit(ImportJob $importJob)
    {
        $this->can();
        abort_unless($importJob->project_id === $this->project()->id, 404);

        $job = $this->imports->commit($importJob, $this->project());

        return ApiResponse::data($job, [
            'message' => "{$job->rows_committed} records imported."
                .($job->rows_invalid > 0 ? " {$job->rows_invalid} rows were skipped and are listed below." : ''),
        ]);
    }

    public function index(Request $request)
    {
        $this->can();

        return ApiResponse::data(
            ImportJob::where('project_id', $this->project()->id)
                ->with('creator:id,name')
                ->latest()
                ->paginate(20)
        );
    }

    public function show(ImportJob $importJob)
    {
        $this->can();
        abort_unless($importJob->project_id === $this->project()->id, 404);

        return ApiResponse::data($importJob->load('creator:id,name'));
    }

    private function can(): void
    {
        abort_unless($this->context()->can('import.run'), 403, 'You do not have permission to import data.');
    }
}
