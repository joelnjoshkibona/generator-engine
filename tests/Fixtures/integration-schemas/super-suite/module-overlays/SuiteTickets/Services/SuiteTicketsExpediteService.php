<?php

namespace App\Project\Modules\System\Suite\SuiteTickets\Services;

use App\Project\_Src\Helpers;
use App\Project\Modules\System\Suite\SuiteTickets\SuiteTicketsModel;

class SuiteTicketsExpediteService
{
    public static function execute(array $data, array $params): array
    {
        // Hand-written body (super-suite module-overlays/): a bulk action that can FAIL per row, so the
        // batch outcome has something in `failed`. A generated stub always succeeds.
        //
        // The rule keys on a value the engine's own generated tests never produce (their rows are factory
        // strings): the generated "bulk action processes all records in filter mode" test asserts a blanket
        // 200, so failing on something a factory row can carry (a priority, a status) breaks it.
        $record = SuiteTicketsModel::where('uuid', $params['uuid'] ?? null)->firstOrFail();

        if ($record->reason === 'BLOCKED') {
            throw new \Exception('Blocked tickets cannot be expedited.');
        }

        $record->update(['priority' => 'high']);

        return Helpers::success($record->fresh(), 'SuiteTickets expedite applied successfully');
    }
}
