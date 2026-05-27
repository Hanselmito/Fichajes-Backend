<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CheckMissingCheckinsCommand extends Command
{
    protected $signature = 'legacy:check-missing-checkins {--days= : Override auto-confirm threshold in days}';

    protected $description = 'Auto-confirms stale pending records and executes the legacy missing checkins procedure';

    public function handle(): int
    {
        $days = (int) ($this->option('days') ?: env('LEGACY_AUTO_CONFIRM_PENDING_RECORDS_DAYS', 5));
        $days = max($days, 0);

        Log::info('===== INICIO VERIFICACION FICHAJES =====');

        try {
            $autoConfirmed = 0;

            if ($days > 0) {
                $autoConfirmed = DB::update(
                    'UPDATE records
                     SET confirmed = 1
                     WHERE COALESCE(confirmed, 0) = 0
                       AND COALESCE(created_at, `timestamp`) <= DATE_SUB(NOW(), INTERVAL ? DAY)',
                    [$days]
                );
            }

            Log::info(sprintf(
                'Fichajes pendientes auto-confirmados: %d (umbral: %d dias)',
                $autoConfirmed,
                $days
            ));

            DB::statement('CALL check_missing_checkins()');
            Log::info('Procedimiento check_missing_checkins() ejecutado correctamente');

            $count = DB::table('notifications')
                ->whereDate('created_at', today())
                ->whereIn('type', ['missed_checkin', 'employee_missed_checkin', 'missed_checkout'])
                ->count();

            Log::info(sprintf('Total de notificaciones de fichaje hoy: %d', $count));
            Log::info('===== FIN VERIFICACION (OK) =====');

            $this->info(sprintf('OK. Auto-confirmados: %d. Notificaciones hoy: %d.', $autoConfirmed, $count));

            return self::SUCCESS;
        } catch (\Throwable $exception) {
            Log::error('ERROR: '.$exception->getMessage());
            Log::info('===== FIN VERIFICACION (ERROR) =====');

            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }
}