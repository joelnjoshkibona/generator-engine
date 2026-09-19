<?php

namespace Blutrixx\GeneratorEngine\Helpers;

/**
 * An action's service is write-once, so developers reshape its public entry
 * point (NJIWA's MessagesSendService has both `execute(ApiKeysModel, array,
 * ?string)` for the public API and `sendFromConsole(array $data)` for the
 * console, kept by hand). The controller method is regenerated on every
 * --force and always emitted `{Module}{Action}Service::execute($request->
 * all()[, ...])` -- so a --force produced a controller calling a method
 * that no longer exists, or with the wrong arguments: a runtime error on
 * the first click, with no warning while generating.
 *
 * `serviceMethod`/`serviceArgs` on the action config record the real call
 * shape. This class is the single place that resolves, validates and
 * renders it -- both ControllerGenerator and ActionServiceGenerator call
 * resolve() before writing anything, so a typo fails loudly at generation
 * time instead of at runtime.
 */
final class ActionServiceInvocation
{
    private const VOCABULARY = ['data', 'request', 'user'];
    private const RESERVED_PARAM_NAMES = ['data', 'request', 'user', 'params'];

    /**
     * @return array{method: string, args: list<string>, declared: bool}
     */
    public static function resolve(string $actionKey, array $action): array
    {
        $urlParams = $action['urlParams'] ?? [];

        $rawMethod = $action['serviceMethod'] ?? null;
        $method = (is_string($rawMethod) && $rawMethod !== '') ? $rawMethod : 'execute';

        $rawArgs = $action['serviceArgs'] ?? null;
        $args = $rawArgs ?? array_merge(['data'], array_map(fn ($p) => "param:{$p}", $urlParams));

        $declared = ($rawMethod !== null && $rawMethod !== '') || $rawArgs !== null;

        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $method)) {
            throw new \InvalidArgumentException("Action '{$actionKey}': serviceMethod " . json_encode($method) . ' is not a valid PHP method name');
        }
        if (strtolower($method) === 'process') {
            throw new \InvalidArgumentException("Action '{$actionKey}': serviceMethod " . json_encode($method) . ' collides with the protected process() method every generated action service declares; choose another name');
        }

        if (!is_array($args) || !array_is_list($args) || !array_reduce($args, fn ($carry, $v) => $carry && is_string($v), true)) {
            throw new \InvalidArgumentException("Action '{$actionKey}': serviceArgs must be a JSON array of strings, e.g. [\"data\", \"param:uuid\"]");
        }

        $seen = [];
        foreach ($args as $token) {
            if (isset($seen[$token])) {
                throw new \InvalidArgumentException("Action '{$actionKey}': serviceArgs lists \"{$token}\" more than once");
            }
            $seen[$token] = true;
        }

        foreach ($args as $token) {
            if (in_array($token, self::VOCABULARY, true)) {
                continue;
            }
            if (str_starts_with($token, 'param:')) {
                $name = substr($token, 6);
                if (!in_array($name, $urlParams, true)) {
                    $list = empty($urlParams) ? 'none' : implode(', ', $urlParams);
                    throw new \InvalidArgumentException("Action '{$actionKey}': serviceArgs entry \"{$token}\" names no entry in urlParams ({$list})");
                }
                if (in_array($name, self::RESERVED_PARAM_NAMES, true)) {
                    throw new \InvalidArgumentException("Action '{$actionKey}': serviceArgs entry \"{$token}\": the url param name \"{$name}\" is reserved (data, request, user, params); rename the url param");
                }
                continue;
            }

            $list = empty($urlParams) ? 'none' : implode(', ', $urlParams);
            $paramExamples = empty($urlParams) ? '' : (' param:' . implode(', param:', $urlParams));
            throw new \InvalidArgumentException("Action '{$actionKey}': serviceArgs entry \"{$token}\" is not one of: data, request, user, param:<urlParam> (urlParams: {$list})");
        }

        return ['method' => $method, 'args' => array_values($args), 'declared' => $declared];
    }

    public static function controllerArguments(array $resolved): string
    {
        return implode(', ', array_map([self::class, 'controllerArgumentFor'], $resolved['args']));
    }

    public static function serviceParameters(array $resolved): string
    {
        $parts = array_map([self::class, 'serviceParameterFor'], $resolved['args']);
        $parts[] = 'array $params = []';

        return implode(', ', $parts);
    }

    public static function processArguments(array $resolved): string
    {
        $parts = array_map([self::class, 'processArgumentFor'], $resolved['args']);
        $parts[] = '$params';

        return implode(', ', $parts);
    }

    public static function errors(string $actionKey, array $action): array
    {
        try {
            self::resolve($actionKey, $action);

            return [];
        } catch (\InvalidArgumentException $e) {
            return [$e->getMessage()];
        }
    }

    private static function controllerArgumentFor(string $token): string
    {
        return match (true) {
            $token === 'data' => '$request->all()',
            $token === 'request' => '$request',
            $token === 'user' => '$request->user()',
            default => '$' . substr($token, 6),
        };
    }

    private static function serviceParameterFor(string $token): string
    {
        return match (true) {
            $token === 'data' => 'array $data',
            $token === 'request' => '\Illuminate\Http\Request $request',
            $token === 'user' => '?\Illuminate\Contracts\Auth\Authenticatable $user',
            default => 'string $' . substr($token, 6),
        };
    }

    private static function processArgumentFor(string $token): string
    {
        return match (true) {
            $token === 'data', $token === 'request', $token === 'user' => '$' . $token,
            default => '$' . substr($token, 6),
        };
    }
}
