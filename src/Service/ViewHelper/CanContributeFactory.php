<?php declare(strict_types=1);

namespace Generate\Service\ViewHelper;

use Generate\View\Helper\CanGenerate;
use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;

class CanGenerateFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, array $options = null)
    {
        $plugins = $services->get('ControllerPluginManager');
        return new CanGenerate(
            $plugins->has('isCasUser') ? $plugins->get('isCasUser') : null,
            $plugins->has('isLdapUser') ? $plugins->get('isLdapUser') : null,
            $plugins->has('isSsoUser') ? $plugins->get('isSsoUser') : null,
        );
    }
}
