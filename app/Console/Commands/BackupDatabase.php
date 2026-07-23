<?php

namespace App\Console\Commands;

use App\Models\BackupRecord;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Laravel-native equivalent of the master project file's
 * pos_backup_restore.sh `backup`/`verify` commands (Part F.3) — same
 * underlying tool (mysqldump, --single-transaction/--routines/--triggers/
 * --events so scheduled jobs and stored logic survive a restore), just
 * invoked from Artisan instead of a standalone shell script, and recording
 * a BackupRecord row so Settings > Backup & Restore has something real to
 * show instead of an empty table.
 *
 * Off-host copying (the shell script's POS_BACKUP_OFFHOST_DEST) is
 * deliberately not wired here — which S3 bucket/destination to use is a
 * business decision the master file itself says needs a human owner, not
 * something to guess at. The backup lands in storage/app/backups; copying
 * it off-host is the next step whoever owns that decision adds.
 */
class BackupDatabase extends Command
{
    protected $signature = 'pos:backup';

    protected $description = 'Create a logical (mysqldump) backup of the database and record it in backup_records';

    public function handle(): int
    {
        $connection = config('database.connections.'.config('database.default'));
        $database = $connection['database'];
        $filename = "{$database}_".now()->format('Ymd_His').'.sql';
        $relativePath = "backups/{$filename}";

        Storage::disk('local')->makeDirectory('backups');
        $absolutePath = Storage::disk('local')->path($relativePath);

        $binary = env('MYSQLDUMP_PATH', 'mysqldump');

        $this->info("Backing up database '{$database}' to storage/app/{$relativePath}...");

        $process = new Process([
            $binary,
            '--host='.$connection['host'],
            '--port='.$connection['port'],
            '--user='.$connection['username'],
            '--single-transaction',
            '--routines',
            '--triggers',
            '--events',
            '--hex-blob',
            '--default-character-set=utf8mb4',
            '--result-file='.$absolutePath,
            $database,
        ]);
        $process->setTimeout(600);
        $process->setEnv(['MYSQL_PWD' => $connection['password'] ?? '']);

        try {
            $process->mustRun();
        } catch (ProcessFailedException|Throwable $e) {
            $this->error("mysqldump failed: {$e->getMessage()}");

            BackupRecord::create([
                'scope' => 'full',
                'status' => 'failed',
                'file_reference' => $relativePath,
            ]);

            return self::FAILURE;
        }

        if (! $this->verify($absolutePath)) {
            BackupRecord::create([
                'scope' => 'full',
                'status' => 'failed',
                'file_reference' => $relativePath,
            ]);

            return self::FAILURE;
        }

        BackupRecord::create([
            'scope' => 'full',
            'status' => 'completed',
            'file_reference' => $relativePath,
            'completed_at' => now(),
        ]);

        $size = number_format(filesize($absolutePath) / 1024, 1);
        $this->info("Backup completed: {$relativePath} ({$size} KB)");

        return self::SUCCESS;
    }

    /**
     * Same sanity checks as the shell script's cmd_verify: non-empty, and
     * the audit_logs table's CREATE TABLE statement actually made it in —
     * a truncated or permission-denied dump can otherwise look "successful"
     * (mysqldump exits 0) while silently missing tables.
     */
    private function verify(string $path): bool
    {
        if (! is_file($path) || filesize($path) === 0) {
            $this->error('Verify failed: backup file is empty or missing.');

            return false;
        }

        $contents = file_get_contents($path);

        if (! str_contains($contents, 'CREATE TABLE')) {
            $this->error('Verify failed: no CREATE TABLE statements found in the dump.');

            return false;
        }

        if (! str_contains($contents, '`audit_logs`')) {
            $this->error('Verify failed: audit_logs table missing from the dump — backup is incomplete.');

            return false;
        }

        $this->info('Verify OK: backup looks structurally sound.');

        return true;
    }
}
