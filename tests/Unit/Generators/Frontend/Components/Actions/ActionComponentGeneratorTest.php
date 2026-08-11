<?php

namespace Blutrixx\GeneratorEngine\Tests\Unit\Generators\Frontend\Components\Actions;

use Blutrixx\GeneratorEngine\Generators\Frontend\Components\Actions\ActionComponentGenerator;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Regression coverage for ActionComponentGenerator::buildEndpointExpression().
 *
 * Bug (v2.51.2 follow-up): when an action's operations left `endpoint.path`
 * unset, the frontend default independently derived
 * "/{module}/{params}/{action}" while RoutesGenerator::generateActionRoutes()
 * registers "/{module}/{action}/{params}/{op}" on the backend -- a different
 * segment order AND a missing trailing operation segment, so the generated
 * form POSTed to a route the backend never registers (a guaranteed 404) for
 * every action that doesn't set endpoint.path explicitly.
 *
 * Fix: the frontend default now derives from the exact same shape the
 * backend registers, keyed off the specific operation ('create'/'edit'/
 * 'view'/'delete'/'list', in that lookup order) that matched.
 *
 * @see \Blutrixx\GeneratorEngine\Generators\Backend\Routes\RoutesGenerator::generateActionRoutes()
 */
class ActionComponentGeneratorTest extends TestCase
{
    private function makeGenerator(): TestActionComponentGenerator
    {
        $ref = new ReflectionClass(TestActionComponentGenerator::class);

        /** @var TestActionComponentGenerator $generator */
        $generator = $ref->newInstanceWithoutConstructor();

        return $generator;
    }

    public function test_default_endpoint_mirrors_backend_route_shape_for_matched_operation(): void
    {
        $generator = $this->makeGenerator();

        $result = $generator->callBuildEndpointExpression(
            [
                'operations' => ['edit' => ['enabled' => true]],
                'urlParams' => ['uuid'],
            ],
            'orders',
            'cancel'
        );

        $this->assertSame('/orders/cancel/${props.uuid}/edit', $result);
    }

    public function test_default_endpoint_with_no_url_params(): void
    {
        $generator = $this->makeGenerator();

        $result = $generator->callBuildEndpointExpression(
            ['operations' => ['create' => ['enabled' => true]]],
            'quotations',
            'activate'
        );

        $this->assertSame('/quotations/activate/create', $result);
    }

    public function test_operation_lookup_order_prefers_create_over_later_operations(): void
    {
        $generator = $this->makeGenerator();

        $result = $generator->callBuildEndpointExpression(
            [
                'operations' => [
                    'view' => ['enabled' => true],
                    'create' => ['enabled' => true],
                ],
            ],
            'expenses',
            'approve'
        );

        $this->assertStringEndsWith('/create', $result);
    }

    public function test_explicit_endpoint_path_is_used_verbatim_over_the_default(): void
    {
        $generator = $this->makeGenerator();

        $result = $generator->callBuildEndpointExpression(
            [
                'operations' => [
                    'edit' => [
                        'enabled' => true,
                        'endpoint' => ['path' => '/users/{uuid}/force-reset-password'],
                    ],
                ],
            ],
            'users',
            'forceResetPassword'
        );

        $this->assertSame('/users/${props.uuid}/force-reset-password', $result);
    }
}

/**
 * Minimal concrete subclass exposing the protected method under test.
 * Named (not anonymous) so it can be built via
 * ReflectionClass::newInstanceWithoutConstructor().
 */
class TestActionComponentGenerator extends ActionComponentGenerator
{
    public function callBuildEndpointExpression(array $action, string $moduleRoute, string $actionRoute): string
    {
        return $this->buildEndpointExpression($action, $moduleRoute, $actionRoute);
    }
}
