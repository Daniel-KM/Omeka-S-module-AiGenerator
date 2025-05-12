<?php declare(strict_types=1);

namespace Generate\Service\Controller;

use Generate\Controller\Admin\GenerationController;
use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;

class AdminGenerationControllerFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, array $options = null)
    {
        return new GenerationController(
            $services->get('Omeka\EntityManager')
        );
    }
}
