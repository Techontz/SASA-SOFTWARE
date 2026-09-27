<?php

namespace App\Domain\Reporting;

final class ReportSection
{
    public const TYPE_KPIS = 'kpis';

    public const TYPE_TABLE = 'table';

    public const TYPE_TEXT = 'text';

    public function __construct(
        public readonly string $title,
        public readonly string $type = self::TYPE_TABLE,
        public readonly ?string $description = null,
        public readonly array $kpis = [],
        public readonly array $columns = [],
        public readonly array $rows = [],
        public readonly ?string $text = null,
    ) {}

    public static function kpis(string $title, array $kpis, ?string $description = null): self
    {
        return new self($title, self::TYPE_KPIS, $description, kpis: $kpis);
    }

    public static function table(string $title, array $columns, array $rows, ?string $description = null): self
    {
        return new self($title, self::TYPE_TABLE, $description, columns: $columns, rows: $rows);
    }

    public static function text(string $title, string $text): self
    {
        return new self($title, self::TYPE_TEXT, text: $text);
    }
}
