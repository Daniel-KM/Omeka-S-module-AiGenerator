<?php declare(strict_types=1);

namespace AiGenerator\Service\ControllerPlugin;

use AiGenerator\Mvc\Controller\Plugin\GenerateViaOpenAi;
use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;

class GenerateViaOpenAiFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, array $options = null)
    {
        $plugins = $services->get('ControllerPluginManager');
        $settings = $services->get('Omeka\Settings');

        return new GenerateViaOpenAi(
            $services->get('Omeka\Acl'),
            $services->get('Omeka\ApiManager'),
            $services->get('Omeka\AuthenticationService'),
            $services->get('Common\EasyMeta'),
            $services->get('Omeka\Logger'),
            $plugins->get('messenger'),
            $settings,
            $services->get('Omeka\Status'),
            $services->get('MvcTranslator'),
            $plugins->get('validateRecordOrCreateOrUpdate'),
            $settings->get('aigenerator_openai_api_key'),
            $settings->get('aigenerator_openai_organization'),
            $settings->get('aigenerator_openai_project')
        );
    }
}
