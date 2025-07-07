<?php declare(strict_types=1);

namespace Generate\Service\ControllerPlugin;

use Generate\Mvc\Controller\Plugin\GenerateViaOpenAi;
use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;

class GenerateViaOpenAiFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, array $options = null)
    {
        $plugins = $services->get('ControllerPluginManager');
        $settings = $services->get('Omeka\Settings');

        return new GenerateViaOpenAi(
            $services->get('Omeka\ApiManager'),
            $services->get('Omeka\AuthenticationService'),
            $services->get('Common\EasyMeta'),
            $services->get('Omeka\Logger'),
            $plugins->get('messenger'),
            $settings,
            $services->get('MvcTranslator'),
            $settings->get('generate_api_key_openai')
        );
    }
}
