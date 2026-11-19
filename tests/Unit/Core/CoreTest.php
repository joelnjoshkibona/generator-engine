<?php

namespace Blutrixx\GeneratorEngine\Tests\Unit\Core;

use Blutrixx\GeneratorEngine\Core\ColumnTypes;
use Blutrixx\GeneratorEngine\Core\ListSpec;
use Blutrixx\GeneratorEngine\Core\ModuleSpec;
use Blutrixx\GeneratorEngine\Core\SampleValues;
use Blutrixx\GeneratorEngine\Core\ValidationRules;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/** The target-neutral core: a module's definition read into data, with nothing about where files go. */
class CoreTest extends TestCase
{
    public static function tasks(array $over = []): ModuleSpec
    {
        return ModuleSpec::fromArray($over + [
            'module_name' => 'Tasks', 'module_group_name' => 'Work', 'table_name' => 'tasks', 'scope' => 'location',
            'label' => 'Task', 'label_column' => 'name',
            'workflow' => ['key' => 'task', 'statuses' => [['code' => 'open', 'label' => 'Open']]],
            'columns' => [
                ['name' => 'name', 'type' => 'string', 'length' => 200, 'unique' => true],
                ['name' => 'description', 'type' => 'text', 'nullable' => true],
                ['name' => 'task_status_id', 'type' => 'workflow', 'label' => 'Status'],
                ['name' => 'due_date', 'type' => 'date', 'nullable' => true, 'label' => 'Due'],
                ['name' => 'assignee_id', 'type' => 'foreignId', 'relatedModule' => 'Users', 'nullable' => true],
                ['name' => 'project_id', 'type' => 'foreignId', 'relatedModule' => 'Projects'],
                ['name' => 'created_at', 'type' => 'datetime'],
            ],
        ]);
    }

    public function test_column_kinds_casts_and_names(): void
    {
        $this->assertSame('foreign', ColumnTypes::kind(['name' => 'x_id', 'type' => 'bigInteger', 'relatedModule' => 'Users']));
        $this->assertSame('text', ColumnTypes::kind(['name' => 'x', 'type' => 'longText']));
        $this->assertSame('date:Y-m-d', ColumnTypes::cast(['name' => 'd', 'type' => 'date']));
        $this->assertSame('decimal:3', ColumnTypes::cast(['name' => 'p', 'type' => 'decimal', 'scale' => 3]));
        $this->assertNull(ColumnTypes::cast(['name' => 's', 'type' => 'string']));
        $this->assertSame('parentLocation', ColumnTypes::relationName('parent_location_id'));
        $this->assertSame('Assignee', ColumnTypes::label(['name' => 'assignee_id']));
    }

