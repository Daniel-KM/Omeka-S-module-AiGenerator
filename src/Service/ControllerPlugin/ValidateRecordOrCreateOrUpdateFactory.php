<?php declare(strict_types=1);

namespace Generate\Service\ControllerPlugin;

use Generate\Mvc\Controller\Plugin\ValidateRecordOrCreateOrUpdate;
use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;

class ValidateRecordOrCreateOrUpdateFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, array $options = null)
    {
        $plugins = $services->get('ControllerPluginManager');

        return new ValidateRecordOrCreateOrUpdate(
            $services->get('Omeka\Acl'),
            $services->get('Omeka\ApiManager'),
            $plugins->get('api'),
            $services->get('Omeka\AuthenticationService'),
            $services->get('Omeka\EntityManager'),
            $services->get('Omeka\Logger'),
            $plugins->get('messenger')
        );
    }
}
