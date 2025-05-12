<?php declare(strict_types=1);

namespace Generate\Service\File;

use Generate\File\Generation;
use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;

class GenerationFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, array $options = null)
    {
        return new Generation(
            $services->get(\Omeka\File\TempFileFactory::class),
            $services->get('Omeka\Logger'),
            $services->get('Omeka\File\Store'),
            $services->get('Config')['file_store']['local']['base_path'] ?: (OMEKA_PATH . '/files')
        );
    }
}
