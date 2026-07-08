<?php

declare(strict_types=1);

namespace PrettyLinks\GroundLevel\Database\Concerns;

use PrettyLinks\GroundLevel\Database\Models\Relationship;
use PrettyLinks\GroundLevel\QueryBuilder\Query;

trait HasMetadata
{
    use HasRelationships;

    /**
     * Returns the name of the metadata class for the current class.
     *
     * @return string The name of the metadata class.
     */
    protected function getMetaClass(): string
    {
        return static::class . 'Meta';
    }

    /**
     * Retrieves a Relationship model for the models's meta data.
     *
     * @return \PrettyLinks\GroundLevel\Database\Models\Relationship
     */
    public function metas(): Relationship
    {
        return $this->hasMany($this->getMetaClass());
    }

    /**
     * Retrieves the metas associated with the instance.
     *
     * @param  string|null $key The key of the meta to retrieve. If null, all metas are returned.
     * @return array An array of metas.
     */
    public function getMetas(?string $key = null): array
    {
        if (empty($key)) {
            return $this->metas()->select();
        }

        return $this
            ->metas()
            ->select(
                function (Query $query) use ($key): void {
                    $query->where('meta_key', $key);
                }
            );
    }

    /**
     * Retrieves the meta values associated with the specified key.
     *
     * @param  string|null $key The key to filter the meta values.
     *                         If null, all meta values are returned.
     * @return array The array of meta values.
     */
    public function getMetaValues(?string $key = null): array
    {
        $values = [];
        foreach ($this->getMetas($key) as $meta) {
            $values[] = $meta->getAttribute('meta_value');
        }
        return $values;
    }
}
