<?php declare(strict_types=1);

namespace AiGenerator\Service\ViewHelper;

use AiGenerator\View\Helper\AiRecordSearchFilters;
use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;

class AiRecordSearchFiltersFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, array $options = null)
    {
        return new AiRecordSearchFilters(
            $services->get('Omeka\ApiManager')
        );
    }
}
