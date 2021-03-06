<?php namespace TightenCo\Jigsaw;

use Exception;
use TightenCo\Jigsaw\Collection\Collection;
use TightenCo\Jigsaw\Collection\CollectionItem;
use TightenCo\Jigsaw\File\InputFile;
use TightenCo\Jigsaw\IterableObject;
use TightenCo\Jigsaw\IterableObjectWithDefault;
use TightenCo\Jigsaw\PageVariable;

class CollectionDataLoader
{
    private $filesystem;
    private $pathResolver;
    private $handlers;
    private $source;
    private $collectionSettings;

    public function __construct($filesystem, $pathResolver, $handlers = [])
    {
        $this->filesystem = $filesystem;
        $this->pathResolver = $pathResolver;
        $this->handlers = collect($handlers);
    }

    public function load($source, $siteData)
    {
        $this->source = $source;
        $this->pageSettings = $siteData->page;
        $this->collectionSettings = collect($siteData->collections);

        return $this->collectionSettings->map(function ($collectionSettings, $collectionName) {
            $collection = Collection::withSettings($collectionSettings, $collectionName);
            $collection->loadItems($this->buildCollection($collection));

            return $collection->updateItems($collection->map(function($item) {
                return $this->addCollectionItemContent($item);
            }));
        })->all();
    }

    private function buildCollection($collection)
    {
        return collect($this->filesystem->allFiles("{$this->source}/_{$collection->name}"))
            ->reject(function ($file) {
                return starts_with($file->getFilename(), '_');
            })->map(function ($file) {
                return new InputFile($file, $this->source);
            })->map(function ($inputFile) use ($collection) {
                return $this->buildCollectionItem($inputFile, $collection);
            });
    }

    private function buildCollectionItem($file, $collection)
    {
        $data = $this->pageSettings
            ->merge(['section' => 'content'])
            ->merge($collection->settings)
            ->merge($this->getHandler($file)->getItemVariables($file));
        $data->put('_meta', new IterableObject($this->getMetaData($file, $collection, $data)));
        $path = $this->getPath($data);
        $data->_meta->put('path', $path)->put('url', $this->buildUrls($path));

        return CollectionItem::build($collection, $data);
    }

    private function addCollectionItemContent($item)
    {
        $file = collect($this->filesystem->getFile($item->getSource(), $item->getFilename(), $item->getExtension()))->first();

        if ($file) {
            $item->setContent($this->getHandler($file)->getItemContent($file));
        }

        return $item;
    }

    private function getHandler($file)
    {
        $handler = $this->handlers->first(function ($handler) use ($file) {
            return $handler->shouldHandle($file);
        });

        if (! $handler) {
            throw new Exception('No matching collection item handler');
        }

        return $handler;
    }

    private function getMetaData($file, $collection, $data)
    {
        $filename = $file->getFilenameWithoutExtension();
        $baseUrl = $data->baseUrl;
        $extension = $file->getFullExtension();
        $collectionName = $collection->name;
        $collection = $collectionName;
        $source = $file->getPath();

        return compact('filename', 'baseUrl', 'extension', 'collection', 'collectionName', 'source');
    }

    private function buildUrls($paths)
    {
        $urls = collect($paths)->map(function($path) {
            return rtrim($this->pageSettings->get('baseUrl'), ' /') . '/' . trim($path, '/');
        });

        return $urls->count() ? new IterableObjectWithDefault($urls) : null;
    }

    private function getPath($data)
    {
        $links = $this->pathResolver->link($data->path, new PageVariable($data));

        return $links->count() ? new IterableObjectWithDefault($links) : null;
    }
}
