<?php

namespace App\Project\Modules\System\Suite\SuiteTickets\Services;

use App\Project\_Src\Helpers;
use App\Project\_Src\ApplicationException;

class SuiteTicketsEscalateService
{
    public static function execute(array $data, string $uuid, array $params = []): array
    {
        try {
            return self::process($data, $uuid, $params);
        } catch (ApplicationException $e) {
            return Helpers::error($e->getMessage(), 422, $e->getData());
        } catch (\Illuminate\Database\UniqueConstraintViolationException $e) {
            return Helpers::error('This record conflicts with an existing one (duplicate value for a unique field or field combination).', 422);
        } catch (\Exception $e) {
            throw $e;
        }
    }

    protected static function process(array $data, string $uuid, array $params = []): array
    {
        // Record scope seam: an app whose BaseModel defines applyRecordScope() narrows this
        // lookup to the rows the acting user may reach, so a uuid outside their reach 404s here.
        $recordQuery = \App\Project\Modules\System\Suite\SuiteTickets\SuiteTicketsModel::query();
        if (method_exists(\App\Project\Modules\System\Suite\SuiteTickets\SuiteTicketsModel::class, 'applyRecordScope')) {
            $recordQuery = \App\Project\Modules\System\Suite\SuiteTickets\SuiteTicketsModel::applyRecordScope($recordQuery);
        }
        $record = $recordQuery->where('uuid', $uuid)->first();
        if (!$record) {
            abort(404, 'Record not found');
        }
        // Hand-written body (super-suite module-overlays/): the generated service is a write-once stub
        // with no effect, so nothing could assert that the wizard's input reached the record.
        $valid = validator($data, [
            'priority'    => 'required|in:low,normal,high',
            'assignee_id' => 'required|integer|exists:users,id',
            'reason'      => 'required|string|max:500',
        ])->validate();

        $record->update($valid + ['updated_by_id' => \Illuminate\Support\Facades\Auth::id()]);

        return Helpers::success($record->fresh(), 'Ticket escalated successfully');
    }
}
