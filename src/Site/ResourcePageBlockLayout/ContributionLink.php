<?php declare(strict_types=1);

namespace Generate\Site\ResourcePageBlockLayout;

use Laminas\View\Renderer\PhpRenderer;
use Omeka\Api\Representation\AbstractResourceEntityRepresentation;
use Omeka\Site\ResourcePageBlockLayout\ResourcePageBlockLayoutInterface;

class GenerationLink implements ResourcePageBlockLayoutInterface
{
    public function getLabel() : string
    {
        return 'Generate: Edit link'; // @translate
    }

    public function getCompatibleResourceNames() : array
    {
        return [
            'items',
            // 'media',
        ];
    }

    public function render(PhpRenderer $view, AbstractResourceEntityRepresentation $resource) : string
    {
        return $view->partial('common/resource-page-block-layout/generation-link', [
            'resource' => $resource,
        ]);
    }
}
