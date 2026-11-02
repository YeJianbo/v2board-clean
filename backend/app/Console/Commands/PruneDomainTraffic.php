<?php
namespace App\Console\Commands;
use Illuminate\Console\Command;
use App\Services\DomainTrafficService;
class PruneDomainTraffic extends Command
{
    protected $signature = 'traffic:prune-domains';
    protected $description = 'Remove expired domain traffic aggregates and receipts';
    public function handle(DomainTrafficService $service): int
    {
        $this->info('Removed ' . $service->prune() . ' rows');
        return 0;
    }
}
