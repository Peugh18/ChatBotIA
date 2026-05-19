<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class ProcessStuckWhatsAppJobs extends Command
{
    protected $signature = 'whatsapp:process-stuck {--limit=50 : Max jobs to process}';

    protected $description = 'Procesa jobs de WhatsApp atascados (cola whatsapp o default)';

    public function handle(): int
    {
        $limit = (int) $this->option('limit');

        $this->info("Procesando hasta {$limit} jobs en colas default y whatsapp...");

        $this->call('queue:work', [
            '--queue' => 'whatsapp,default',
            '--stop-when-empty' => true,
            '--max-jobs' => $limit,
            '--tries' => 3,
        ]);

        $this->info('Listo.');

        return self::SUCCESS;
    }
}