    public function test_the_spec_leaves_out_the_skeletons_columns_and_refuses_what_it_cannot_generate(): void
    {
        $this->assertSame(['name', 'description', 'task_status_id', 'due_date', 'assignee_id', 'project_id'], array_column(self::tasks()->columns(), 'name'));
        $this->assertSame('tasks', self::tasks()->route());

        foreach ([
            ['scope' => 'team'],
            ['columns' => [['name' => 'x_id', 'type' => 'foreignId']]],
            ['columns' => [['name' => 's_id', 'type' => 'workflow']], 'workflow' => null],
        ] as $bad) {
            try {
                ModuleSpec::fromArray(array_filter($bad + ['module_name' => 'X', 'columns' => []], fn ($v) => $v !== null));
                $this->fail('accepted '.json_encode($bad));
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_the_spec_refuses_what_the_bootbox_target_cannot_write_yet(): void
    {
        $cases = [
            'no label column' => [['name' => 'title', 'type' => 'string']],
            'a nullable label' => [['name' => 'name', 'type' => 'string', 'nullable' => true]],
            'a json column' => [['name' => 'name', 'type' => 'string'], ['name' => 'params', 'type' => 'json']],
        ];
        foreach ($cases as $why => $columns) {
            try {
                ModuleSpec::fromArray(['module_name' => 'X', 'columns' => $columns]);
                $this->fail("accepted {$why}");
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString('generator.json', $e->getMessage(), $why);
            }
        }
        $this->expectExceptionMessage("'delegations' (child tabs (P86, step 4b)) is not in the Bootbox target yet");
        ModuleSpec::fromArray(['module_name' => 'X', 'columns' => [['name' => 'name', 'type' => 'string']], 'delegations' => ['x' => []]]);
    }

    public function test_a_label_column_of_its_own(): void
    {
        $spec = ModuleSpec::fromArray(['module_name' => 'Receipts', 'label_column' => 'receipt_no', 'columns' => [['name' => 'receipt_no', 'type' => 'string', 'length' => 32, 'unique' => true]]]);
        $this->assertSame(['receipt_no', 'created_at', 'uuid', 'id'], ListSpec::filterable($spec));
        $this->assertSame('unique_words', SampleValues::for($spec, $spec->column('receipt_no'))['kind']);
    }

    public function test_rules_for_create_and_edit(): void
    {
        $create = array_column(ValidationRules::for(self::tasks(), 'create', new ArrayModuleResolver), 'rules', 'field');
        $this->assertSame(['required', 'string', 'max:200', ['rule' => 'unique', 'table' => 'tasks', 'column' => 'name', 'scoped' => true, 'ignoreSelf' => false]], $create['name']);
        $this->assertSame(['nullable', 'string', 'max:5000'], $create['description']);
        $this->assertSame(['nullable', 'integer', ['rule' => 'exists', 'table' => 'task_statuses', 'column' => 'id', 'scoped' => true]], $create['task_status_id']);
        $this->assertSame(['nullable', 'date_format:Y-m-d'], $create['due_date']);
        $this->assertSame(['rule' => 'exists', 'table' => 'users', 'column' => 'id', 'scoped' => false, 'module' => 'Users'], $create['assignee_id'][2]);
        $this->assertTrue($create['project_id'][2]['scoped']);

        $edit = array_column(ValidationRules::for(self::tasks(), 'edit', new ArrayModuleResolver), 'rules', 'field');
        $this->assertSame('sometimes', $edit['description'][0]);
        $this->assertSame(['sometimes', 'required', 'integer'], array_slice($edit['task_status_id'], 0, 3));
        $this->assertTrue(end($edit['name'])['ignoreSelf']);
    }

    public function test_the_list_derives_its_allowlists_and_filters_and_a_spec_overrides_them(): void
    {
        $spec = self::tasks();
        $this->assertSame(['name', 'task_status_id', 'assignee_id', 'project_id', 'location_id', 'due_date', 'created_at', 'uuid', 'id'], ListSpec::filterable($spec));
        $this->assertSame(['created_at', 'due_date', 'name', 'id'], ListSpec::sortable($spec));
        $this->assertSame(['name', 'description'], ListSpec::searchable($spec));
        $this->assertSame(['creator', 'assignee:id,uuid,name', 'project:id,uuid,title', 'location:id,uuid,name'], ListSpec::eager($spec, new ArrayModuleResolver));
        $types = array_column(ListSpec::filterFields($spec), 'type', 'key');
        $this->assertSame(['search' => 'text', 'name' => 'text', 'task_status_id' => 'workflow', 'assignee_id' => 'select_paginated', 'project_id' => 'select_paginated', 'location_id' => 'select_paginated', 'due_date' => 'date', 'created_at' => 'date'], $types);

        $given = self::tasks(['features' => ['backend' => ['list' => ['sortableFields' => ['name']]]]]);
        $this->assertSame(['name'], ListSpec::sortable($given));
    }

    public function test_sample_values_by_kind(): void
    {
        $spec = self::tasks();
        $this->assertSame('unique_words', SampleValues::for($spec, $spec->column('name'))['kind']);
        $this->assertSame('future_date', SampleValues::for($spec, $spec->column('due_date'))['kind']);
        $this->assertSame(['module' => 'Projects', 'nullable' => false], SampleValues::for($spec, $spec->column('project_id'))['params']);
    }
}
