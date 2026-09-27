<?php

namespace App\Domain\Reporting;

/**
 * The format-neutral shape of a report. Built once from live data, then
 * rendered to PDF, Word, Excel or CSV — so the same definition run into two
 * formats can never disagree with itself.
 */
final class ReportDocument
{
    /** @var array<int,ReportSection> */
    public array $sections = [];

    public function __construct(
        public readonly string $title,
        public readonly string $subtitle,
        public readonly string $projectName,
        public readonly string $organisationName,
        public readonly string $periodLabel,
        public readonly array $filters,
        public readonly string $generatedBy,
        public readonly string $generatedAt,
        public readonly ?string $logoPath = null,
    ) {}

    public function add(ReportSection $section): self
    {
        $this->sections[] = $section;

        return $this;
    }

    /** Every definition used in this report, reproduced in the footer. */
    public function definitions(): array
    {
        $definitions = [];

        foreach ($this->sections as $section) {
            foreach ($section->kpis as $kpi) {
                if (! empty($kpi['definition'])) {
                    $definitions[$kpi['label']] = $kpi['definition'];
                }
            }
        }

        return $definitions;
    }

    public function filterSummary(): string
    {
        $parts = [];

        foreach ($this->filters as $key => $value) {
            if ($value === null || $value === '' || in_array($key, ['project_id', 'label'], true)) {
                continue;
            }

            $parts[] = ucfirst(str_replace('_', ' ', $key)).': '.(is_array($value) ? implode(', ', $value) : $value);
        }

        return $parts === [] ? 'No filters applied — all records in the period.' : implode(' · ', $parts);
    }
}
