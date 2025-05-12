<?php declare(strict_types=1);

namespace Generate\Service\ViewHelper;

use Generate\View\Helper\GenerationLink;
use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;

class GenerationLinkFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, array $options = null)
    {
        $checkToken = $services->get('ControllerPluginManager')->get('checkToken');
        return new GenerationLInk(
            $checkToken
        );
    }
}
