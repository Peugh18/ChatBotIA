<?php

namespace App\Console\Commands;

use App\Services\Integration\RomaApiService;
use Illuminate\Console\Command;

class SyncRomaMessagesCommand extends Command
{
    protected $signature = 'roma:sync-messages {--limit=50 : Cantidad máxima desde roma-api}';

    protected $description = 'Importa mensajes desde GET /api/messages (roma-api / Supabase) al CRM';

    public function handle(RomaApiService $romaApi): int
    {
        if (! $romaApi->isEnabled()) {
            $this->error('ROMA_API_ENABLED=false o falta ROMA_API_URL en .env');

            return self::FAILURE;
        }

        $result = $romaApi->pullLatestMessages((int) $this->option('limit'));

        if (isset($result['error'])) {
            $this->error($result['error']);

            return self::FAILURE;
        }

        $this->info("Total en API: {$result['total']}");
        $this->info("Importados: {$result['imported']}");
        $this->info("Omitidos (duplicados): {$result['skipped']}");

        return self::SUCCESS;
    }
}
