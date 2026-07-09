<?php

declare(strict_types=1);

namespace PrettyLinks\GroundLevel\Container;

/**
 * Resolves class dependencies via constructor reflection and docblock annotations.
 *
 * Auto-wires services by reflecting on constructor parameters and matching them
 * to container entries via type hints and @inject annotations.
 */
class Resolver
{
    /**
     * The container instance.
     *
     * @var \PrettyLinks\GroundLevel\Container\Container
     */
    private Container $container;

    /**
     * Classes currently being resolved, used to detect circular dependencies.
     *
     * @var array<string, true>
     */
    private array $resolving = [];

    /**
     * Creates a new Resolver instance.
     *
     * @param \PrettyLinks\GroundLevel\Container\Container $container The container instance.
     */
    public function __construct(Container $container)
    {
        $this->container = $container;
    }

    /**
     * Instantiate a class by auto-wiring its constructor dependencies.
     *
     * @throws \PrettyLinks\GroundLevel\Container\Exception If the class cannot be resolved.
     *
     * @param  string      $class         The class to instantiate.
     * @param  string|null $concreteClass The concrete class if $class is an interface/abstract.
     * @return object
     */
    public function resolve(string $class, ?string $concreteClass = null): object
    {
        $targetClass = $concreteClass ?? $class;

        if (isset($this->resolving[$targetClass])) {
            $chain = implode(' -> ', array_keys($this->resolving)) . ' -> ' . $targetClass;
            throw new Exception("Circular dependency detected: {$chain}");
        }

        if (!class_exists($targetClass)) {
            throw new Exception("Cannot resolve [{$targetClass}]: class does not exist.");
        }

        $this->resolving[$targetClass] = true;

        try {
            $ref         = new \ReflectionClass($targetClass);
            $constructor = $ref->getConstructor();

            if (null === $constructor) {
                return new $targetClass();
            }

            $paramMap = $this->buildParamMap($ref);
            $args     = [];

            foreach ($constructor->getParameters() as $param) {
                $args[] = $this->resolveParameter($param, $paramMap);
            }

            return $ref->newInstanceArgs($args);
        } finally {
            unset($this->resolving[$targetClass]);
        }
    }

    /**
     * Build a map of property name => container ID from @inject annotations.
     *
     * Supports both literal IDs and class constant references:
     * - string literal: `@inject service.prefix`
     * - constant:       `@inject \GroundLevel\Component\ComponentServiceProvider::PARAM_PREFIX`
     *
     * @param  \ReflectionClass $ref The class reflection.
     * @return array<string, string> Map of property name => container ID.
     */
    private function buildParamMap(\ReflectionClass $ref): array
    {
        $map = [];

        foreach ($ref->getProperties() as $prop) {
            $doc = $prop->getDocComment();

            // PL strauss-fixup: opcache.save_comments=0 strips docblocks from the
            // compiled class, so getDocComment() returns false and @inject-based DI
            // silently breaks (fatal on required scalar params like Worker::$prefix
            // on hosts such as Kinsta). Recover the annotation from the on-disk
            // source, which opcache never rewrites.
            if (false === $doc) {
                $doc = self::injectDocFromSource($prop);
            }

            if (false === $doc) {
                continue;
            }

            if (preg_match('/@inject\s+(\S+)/', $doc, $matches)) {
                $map[$prop->getName()] = $this->resolveContainerParamValue($matches[1]);
            }
        }

        return $map;
    }

    /**
     * PL strauss-fixup: recover a property's @inject annotation from the on-disk
     * source when opcache.save_comments=0 has stripped it from the compiled class
     * (ReflectionProperty::getDocComment() === false). The source file is never
     * rewritten by opcache, so re-scanning it yields the original annotation.
     * Cached per file.
     *
     * @param  \ReflectionProperty $prop The property whose annotation to recover.
     * @return string|false A synthetic "@inject <value>" docblock, or false.
     */
    private static function injectDocFromSource(\ReflectionProperty $prop)
    {
        static $cache = [];

        $file = $prop->getDeclaringClass()->getFileName();
        if (false === $file || !is_file($file)) {
            return false;
        }

        if (!isset($cache[$file])) {
            $cache[$file] = [];
            $contents     = (string) file_get_contents($file);
            // Pair each "@inject <value>" with the property declaration that
            // immediately follows its docblock.
            if (
                preg_match_all(
                    '/@inject\s+(\S+).*?\*\/\s*(?:public|protected|private)[^;$]*\$(\w+)/s',
                    $contents,
                    $matches,
                    PREG_SET_ORDER
                )
            ) {
                foreach ($matches as $pair) {
                    $cache[$file][$pair[2]] = $pair[1];
                }
            }
        }

        $name = $prop->getName();

        return isset($cache[$file][$name]) ? '@inject ' . $cache[$file][$name] : false;
    }

    /**
     * Resolve a @inject value to a container ID.
     *
     * If the value contains `::` it is treated as a class constant reference
     * and resolved via PHP's constant() function. Otherwise it is used as-is.
     *
     * @throws \PrettyLinks\GroundLevel\Container\Exception If the constant reference is undefined.
     *
     * @param  string $ref The raw annotation value.
     * @return string The resolved container ID.
     */
    private function resolveContainerParamValue(string $ref): string
    {
        if (strpos($ref, '::') !== false) {
            $fqcn = ltrim($ref, '\\');

            if (!defined($fqcn)) {
                throw new Exception("@inject references undefined constant: {$fqcn}");
            }

            return (string) constant($fqcn);
        }

        return $ref;
    }

    /**
     * Resolve a single constructor parameter.
     *
     * @throws \PrettyLinks\GroundLevel\Container\Exception If the parameter cannot be resolved.
     *
     * @param  \ReflectionParameter  $param    The parameter to resolve.
     * @param  array<string, string> $paramMap Map of property name => container ID.
     * @return mixed
     */
    private function resolveParameter(\ReflectionParameter $param, array $paramMap)
    {
        $type = $param->getType();
        $name = $param->getName();

        // 1. Check if a property with the same name has @inject.
        if (isset($paramMap[$name])) {
            $id = $paramMap[$name];

            if (!$this->container->has($id)) {
                throw new Exception(
                    "@inject on \${$name} references '{$id}' which is not registered in the container."
                );
            }

            return $this->container->get($id);
        }

        // 2. Auto-wire by type hint (services).
        if ($type instanceof \ReflectionNamedType && !$type->isBuiltin()) {
            return $this->container->get($type->getName());
        }

        // 3. Fall back to default value.
        if ($param->isDefaultValueAvailable()) {
            return $param->getDefaultValue();
        }

        // 4. Allow nullable.
        if (null !== $type && $type->allowsNull()) {
            return null;
        }

        throw new Exception(
            "Cannot resolve parameter \${$name} in {$param->getDeclaringClass()->getName()}."
        );
    }
}
