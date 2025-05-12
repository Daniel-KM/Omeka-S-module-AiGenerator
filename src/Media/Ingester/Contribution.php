<?php declare(strict_types=1);

namespace Generate\Media\Ingester;

use Generate\File\Generation as FileGeneration;
use Laminas\View\Renderer\PhpRenderer;
use Omeka\Api\Request;
use Omeka\Entity\Media;
use Omeka\Media\Ingester\IngesterInterface;
use Omeka\Stdlib\ErrorStore;

class Generation implements IngesterInterface
{
    /**
     * @var \Generate\File\Generation
     */
    protected $fileGeneration;

    public function __construct(FileGeneration $fileGeneration)
    {
        $this->fileGeneration = $fileGeneration;
    }

    public function getLabel()
    {
        return 'Generation'; // @translate
    }

    public function getRenderer()
    {
        return 'file';
    }

    /**
     * Ingest from a generation stored in "/generation".
     *
     * {@inheritDoc}
     */
    public function ingest(Media $media, Request $request, ErrorStore $errorStore): void
    {
        $data = $request->getContent();
        if (!isset($data['store'])) {
            $errorStore->addError('error', 'The store for file is not set.'); // @translate
            return;
        }

        $sourceName = $data['o:source'] ?? null;

        $tempFile = $this->fileGeneration->toTempFile($data['store'], $sourceName, $errorStore);
        if (!$tempFile) {
            return;
        }

        // Keep standard ingester name to simplify management: this is only an
        // internal intermediate temporary ingester.
        $media->setIngester('upload');

        if (!array_key_exists('o:source', $data)) {
            $media->setSource($tempFile->getSourceName());
        }

        $storeOriginal = true;
        $storeThumbnails = true;
        // Keep temp files to avoid losses when generation is validated.
        // TODO The file will be removed later (after hydration: see Module).
        $deleteTempFile = false;
        $hydrateFileMetadataOnStoreOriginalFalse = true;
        $tempFile->mediaIngestFile(
            $media,
            $request,
            $errorStore,
            $storeOriginal,
            $storeThumbnails,
            $deleteTempFile,
            $hydrateFileMetadataOnStoreOriginalFalse
        );
    }

    public function form(PhpRenderer $view, array $options = [])
    {
        return $view->translate('Used only for internal generation process.'); // @translate
    }
}
