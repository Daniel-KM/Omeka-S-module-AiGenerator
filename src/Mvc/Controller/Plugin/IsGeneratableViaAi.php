<?php

declare(strict_types=1);

namespace AiGenerator\Mvc\Controller\Plugin;

use Laminas\Mvc\Controller\Plugin\AbstractPlugin;
use Omeka\Api\Representation\AbstractResourceEntityRepresentation;

class IsGeneratableViaAi extends AbstractPlugin
{
    /**
     * @var string
     */
    protected $defaultDerivative = 'large';

    public function __construct(
        string $defaultDerivative = 'large'
    ) {
        $this->defaultDerivative = $defaultDerivative;
    }

    /**
     * Check if a resource can have metadata generated via AI.
     *
     * A resource can be generated via AI if it has a resource template and at
     * least one image.
     */
    public function __invoke(
        ?AbstractResourceEntityRepresentation $resource,
        array $options = []
    ): bool {
        if (!$resource) {
            return false;
        }

        $derivative = ($options['derivative'] ?? $this->defaultDerivative) ?: $this->defaultDerivative;
        $useOriginal = $derivative === 'original';

        if ($resource instanceof \Omeka\Api\Representation\ItemRepresentation) {
            foreach ($resource ->media() as $media) {
                if ($media->renderer() === 'file'
                    && (
                        ($useOriginal && $media->hasOriginal())
                        || (!$useOriginal && $media->hasThumbnails())
                    )
                    && strtok((string) $media->mediaType(), '/') === 'image'
                ) {
                    return true;
                }
            }
        } elseif ($resource instanceof \Omeka\Api\Representation\MediaRepresentation) {
            if ($resource->renderer() === 'file'
                && (
                    ($useOriginal && $resource->hasOriginal())
                    || (!$useOriginal && $resource->hasThumbnails())
                )
                && strtok((string) $resource->mediaType(), '/') === 'image'
            ) {
                return true;
            }
        }

        return false;
    }
}
