<?php declare(strict_types=1);

namespace Generate\Service\ViewHelper;

use Generate\View\Helper\GeneratedResourceSearchFilters;
use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;

class GeneratedResourceSearchFiltersFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, array $options = null)
    {
        return new GeneratedResourceSearchFilters(
            $services->get('Omeka\ApiManager')
        );
    }
}
