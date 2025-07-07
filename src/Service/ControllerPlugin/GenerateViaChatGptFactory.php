<?php declare(strict_types=1);

namespace Generate\Service\ControllerPlugin;

use Generate\Mvc\Controller\Plugin\GenerateViaChatGpt;
use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;

class GenerateViaChatGptFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, array $options = null)
    {
        $settings = $services->get('Omeka\Settings');

        return new GenerateViaChatGpt(
            $services->get('Omeka\ApiManager'),
            $services->get('Common\EasyMeta'),
            $services->get('Omeka\Logger'),
            $settings,
            $services->get('MvcTranslator'),
            $settings->get('generate_chatgpt_api_key')
        );
    }
}
