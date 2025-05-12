<?php declare(strict_types=1);

namespace Generate\Service\ViewHelper;

use Generate\View\Helper\GenerationSearchFilters;
use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;

class GenerationSearchFiltersFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, array $options = null)
    {
        return new GenerationSearchFilters(
            $services->get('Omeka\ApiManager')
        );
    }
}
