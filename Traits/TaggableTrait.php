<?php

namespace Modules\Tag\Traits;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Support\Arr;
use Modules\Tag\Entities\Tag;

trait TaggableTrait
{
  protected static $tagsModel = Tag::class;

  public static function bootTaggableTrait()
  {
    //Listen event after create model
    static::createdWithBindings(function ($model) {
      $model->syncTags($model->getEventBindings('createdWithBindings'));
    });
    //Listen event after update model
    static::updatedWithBindings(function ($model) {
      $model->syncTags($model->getEventBindings('updatedWithBindings'));
    });
  }

  public function syncTags($params)
  {
    $this->setTags($params['data']['tags'] ?? []);
  }

  public static function getTagsModel(): string
  {
    return static::$tagsModel;
  }

  public static function setTagsModel(string $model)
  {
    static::$tagsModel = $model;
  }

  public function scopeWhereTag(Builder $query, $tags, string $type = 'slug'): Builder
  {
    $tags = is_array($tags) ? $tags : explode(' ', trim($tags));

    $query->whereIn('id', function ($q) use ($tags, $type) {
      $q->from('tag__tagged')
        ->select('tag__tagged.taggable_id')
        ->leftJoin('tag__tag_translations', 'tag__tag_translations.tag_id', '=', 'tag__tagged.tag_id')
        ->where(function ($query) use ($tags, $type) {
          foreach ($tags as $tag) {
            $query->orWhere("tag__tag_translations.$type", 'LIKE', "%$tag%");
          }
        });
    });

    return $query;
  }

  public function scopeWithTag(Builder $query, $tags, string $type = 'slug'): Builder
  {
    $tags = Arr::wrap($tags);

    $query->with('translations');

    return $query->whereHas('tags', function (Builder $query) use ($type, $tags) {
      $query->whereHas('translations', function (Builder $query) use ($type, $tags) {
        $query->whereIn($type, $tags);
      });
    });
  }

  public function tags(): MorphToMany
  {
    return $this->morphToMany(static::$tagsModel, 'taggable', 'tag__tagged', 'taggable_id', 'tag_id');
  }

  public static function createTagsModel(): Model
  {
    return new static::$tagsModel;
  }

  public static function allTags(): Builder
  {
    $instance = new static;

    return self::createTagsModel()->with('translations');
  }

  public function setTags($tags, string $type = 'slug'): bool
  {
    if (empty($tags)) {
      $tags = [];
    }

    // Get the current entity tags
    $entityTags = $this->tags()->get()->pluck($type)->all();

    // Prepare the tags to be added and removed
    $tagsToAdd = array_diff($tags, $entityTags);
    $tagsToDel = array_diff($entityTags, $tags);

    // Detach the tags
    if (count($tagsToDel) > 0) {
      $this->untag($tagsToDel);
    }

    // Attach the tags
    if (count($tagsToAdd) > 0) {
      $this->tag($tagsToAdd);
    }

    return true;
  }

  public function tag($tags): bool
  {
    foreach (Arr::wrap($tags) as $tag) {
      $this->addTag($tag);
    }

    return true;
  }

  public function addTag(string $name)
  {
    $tag = self::allTags()
      ->whereHas('translations', function (Builder $q) use ($name) {
        $q->where('slug', $this->generateTagSlug($name));
      })->first();

    if ($tag === null) {
      $tag = new Tag([
        'namespace' => $this->getEntityClassName(),
        app()->getLocale() => [
          'slug' => $this->generateTagSlug($name),
          'name' => $name,
        ],
      ]);
    }
    if ($tag->exists === false) {
      $tag->save();
    }

    if ($this->tags()->get()->contains($tag->id) === false) {
      $this->tags()->attach($tag);
    }
  }

  public function untag($tags = null): bool
  {
    $tags = $tags ?: $this->tags()->get()->pluck('name')->all();

    foreach ($tags as $tag) {
      $this->removeTag($tag);
    }

    return true;
  }

  public function removeTag(string $name)
  {
    $tag = self::allTags()
      ->whereHas('translations', function (Builder $q) use ($name) {
        $q->where('slug', $this->generateTagSlug($name));
      })->first();

    if ($tag) {
      $this->tags()->detach($tag);
    }
  }

  protected function getEntityClassName(): string
  {
    if (isset(static::$entityNamespace)) {
      return static::$entityNamespace;
    }

    return $this->tags()->getMorphClass();
  }

  public function generateTagSlug($name, $separator = '-'): string
  {
    // Convert all dashes/underscores into separator
    $flip = $separator == '-' ? '_' : '-';

    $name = preg_replace('![' . preg_quote($flip, '!') . ']+!u', $separator, $name);

    // Replace @ with the word 'at'
    $name = str_replace('@', $separator . 'at' . $separator, $name);

    // Remove all characters that are not the separator, letters, numbers, or whitespace.
    $name = preg_replace('![^' . preg_quote($separator, '!') . '\pL\pN\s]+!u', '', mb_strtolower($name));

    // Replace all separator characters and whitespace by a single separator
    $name = preg_replace('![' . preg_quote($separator, '!') . '\s]+!u', $separator, $name);

    return trim($name, $separator);
  }

  public function getNameTags()
  {
    return $this->tags->pluck('name')->toArray();
  }
}
