<?php declare(strict_types=1);

namespace Generate\Service\Controller;

use Generate\Controller\Site\GenerationController;
use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;

class SiteGenerationControllerFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, array $options = null)
    {
        $config = $services->get('Config');
        return new GenerationController(
            $services->get('Omeka\EntityManager'),
            $services->get(\Omeka\File\TempFileFactory::class),
            $services->get(\Omeka\File\Uploader::class),
            $config['file_store']['local']['base_path'] ?: (OMEKA_PATH . '/files'),
            $config
        );
    }
}
