<?php

namespace App\Jobs;

use App\Models\S3Storage;
use App\Models\ScheduledDatabaseBackup;
use App\Models\ScheduledDatabaseBackupExecution;
use App\Models\Server;
use App\Models\StandalonePostgresql;
use App\Models\Team;
use App\Notifications\Database\BackupFailed;
use App\Notifications\Database\BackupSuccess;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

class DatabaseBackupJob implements ShouldQueue, ShouldBeEncrypted
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public ?Team $team = null;
    public Server $server;
    public ScheduledDatabaseBackup $backup;
    public StandalonePostgresql $database;

    public ?string $container_name = null;
    public ?ScheduledDatabaseBackupExecution $backup_log = null;
    public string $backup_status;
    public ?string $backup_location = null;
    public string $backup_dir;
    public string $backup_file;
    public int $size = 0;
    public ?string $backup_output = null;
    public ?S3Storage $s3 = null;

    public function __construct($backup)
    {
        $this->backup = $backup;
        $this->team = Team::find($backup->team_id);
        $this->database = data_get($this->backup, 'database');
        $this->server = $this->database->destination->server;
        $this->s3 = $this->backup->s3;
    }

    public function middleware(): array
    {
        return [new WithoutOverlapping($this->backup->id)];
    }

    public function uniqueId(): int
    {
        return $this->backup->id;
    }

    public function handle(): void
    {
        try {
            $status = Str::of(data_get($this->database, 'status'));
            if (!$status->startsWith('running') && $this->database->id !== 0) {
                ray('database not running');
                return;
            }
            $this->container_name = $this->database->uuid;
            $this->backup_dir = backup_dir() . "/databases/" . Str::of($this->team->name)->slug() . '-' . $this->team->id . '/' . $this->container_name;

            if ($this->database->name === 'coolify-db') {
                $this->container_name = "coolify-db";
                $ip = Str::slug($this->server->ip);
                $this->backup_dir = backup_dir() . "/coolify" . "/coolify-db-$ip";
            }
            $this->backup_file = "/pg_dump-" . Carbon::now()->timestamp . ".dump";
            $this->backup_location = $this->backup_dir . $this->backup_file;

            $this->backup_log = ScheduledDatabaseBackupExecution::create([
                'filename' => $this->backup_location,
                'scheduled_database_backup_id' => $this->backup->id,
            ]);
            if ($this->database->type() === 'standalone-postgresql') {
                $this->backup_standalone_postgresql();
            }
            $this->calculate_size();
            $this->remove_old_backups();
            if ($this->backup->save_s3) {
                $this->upload_to_s3();
            }
            $this->save_backup_logs();
        } catch (\Throwable $e) {
            ray($e->getMessage());
            send_internal_notification('DatabaseBackupJob failed with: ' . $e->getMessage());
            throw $e;
        }
    }

    private function backup_standalone_postgresql(): void
    {
        try {
            ray($this->backup_dir);
            $commands[] = "mkdir -p " . $this->backup_dir;
            $commands[] = "docker exec $this->container_name pg_dump -Fc -U {$this->database->postgres_user} > $this->backup_location";

            $this->backup_output = instant_remote_process($commands, $this->server);

            $this->backup_output = trim($this->backup_output);

            if ($this->backup_output === '') {
                $this->backup_output = null;
            }

            ray('Backup done for ' . $this->container_name . ' at ' . $this->server->name . ':' . $this->backup_location);

            $this->backup_status = 'success';
            $this->team->notify(new BackupSuccess($this->backup, $this->database));
        } catch (\Throwable $e) {
            $this->backup_status = 'failed';
            $this->add_to_backup_output($e->getMessage());
            ray('Backup failed for ' . $this->container_name . ' at ' . $this->server->name . ':' . $this->backup_location . '\n\nError:' . $e->getMessage());
            $this->team->notify(new BackupFailed($this->backup, $this->database, $this->backup_output));
        } finally {
            $this->backup_log->update([
                'status' => $this->backup_status,
            ]);
        }
    }

    private function add_to_backup_output($output): void
    {
        if ($this->backup_output) {
            $this->backup_output = $this->backup_output . "\n" . $output;
        } else {
            $this->backup_output = $output;
        }
    }

    private function calculate_size(): void
    {
        $this->size = instant_remote_process(["du -b $this->backup_location | cut -f1"], $this->server);
    }

    private function remove_old_backups(): void
    {
        if ($this->backup->number_of_backups_locally === 0) {
            $deletable = $this->backup->executions()->where('status', 'success');
        } else {
            $deletable = $this->backup->executions()->where('status', 'success')->orderByDesc('created_at')->skip($this->backup->number_of_backups_locally);
        }
        foreach ($deletable->get() as $execution) {
            delete_backup_locally($execution->filename, $this->server);
            $execution->delete();
        }
    }

    private function upload_to_s3(): void
    {
        try {
            if (is_null($this->s3)) {
                return;
            }
            $key = $this->s3->key;
            $secret = $this->s3->secret;
            //            $region = $this->s3->region;
            $bucket = $this->s3->bucket;
            $endpoint = $this->s3->endpoint;

            $commands[] = "docker run --pull=always -d --network {$this->database->destination->network} --name backup-of-{$this->backup->uuid} --rm -v $this->backup_location:$this->backup_location:ro ghcr.io/coollabsio/coolify-helper";
            $commands[] = "docker exec backup-of-{$this->backup->uuid} mc config host add temporary {$endpoint} $key $secret";
            $commands[] = "docker exec backup-of-{$this->backup->uuid} mc cp $this->backup_location temporary/$bucket{$this->backup_dir}/";
            instant_remote_process($commands, $this->server);
            $this->add_to_backup_output('Uploaded to S3.');
            ray('Uploaded to S3. ' . $this->backup_location . ' to s3://' . $bucket . $this->backup_dir);
        } catch (\Throwable $e) {
            $this->add_to_backup_output($e->getMessage());
            ray($e->getMessage());
        } finally {
            $command = "docker rm -f backup-of-{$this->backup->uuid}";
            instant_remote_process([$command], $this->server);
        }
    }

    private function save_backup_logs(): void
    {
        $this->backup_log->update([
            'status' => $this->backup_status,
            'message' => $this->backup_output,
            'size' => $this->size,
        ]);
    }
}
