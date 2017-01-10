<?php

namespace Liugj\Xunsearch\Engines;

use Laravel\Scout\Builder;
use Laravel\Scout\Engines\Engine as Engine;
use Illuminate\Database\Eloquent\Collection;
use Liugj\Xunsearch\XunsearchClient as Xunsearch;

class XunsearchEngine extends Engine
{
    /**
     * The Xunsearch client.
     *
     * @var Liugj\Xunsearch\XunSearchClient
     */
    protected $xunsearch;
    /**
     * Create a new engine instance.
     *
     * @return void
     */
    public function __construct(Xunsearch $xunsearch)
    {
        $this->xunsearch = $xunsearch;
    }
    /**
     * Update the given model in the index.
     *
     * @param  \Illuminate\Database\Eloquent\Collection  $models
     * @throws \AlgoliaSearch\AlgoliaException
     * @return void
     */
    public function update($models)
    {
        $index = $this->xunsearch->initIndex($models->first()->searchableAs());

        $models->map(function ($model) use ($index) {
            $array = $model->toSearchableArray();

            if (empty($array)) {
                return;
            }

            $doc = new \XSDocument();
            $doc->setFields(array_merge(['id' => $model->getKey()], $array));
            $index->update($doc);
        });

        $index->flushIndex();
    }

    /**
     * Remove the given model from the index.
     *
     * @param  \Illuminate\Database\Eloquent\Collection  $models
     * @return void
     */
    public function delete($models)
    {
        $index = $this->xunsearch->initIndex($models->first()->searchableAs());

        $models->map(function ($model) use ($index) {
                $index->del($model->getKey());
        });

        $index->flushIndex();
    }

    /**
     * Perform the given search on the engine.
     *
     * @param  \Laravel\Scout\Builder  $builder
     * @return mixed
     */
    public function search(Builder $builder)
    {
        return $this->performSearch($builder, array_filter([
            'numericFilters' => $this->filters($builder),
            'hitsPerPage' => $builder->limit,
        ]));
    }

    /**
     * Perform the given search on the engine.
     *
     * @param  \Laravel\Scout\Builder  $builder
     * @param  int  $perPage
     * @param  int  $page
     * @return mixed
     */
    public function paginate(Builder $builder, $perPage, $page)
    {
        return $this->performSearch($builder, [
            'numericFilters' => $this->filters($builder),
            'hitsPerPage' => $perPage,
            'page' => $page - 1,
        ]);
    }

    /**
     * Perform the given search on the engine.
     *
     * @param  \Laravel\Scout\Builder  $builder
     * @param  array  $options
     * @return mixed
     */
    protected function performSearch(Builder $builder, array $options = [])
    {
        $search = $this->xunsearch->initSearch(
            $builder->index ?: $builder->model->searchableAs()
        );

        if ($builder->callback) {
            return call_user_func(
                $builder->callback,
                $search,
                $builder->query,
                $options
            );
        }

        $search->setFuzzy()->setQuery($builder->query);
        collect($builder->wheres)->map(function ($value, $key) use ($search) {
            $search->addRange($key, $value, $value);
        });

        $offset = 0;
        $perPage = $options['hitsPerPage'];
        if (!empty($options['page'])) {
            $offset = $perPage * $options['page'];
        }

        return $search->setLimit($perPage, $offset)->search();
    }

    /**
     * Get the filter array for the query.
     *
     * @param  \Laravel\Scout\Builder  $builder
     * @return array
     */
    protected function filters(Builder $builder)
    {
        return collect($builder->wheres)->map(function ($value, $key) {
            return $key.'='.$value;
        })->values()->all();
    }

    /**
     * Pluck and return the primary keys of the given results.
     *
     * @param  mixed  $results
     * @return \Illuminate\Support\Collection
     */
    public function mapIds($results)
    {
        return collect($results['hits'])->pluck('id')->values();
    }

    /**
     * Map the given results to instances of the given model.
     *
     * @param  mixed  $results
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function map($results, $model)
    {
        if (count($results['hits']) === 0) {
            return Collection::make();
        }

        $keys = collect($results['hits'])
                        ->pluck($model->getKeyName())->values()->all();

        $models = $model->whereIn(
            $model->getQualifiedKeyName(),
            $keys
        )->get()->keyBy($model->getKeyName());

        return Collection::make($results['hits'])->map(function ($hit) use ($model, $models) {
            $key = $hit[$model->getKeyName()];

            if (isset($models[$key])) {
                return $models[$key];
            }
        })->filter();
    }

    /**
     * Get the total count from a raw result returned by the engine.
     *
     * @param  mixed  $results
     * @return int
     */
    public function getTotalCount($results)
    {
        return $results['nbHits'];
    }
}
