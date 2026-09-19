<?php

namespace App\Project\Modules\System\Suite\SuiteTickets\Services;

use App\Project\_Src\Helpers;
use App\Project\_Src\ApplicationException;

class SuiteTicketsAssignService
{
    public static function assignTo(array $data, string $uuid, ?\Illuminate\Contracts\Auth\Authenticatable $user, array $params = []): array
    {
        try {
            return self::process($data, $uuid, $user, $params);
        } catch (ApplicationException $e) {
            return Helpers::error($e->getMessage(), 422, $e->getData());
        } catch (\Illuminate\Database\UniqueConstraintViolationException $e) {
            return Helpers::error('This record conflicts with an existing one (duplicate value for a unique field or field combination).', 422);
        } catch (\Exception $e) {
            throw $e;
        }
    }

    protected static function process(array $data, string $uuid, ?\Illuminate\Contracts\Auth\Authenticatable $user, array $params = []): array
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
        // Hand-written body (super-suite module-overlays/): reached through the blueprint's
        // serviceMethod "assignTo" and serviceArgs ["data", "param:uuid", "user"], which is what gives
        // this method its (data, uuid, user) signature instead of the default execute().
        $valid = validator($data, [
            'assignee_id' => 'required|integer|exists:users,id',
        ])->validate();

        $record->update($valid + ['updated_by_id' => $user?->getAuthIdentifier()]);

        return Helpers::success($record->fresh(), 'Ticket assigned successfully');
    }
}
