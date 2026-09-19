<?php

namespace App\Project\Modules\System\Suite\SuiteTickets\Services;

use App\Project\_Src\Helpers;
use App\Project\_Src\ApplicationException;

/**
 * Splash data for the "Assign" action.
 *
 * Answers GET /suite-tickets/{uuid}/assign/splash — everything the action's form needs to
 * render before the user has entered anything: option lists, defaults, values derived from the
 * record being acted on. The record's uuid is passed, unlike create's splash, because an action
 * always operates on an existing row and its choices usually depend on that row's state.
 *
 * Write-once: filled in by hand and never regenerated, exactly like the action's own Service.
 */
class SuiteTicketsAssignSplashService
{
    public static function execute(string $uuid, array $data = [])
    {
        try {
            return self::process($uuid, $data);
        } catch (ApplicationException $e) {
            return Helpers::error($e->getMessage(), 422, $e->getData());
        } catch (\Exception $e) {
            throw $e;
        }
    }

    public static function process(string $uuid, array $data = []): array
    {
        // Hand-written body (super-suite module-overlays/): the option list this action's form needs
        // before it opens, plus the row's current assignee as the default.
        $record = \App\Project\Modules\System\Suite\SuiteTickets\SuiteTicketsModel::where('uuid', $uuid)->firstOrFail();

        $splashData = [
            'assignees' => \App\Project\Modules\Core\Users\Users\UsersModel::query()
                ->select(['id', 'name'])->orderBy('name')->limit(50)->get()->toArray(),
            'current_assignee_id' => $record->assignee_id,
        ];

        return Helpers::success($splashData, 'Data fetched successfully');
    }
}
