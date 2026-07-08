<?php

declare(strict_types=1);

namespace PrettyLinks\GroundLevel\Package;

use PrettyLinks\GroundLevel\Container\Container;
use PrettyLinks\GroundLevel\Package\Contracts\Configurable;
use PrettyLinks\GroundLevel\Package\Exceptions\RequirementsError;
use PrettyLinks\GroundLevel\Support\Str;
use PrettyLinks\GroundLevel\Support\Concerns\Hookable;

class Bootstrap
{
    use Hookable;

    /**
     * The singleton instance.
     *
     * @var static|null
     */
    protected static ?self $instance = null;

    /**
     * Returns the singleton instance.
     *
     * @return static|null
     */
    public static function instance(): ?self
    {
        return static::$instance;
    }

    /**
     * The package's configuration instance.
     *
     * @var \PrettyLinks\GroundLevel\Package\Contracts\Configurable
     */
    protected Configurable $config;

    /**
     * The package's container instance.
     *
     * @var \PrettyLinks\GroundLevel\Container\Container
     */
    protected Container $container;

    /**
     * Constructor.
     *
     * Sets up the package and initializes the container.
     *
     * Action steps:
     *   1. Initialize the package's configuration instance ($this->config).
     *   2. Check requirements.
     *   3. Call {@see Bootstrap::boot()}.
     *   4. Initialize the container ($this->container).
     *   5. Call {@see Bootstrap::init()}.
     *   6. Add hooks via {@see Hookable::addHooks()}.
     *   7. Boot service providers via {@see Container::boot()}.
     *   8. Call {@see Bootstrap::loaded()}.
     *
     * @param \PrettyLinks\GroundLevel\Package\Contracts\Configurable $config The package's configuration instance.
     */
    public function __construct(Configurable $config)
    {
        // Guard against multiple instances being created.
        if (null === static::$instance) {
            static::$instance = $this;
        }

        $this->config = $config;

        $this->assertMeetsPHPVersionRequirements();
        $this->assertMeetsWPVersionRequirements();

        $this->boot();

        $this->container = (new Container())
            ->singleton(
                get_class($config),
                static function () use ($config): Configurable {
                    return $config;
                }
            )
            ->parameters(
                $this->configToParams()
            );

        $this->init();

        $this->addHooks();

        $this->container->boot();

        $this->loaded();
    }

    /**
     * Called called before the Container is instantiated and after default version
     * requirements are checked.
     *
     * Additional bootstrapping or dependency checks should be run at this stage.
     */
    protected function boot(): void
    {
    }

    /**
     * Returns an array of Hooks that should be added by the class.
     *
     * @return array
     */
    protected function configureHooks(): array
    {
        return [];
    }

    /**
     * Called after the Container is instantiated.
     *
     * This is where the package should register its dependencies with the Container.
     */
    public function init(): void
    {
    }

    /**
     * Called after the Container is instantiated and hooks are added.
     *
     * Any additional actions that should be performed after the package is loaded
     * should be performed here.
     */
    public function loaded(): void
    {
    }

    /**
     * Returns the package's configuration instance.
     *
     * @return \PrettyLinks\GroundLevel\Package\Contracts\Configurable
     */
    public function config(): Configurable
    {
        return $this->config;
    }

    /**
     * Retrieves the Container instances for the package.
     *
     * @return \PrettyLinks\GroundLevel\Container\Container
     */
    public function container(): Container
    {
        return $this->container;
    }

    /**
     * Converts configuration properties to container parameters.
     *
     * Each property is to upper snake case.
     *
     * @return array
     */
    protected function configToParams(): array
    {
        $params = [];
        foreach ($this->config->toArray() as $key => $value) {
            $params[strtoupper(Str::toSnakeCase($key))] = $value;
        }
        return $params;
    }

    /**
     * Asserts that the current PHP version meets the package's requirements.
     *
     * @throws RequirementsError When the current PHP version does not meet the package's requirements.
     */
    protected function assertMeetsPHPVersionRequirements(): void
    {
        $requiredVersion = $this->config->getRequiresPHP();
        $currVersion     = phpversion();
        if (! is_null($requiredVersion) && version_compare($currVersion, $requiredVersion, '<')) {
            throw RequirementsError::phpVersion($currVersion, $requiredVersion);
        }
    }

    /**
     * Asserts that the current WP version meets the package's requirements.
     *
     * @throws RequirementsError When the current WP version does not meet the package's requirements.
     */
    protected function assertMeetsWPVersionRequirements(): void
    {
        $requiredVersion = $this->config->getRequiresWP();
        $currVersion     = get_bloginfo('version');
        if (! is_null($requiredVersion) && version_compare($currVersion, $requiredVersion, '<')) {
            throw RequirementsError::wpVersion($currVersion, $requiredVersion);
        }
    }
}
