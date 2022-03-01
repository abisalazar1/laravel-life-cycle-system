<?php

namespace Abix\SystemLifeCycle\Commands;

use Abix\SystemLifeCycle\Jobs\SystemLifeCycleExecuteJob;
use Abix\SystemLifeCycle\Models\SystemLifeCycleModel;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SystemLifeCycleRunCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'system-life-cycle:run';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'System life cycle run';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $batchId = Str::uuid()->toString();

        $sql = "(SELECT id FROM system_life_cycle_stages
        WHERE system_life_cycle_models.system_life_cycle_id = system_life_cycle_stages.system_life_cycle_id
        ORDER BY `order` ASC LIMIT 1)";

        SystemLifeCycleModel::whereNull('system_life_cycle_stage_id')
            ->whereCanBeExecuted()
            ->update([
                'system_life_cycle_stage_id' => DB::raw($sql),
            ]);

        SystemLifeCycleModel::where('state', SystemLifeCycleModel::PENDING_STATE)
            ->whereCanBeExecuted()
            ->update([
                'batch' => $batchId,
                'state' => SystemLifeCycleModel::PROCESSING_STATE,
            ]);

        SystemLifeCycleModel::with(['currentStage'])
            ->select('system_life_cycle_models.*')
            ->where('state', SystemLifeCycleModel::PROCESSING_STATE)
            ->where('batch', $batchId)
            ->whereCanBeExecuted()
            ->chunkById(100, function ($items) {
                foreach ($items as $item) {
                    SystemLifeCycleExecuteJob::dispatch($item)
                        ->delay($item->executes_at ?? now());
                }
            }, 'system_life_cycle_models.id', 'id');

        return 0;
    }
}