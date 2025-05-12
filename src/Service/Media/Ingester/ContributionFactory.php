<?php declare(strict_types=1);

namespace Generate\Service\Media\Ingester;

use Generate\Media\Ingester\Generation;
use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;

class GenerationFactory implements FactoryInterface
{
    /**
     * Create the Generation media ingester service.
     *
     * @return Generation
     */
    public function __invoke(ContainerInterface $services, $requestedName, array $options = null)
    {
        return new Generation(
            $services->get(\Generate\File\Generation::class)
        );
    }
}
