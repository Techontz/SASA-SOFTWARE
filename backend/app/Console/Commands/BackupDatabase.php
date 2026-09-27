<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;

/**
 * A real, runnable backup — not a claim that backups exist.
 * Writes a compressed dump and prunes beyond the retention window.
 */
class BackupDatabase extends Command
{
    protected $signature = 'sasa:backup {--path=} {--keep=}';

    protected $description = 'Dump the MySQL database to a compressed file and prune old backups.';

    public function handle(): int
    {
        $directory = $this->option('path') ?: base_path(config('sasa.backup.path', env('SASA_BACKUP_PATH', 'storage/app/backups')));
        File::ensureDirectoryExists($directory);

        $filename = sprintf('%s/sasa-%s.sql.gz', rtrim($directory, '/'), now()->format('Y-m-d-His'));

        $command = sprintf(
            'mysqldump --host=%s --port=%s --user=%s %s --single-transaction --quick --routines --events %s | gzip > %s',
            escapeshellarg(config('database.connections.mysql.host')),
            escapeshellarg((string) config('database.connections.mysql.port')),
            escapeshellarg(config('database.connections.mysql.username')),
            config('database.connections.mysql.password')
                ? '--password='.escapeshellarg(config('database.connections.mysql.password'))
                : '',
            escapeshellarg(config('database.connections.mysql.database')),
            escapeshellarg($filename),
        );

        $process = Process::fromShellCommandline($command);
        $process->setTimeout(1800);
        $process->run();

        if (! $process->isSuccessful()) {
            $this->error('Backup failed: '.$process->getErrorOutput());

            return self::FAILURE;
        }

        $this->info('Backup written to '.$filename.' ('.$this->humanSize(filesize($filename)).')');

        $keep = (int) ($this->option('keep') ?: env('SASA_BACKUP_RETENTION_DAYS', 30));
        $pruned = 0;

        foreach (File::files($directory) as $file) {
            if ($file->getMTime() < now()->subDays($keep)->getTimestamp()) {
                File::delete($file->getPathname());
                $pruned++;
            }
        }

        $this->info("{$pruned} backups older than {$keep} days removed.");

        return self::SUCCESS;
    }

    private function humanSize(int|false $bytes): string
    {
        $bytes = (int) $bytes;

        return $bytes >= 1048576 ? round($bytes / 1048576, 1).' MB' : round($bytes / 1024).' KB';
    }
}
